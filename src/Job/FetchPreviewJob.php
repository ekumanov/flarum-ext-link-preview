<?php

namespace Ekumanov\LinkPreview\Job;

use Carbon\Carbon;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\PreviewChangeNotifier;
use Ekumanov\LinkPreview\Fetch\FailurePolicy;
use Ekumanov\LinkPreview\Fetch\FaviconProbe;
use Ekumanov\LinkPreview\Fetch\IconResolver;
use Ekumanov\LinkPreview\Http\SafeHttpClient;
use Ekumanov\LinkPreview\Listener\UrlExtractor;
use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\LinkPreview\Parser\HtmlFallbackParser;
use Ekumanov\LinkPreview\Parser\OpenGraphParser;
use Ekumanov\LinkPreview\Settings\SettingsRepository;
use Flarum\Queue\AbstractJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background worker — fetch one URL, parse OG/fallback metadata, persist.
 *
 * Idempotent: if the preview row was already fetched within the TTL window, the
 * job is a no-op (handles retry storms and duplicate scheduler dispatches).
 *
 * Single-attempt: $tries = 1 disables Laravel's default retry-on-throw. A
 * fetch that genuinely fails (timeout, 4xx, etc.) is recorded as a failed row
 * — we don't want a single bad URL to hammer the queue with retries. A real
 * transport panic (DB unreachable, etc.) just dies silently and the scheduler
 * sweep picks it up later.
 *
 * Bounded runtime: $timeout = 30 is a hard wall — when a job overruns it,
 * Laravel's worker does not merely abandon the job, it kills the whole worker
 * process. The HTTP work therefore runs against its own, shorter deadline (see
 * the *_SECONDS constants): the page fetch, every fallback identity and every
 * redirect hop share one budget, icon work only starts while there is time
 * left for it, and each request's timeout is capped to what remains. The gap
 * to 30 s is for what no deadline can interrupt — a stalled DNS lookup
 * (dns_get_record() has no timeout of its own) and the DB writes.
 *
 * A failed RE-fetch never takes a working card away. A row that already holds
 * a good preview keeps it — status, error and metadata untouched — and the
 * failure goes to `refresh_error`, which the post relationship does not filter
 * on. Otherwise one flaky TTL refresh (or a --force-refresh against a host that
 * blocks us today) would silently strip the card from every post linking it.
 */
class FetchPreviewJob extends AbstractJob
{
    /** @var int Disable retry-on-throw — see class docblock. */
    public int $tries = 1;

    /** @var int Per-job wall clock cap (sec). Overrunning it kills the worker. */
    public int $timeout = 30;

    /** The page fetch — every identity and redirect — must be done by this. */
    private const PAGE_BUDGET_SECONDS = 15.0;

    /**
     * Icon measuring/probing is only started before this point. It is
     * decoration: a card with no site mark is fine, a dead worker is not.
     */
    private const ICON_START_CUTOFF_SECONDS = 12.0;

    /** All HTTP work, icons included, must be done by this. */
    private const WORK_BUDGET_SECONDS = 20.0;

    public function __construct(public readonly int $previewId) {}

    public function handle(
        SafeHttpClient $client,
        OpenGraphParser $ogParser,
        HtmlFallbackParser $fbParser,
        SettingsRepository $settings,
        LoggerInterface $log,
        LocalDiscussionResolver $localResolver,
        FaviconProbe $faviconProbe,
        IconResolver $iconResolver,
        PreviewChangeNotifier $notifier,
    ): void {
        $started = microtime(true);

        $preview = Preview::find($this->previewId);
        if ($preview === null) {
            // Row was deleted between enqueue and run. Nothing to do.
            return;
        }

        // A row the sweep gave up on is not "fresh", however recent its
        // retrieved_at: the give-up is a guess that the job was lost, and this
        // run is the proof that it was merely late (a long queue backlog, a
        // worker that was down). Let it do the work it was queued for.
        $givenUp = $preview->error !== null && FailurePolicy::reasonOf($preview->error) === 'stuck';

        if ($preview->retrieved_at !== null && ! $givenUp) {
            $age = Carbon::parse($preview->retrieved_at)->diffInSeconds(Carbon::now());
            if ($age < $settings->ttlSeconds()) {
                return; // already fresh
            }
        }

        // How the row rendered before this run, so we can tell whether the
        // outcome changed any page — see PreviewsChanged.
        $wasPending = self::isPending($preview);
        $wasCard = self::hasCard($preview);

        // Whatever happens below, this job ran to an outcome: the sweep's
        // "dispatched but never finished" count starts over.
        $preview->sweep_dispatches = 0;

        $this->fetchInto(
            $preview, $started, $client, $ogParser, $fbParser, $settings, $log,
            $localResolver, $faviconProbe, $iconResolver,
        );

        // A skeleton that turned into a card (or into nothing), or a card that
        // appeared or disappeared: the pages showing it are now stale.
        if ($wasPending || $wasCard !== self::hasCard($preview)) {
            $notifier->previewsChanged([(int) $preview->id]);
        }
    }

