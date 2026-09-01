<?php

namespace Ekumanov\LinkPreview\Job;

use Carbon\Carbon;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\Fetch\FailurePolicy;
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
 * Bounded runtime: $timeout = 30 covers up to 10 s of fetch + parse + persist
 * with slack. Worker kills it if it overruns.
 */
class FetchPreviewJob extends AbstractJob
{
    /** @var int Disable retry-on-throw — see class docblock. */
    public int $tries = 1;

    /** @var int Per-job wall clock cap (sec). */
    public int $timeout = 30;

    public function __construct(public readonly int $previewId) {}

    public function handle(
        SafeHttpClient $client,
        OpenGraphParser $ogParser,
        HtmlFallbackParser $fbParser,
        SettingsRepository $settings,
        LoggerInterface $log,
        LocalDiscussionResolver $localResolver,
    ): void {
        $preview = Preview::find($this->previewId);
        if ($preview === null) {
            // Row was deleted between enqueue and run. Nothing to do.
            return;
        }

        if ($preview->retrieved_at !== null) {
            $age = Carbon::parse($preview->retrieved_at)->diffInSeconds(Carbon::now());
            if ($age < $settings->ttlSeconds()) {
                return; // already fresh
            }
        }

        // Media we could never build a card from (audio, video, images,
        // archives). Extraction skips these, but rows created before the list
        // grew still exist and must not be re-fetched — downloading a 2 MB mp3
        // to find no HTML in it is the most expensive way to learn nothing.
        if (UrlExtractor::isNonFetchableMedia($preview->url)) {
            $preview->retrieved_at = Carbon::now();
            $preview->http_status = 0;
            $preview->error = 'media_url: no HTML to parse';
            $preview->save();
            return;
        }

        // Self-link short-circuit — same as ScanPostUrls, but also catches
        // backfill / sweep dispatches where the listener never ran. Anything
        // on our own host NEVER goes to HTTP: succeed locally, or record a
        // permanent failure. Fetching our own public hostname from our own
        // server is what Cloudflare answers with a challenge.
        if ($localResolver->isSelfHost($preview->url)) {
            $local = $localResolver->resolve($preview->url);
            $preview->retrieved_at = Carbon::now();
            if ($local !== null) {
                $preview->http_status = 200;
                $preview->opengraph = $local;
                $preview->final_url = $preview->url;
                $preview->error = null;
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
            }
            $preview->save();
            return;
        }

        try {
            $result = $client->get($preview->url);
        } catch (Throwable $e) {
            // Unexpected internal failure (not an HTTP error — SafeHttpClient
            // returns those as ['ok' => false]). Log and mark errored so the
            // row doesn't sit pending forever.
            $log->warning('link-preview fetch threw', [
                'preview_id' => $preview->id, 'url' => $preview->url, 'err' => $e->getMessage(),
            ]);
            $preview->retrieved_at = Carbon::now();
            $preview->error = substr('exception: '.$e->getMessage(), 0, 255);
            $preview->fetch_attempts = (int) $preview->fetch_attempts + 1;
            $preview->save();
            return;
        }

        $preview->retrieved_at = Carbon::now();

        if (! $result['ok']) {
            $preview->http_status = 0;
            $preview->error = substr($result['reason'].': '.$result['detail'], 0, 255);
            $preview->fetch_attempts = (int) $preview->fetch_attempts + 1;
            $preview->save();
            return;
        }

        $preview->http_status = $result['status'];
        $preview->final_url = $result['finalUrl'];

        // A non-2xx is a FAILED fetch, not a page with no metadata. Recording
        // it as an error is what lets link-preview:retry-failed find it later,
        // and what keeps a WAF block distinguishable from a site that simply
        // has no OpenGraph tags.
        if ($result['status'] < 200 || $result['status'] >= 300) {
            $preview->error = FailurePolicy::httpError($result['status']);
            $preview->fetch_attempts = (int) $preview->fetch_attempts + 1;
            $preview->save();
            return;
        }

        $preview->error = null;
        $preview->fetch_attempts = 0;

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
            if ($fb['icons'] !== []) {
                $preview->icons = $fb['icons'];
            }
        }

        $preview->save();
    }
}