    private function fetchInto(
        Preview $preview,
        float $started,
        SafeHttpClient $client,
        OpenGraphParser $ogParser,
        HtmlFallbackParser $fbParser,
        SettingsRepository $settings,
        LoggerInterface $log,
        LocalDiscussionResolver $localResolver,
        FaviconProbe $faviconProbe,
        IconResolver $iconResolver,
    ): void {
        // Media we could never build a card from (audio, video, images,
        // archives). Extraction skips these, but rows created before the list
        // grew still exist and must not be re-fetched — downloading a 2 MB mp3
        // to find no HTML in it is the most expensive way to learn nothing.
        if (UrlExtractor::isNonFetchableMedia($preview->url)) {
            $preview->retrieved_at = Carbon::now();
            $preview->http_status = 0;
            $preview->error = 'media_url: no HTML to parse';
            $preview->fetch_attempts = FailurePolicy::SETTLED_ATTEMPTS;
            $preview->save();
            return;
        }

        // Self-link short-circuit — same as ScanPostUrls, but also catches
        // backfill / sweep dispatches where the listener never ran. Anything
        // on our own host NEVER goes to HTTP: succeed locally, or record a
        // permanent failure. Fetching our own public hostname from our own
        // server is what Cloudflare answers with a challenge.
        //
        // Authoritative in both directions, unlike a remote re-fetch: a
        // discussion that has since gone private or been deleted MUST lose its
        // card, so "keep the old card on failure" does not apply here.
        if ($localResolver->isSelfHost($preview->url)) {
            $local = $localResolver->resolve($preview->url);
            $preview->retrieved_at = Carbon::now();
            if ($local !== null) {
                $preview->http_status = 200;
                $preview->opengraph = $local;
                $preview->final_url = $preview->url;
                $preview->error = null;
                $preview->refresh_error = null;
                // Local data is authoritative and image-less; clear any residue
                // from a prior HTTP fetch (e.g. a re-fetched legacy row) so a
                // stale fallback/icon thumbnail can't leak through firstImage().
                $preview->fallback = null;
                $preview->icons = null;
                $preview->api_resource = null;
                $preview->mime = null;
                $preview->exif = null;
                $preview->fetch_attempts = 0;
            } else {
                $preview->http_status = 0;
                $preview->error = 'self_link_not_viewable';
                // Settled: stamped so the retry pass can skip it in SQL.
                $preview->fetch_attempts = FailurePolicy::SETTLED_ATTEMPTS;
            }
            $preview->save();
            return;
        }

        $hadCard = self::hasCard($preview);

        try {
            $result = $client->get($preview->url, $started + self::PAGE_BUDGET_SECONDS);
        } catch (Throwable $e) {
            // Unexpected internal failure (not an HTTP error — SafeHttpClient
            // returns those as ['ok' => false]). Log and mark errored so the
            // row doesn't sit pending forever.
            $log->warning('link-preview fetch threw', [
                'preview_id' => $preview->id, 'url' => $preview->url, 'err' => $e->getMessage(),
            ]);
            $this->recordFailure($preview, 'exception: '.$e->getMessage(), null, $hadCard, $log);
            return;
        }

        if (! $result['ok']) {
            $this->recordFailure($preview, $result['reason'].': '.$result['detail'], 0, $hadCard, $log);
            return;
        }

        // A non-2xx is a FAILED fetch, not a page with no metadata. Recording
        // it as an error is what lets link-preview:retry-failed find it later,
        // and what keeps a WAF block distinguishable from a site that simply
        // has no OpenGraph tags.
        if ($result['status'] < 200 || $result['status'] >= 300) {
            $this->recordFailure($preview, FailurePolicy::httpError($result['status']), $result['status'], $hadCard, $log);
            return;
        }

        $preview->retrieved_at = Carbon::now();
        $preview->http_status = $result['status'];
        $preview->final_url = $result['finalUrl'];
        $preview->error = null;
        $preview->refresh_error = null;
        $preview->fetch_attempts = 0;

        // Icons are decoration. Once the page fetch has eaten most of the
        // budget, skip them for this run — the row keeps whatever icons it
        // had, and the next fetch (or link-preview:backfill-icons) catches up.
        $iconsAllowed = microtime(true) - $started < self::ICON_START_CUTOFF_SECONDS;
        $iconDeadline = $started + self::WORK_BUDGET_SECONDS;

        // Only parse HTML bodies — anything else (PDF, JSON, image MIME, etc.)
        // we record the status but leave metadata empty. The display layer
        // skips rows without a title, so they simply don't produce a card.
        $contentType = strtolower($result['contentType']);
        if (str_contains($contentType, 'html') || str_contains($contentType, 'xml')) {
            $og = $ogParser->parse($result['body']);
            $fb = $fbParser->parse($result['body']);

            if ($og !== null) {
                $preview->opengraph = $og;
            }
            if ($fb['fallback'] !== null) {
                $preview->fallback = $fb['fallback'];
            }
            if ($fb['icons'] !== [] && ! $settings->showFavicons()) {
                // Site mark off: store what the page declares, spend nothing.
                $preview->icons = $fb['icons'];
            } elseif ($fb['icons'] !== [] && $iconsAllowed) {
                // Measure what the winning icon actually weighs before any
                // reader is asked to download it — declared metadata does not
                // predict bytes.
                $preview->icons = $this->validateIcons($iconResolver, $fb['icons'], $preview, $settings, $log, $iconDeadline);
            } elseif ($fb['icons'] !== []) {
                // Out of time. A row that already has icons keeps them — they
                // carry the measurements and our stored copies, which a raw
                // re-parse would throw away. A new row gets the parsed list
                // unmeasured, as with the site mark off; the picker treats it
                // as not yet weighed and the next fetch measures it.
                if ($preview->icons === null || $preview->icons === []) {
                    $preview->icons = $fb['icons'];
                }
            } elseif ($preview->icons === null && $settings->iconProbe() && $iconsAllowed) {
                // NULL means "never looked"; an empty array means "looked and
                // found nothing". Writing [] either way is what makes this one
                // probe per row rather than one per fetch attempt — a site with
                // no favicon is not asked again on every TTL expiry. Skipped
                // when out of time, which leaves NULL — "never looked" — so a
                // later fetch or backfill still probes.
                $probed = $this->probeIcon($faviconProbe, $preview, $settings, $log, $iconDeadline);
                $preview->icons = $probed === null ? [] : [$probed];
            }
        }

        $preview->save();
    }

    /**
     * Record a failed remote fetch.
     *
     * A row that already carries a good preview keeps it: the card stays on
     * every post linking the URL, and the failure goes to `refresh_error` (plus
     * the log) instead of `error`/`http_status`, which the post relationship
     * filters on. retrieved_at still advances, so the TTL — not the next edit
     * — decides when we ask again.
     */
    private function recordFailure(Preview $preview, string $error, ?int $httpStatus, bool $hadCard, LoggerInterface $log): void
    {
        $error = substr($error, 0, 255);
        $preview->retrieved_at = Carbon::now();

        if ($hadCard) {
            $preview->refresh_error = $error;
            $log->info('link-preview: re-fetch failed, keeping the existing card', [
                'preview_id' => $preview->id, 'url' => $preview->url, 'err' => $error,
            ]);
        } else {
            if ($httpStatus !== null) {
                $preview->http_status = $httpStatus;
            }
            $preview->error = $error;
            $preview->fetch_attempts = FailurePolicy::attemptsAfterFailure(
                $error,
                $preview->http_status === null ? null : (int) $preview->http_status,
                (int) $preview->fetch_attempts,
            );
        }

        $preview->save();
    }

    /**
     * The row renders as a skeleton — the same test PostResourceFields uses.
     */
    private static function isPending(Preview $preview): bool
    {
        return $preview->retrieved_at === null && $preview->error === null;
    }

    /**
     * The row holds a successful fetch, i.e. the post relationship will load
     * it as a card. Deliberately ignores retrieved_at: a --force-refresh nulls
     * it on rows whose data is perfectly good, and those must count as cards.
     */
    private static function hasCard(Preview $preview): bool
    {
        return $preview->error === null && (int) $preview->http_status === 200;
    }

    /**
     * Best-effort by construction: a probe that throws must not turn a
     * perfectly good preview into a failed one, and must not consume a retry.
     *
     * @return array{href:string,probed:true}|null
     */
    private function probeIcon(
        FaviconProbe $probe,
        Preview $preview,
        SettingsRepository $settings,
        LoggerInterface $log,
        ?float $deadline = null,
    ): ?array {
        try {
            return $probe->probe($preview->final_url ?: $preview->url, $settings->faviconMaxBytes(), $deadline);
        } catch (Throwable $e) {
            $log->debug('link-preview favicon probe failed', [
                'preview_id' => $preview->id, 'url' => $preview->url, 'err' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Best-effort like the probe: if measuring blows up, keep the icons we
     * parsed rather than losing them.
     *
     * @param  list<array<string,mixed>> $icons
     * @return list<array<string,mixed>>
     */
    private function validateIcons(
        IconResolver $resolver,
        array $icons,
        Preview $preview,
        SettingsRepository $settings,
        LoggerInterface $log,
        ?float $deadline = null,
    ): array {
        try {
            return $resolver->validate(
                $icons,
                $preview->final_url ?: $preview->url,
                $settings->faviconMaxBytes(),
                $settings->proxyIcons(),
                $deadline,
            )['icons'];
        } catch (Throwable $e) {
            $log->debug('link-preview icon validation failed', [
                'preview_id' => $preview->id, 'url' => $preview->url, 'err' => $e->getMessage(),
            ]);

            return $icons;
        }
    }
}
