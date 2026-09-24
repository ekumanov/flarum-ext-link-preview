<?php

namespace Ekumanov\LinkPreview\Listener;

use Carbon\Carbon;
use Ekumanov\LinkPreview\Fetch\FailurePolicy;
use Ekumanov\LinkPreview\Http\UrlValidator;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\Job\FetchPreviewJob;
use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\LinkPreview\RateLimit\UrlSubmissionLimiter;
use Ekumanov\LinkPreview\Settings\SettingsRepository;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Queue\SyncQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reacts to Posted and Revised events; scans the rendered body, materialises
 * preview rows + pivot links synchronously (so the row exists by the time the
 * API response comes back and the post serializer can include a pending
 * placeholder), and dispatches a fetch job per new/stale URL.
 *
 * The listener runs INSIDE the post-save HTTP request. Everything it does
 * must be fast: extract URLs, DB INSERTs, queue->push(). The actual fetch is
 * the queue worker's job.
 *
 * On Revised: pivot rows for URLs removed from the post are deleted, so
 * cards disappear from the edited post. The Preview row itself stays (other
 * posts may still link it).
 */
final class ScanPostUrls
{
    public function __construct(
        private readonly UrlExtractor $extractor,
        private readonly UrlSubmissionLimiter $limiter,
        private readonly SettingsRepository $settings,
        private readonly Queue $queue,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $log,
        private readonly LocalDiscussionResolver $localResolver,
    ) {}

    public function handle(Posted|Revised $event): void
    {
        $post = $event->post;
        $actor = $event->actor ?? $post->user;
        if ($actor === null) {
            // No attributable actor (system posts) — skip. Without an actor
            // we can't rate-limit or attribute the fetch.
            return;
        }

        // TODO(v2): per-group permission (`ekumanov-link-preview.useOnOwnPost`).
        // Currently every authenticated post-author triggers scans; the
        // hourly rate limit + max-urls-per-post + SSRF guards bound the
        // worst case. Adding fine-grained group gating requires migrating
        // a default `group_permission` row and is deferred.

        try {
            $html = $post->formatContent();
        } catch (Throwable $e) {
            $this->log->warning('link-preview: formatContent failed', ['post_id' => $post->id, 'err' => $e->getMessage()]);
            return;
        }

        $urls = $this->extractor->extract($html);

        if ($event instanceof Revised) {
            $this->pruneObsoletePivots($post->id, $urls);
        }

        if ($urls === []) {
            return;
        }

        $ttlAgo = Carbon::now()->subSeconds($this->settings->ttlSeconds());

        // One query for every URL's existing row, rather than one per URL.
        $hashes = [];
        foreach ($urls as $url) {
            $hashes[$url] = sha1($url, true);
        }
        $existing = Preview::whereIn('url_hash', array_values($hashes))->get()->keyBy(
            fn (Preview $p) => bin2hex((string) $p->url_hash)
        );

        // The rate limit exists to bound the fetch worker, so it is charged
        // only for what makes work for it: a brand-new row, or a real remote
        // re-fetch of one that has gone stale. An edit that leaves the links
        // alone, a URL someone else already previewed, and a self-link (which
        // never touches HTTP) cost nothing. Charging for those used to burn
        // the allowance on every edit — and a URL over the allowance was then
        // dropped before its pivot row was written, so it never got the card
        // that already existed for it.
        $charged = [];
        foreach ($urls as $url) {
            $preview = $existing->get(bin2hex($hashes[$url]));
            if ($this->localResolver->isSelfHost($url)) {
                continue; // resolved locally, here or in the job — never HTTP
            }
            if ($preview === null || $this->isDue($preview, $ttlAgo)) {
                $charged[] = $url;
            }
        }

        $allowed = $charged === [] ? 0 : $this->limiter->consume($actor, count($charged));
        if ($allowed < count($charged)) {
            $this->log->info('link-preview: user hit hourly URL limit', [
                'user_id' => $actor->id, 'submitted' => count($charged), 'allowed' => $allowed,
            ]);
        }
        $withinAllowance = array_flip(array_slice($charged, 0, $allowed));

        foreach ($urls as $url) {
            $hash = $hashes[$url];
            $preview = $existing->get(bin2hex($hash));
            $isCharged = in_array($url, $charged, true);
            $mayFetch = ! $isCharged || isset($withinAllowance[$url]);

            if ($preview === null) {
                if (! $mayFetch) {
                    // Over the allowance and nothing to show for it: no row,
                    // no pivot, no job — as before.
                    continue;
                }

                $preview = $this->findOrCreate($url, $hash);
                if ($preview === null) {
                    continue;
                }
            }

            // Link the preview to this post. Use insertOrIgnore so repeats are
            // idempotent (e.g. revising the same post twice with the same URL).
            // Written for every URL whose row exists, allowance or not: an
            // existing preview costs the worker nothing to display.
            $this->db->table('ekumanov_link_preview_post')->insertOrIgnore([
                'preview_id' => $preview->id,
                'post_id'  => $post->id,
                'is_link'  => 1,
            ]);

            if (! $mayFetch || ! ($preview->wasRecentlyCreated || $this->isDue($preview, $ttlAgo))) {
                continue;
            }

            // Self-link short-circuit: if the URL has the shape of a
            // self-link (host+path match our own forum), it never goes
            // to HTTP at all. Resolve locally — either synthesise OG data
            // (visible public discussion) or record a permanent failure
            // (discussion missing / hidden / private). Never falls back
            // to HTTP because Cloudflare would block the loopback fetch
            // anyway.
            if ($this->localResolver->parseSelfLink($url) !== null) {
                $local = $this->localResolver->resolve($url);
                $preview->retrieved_at = Carbon::now();
                if ($local !== null) {
                    $preview->http_status = 200;
                    $preview->opengraph = $local;
                    $preview->final_url = $url;
                    $preview->error = null;
                    $preview->refresh_error = null;
                    $preview->fetch_attempts = 0;
                } else {
                    $preview->http_status = 0;
                    $preview->error = 'self_link_not_viewable';
                    // Settled: stamped so the retry pass can skip it in SQL.
                    $preview->fetch_attempts = FailurePolicy::SETTLED_ATTEMPTS;
                }
                $preview->save();
            } elseif ($this->queue instanceof SyncQueue) {
                // No async worker present: a sync queue runs the job inline,
                // which would block this post-save request on the remote
                // fetch. Leave the placeholder row (retrieved_at NULL) for
                // the scheduled sweep to re-dispatch from cron, where a
                // blocking fetch is harmless. Needs `php flarum schedule:run`
                // on cron; the card appears within a few minutes (the
                // front-end holds its place with a skeleton meanwhile).
                continue;
            } else {
                $this->queue->push(new FetchPreviewJob($preview->id));
            }
        }
    }

    /**
     * Never fetched, or fetched longer ago than the TTL.
     */
    private function isDue(Preview $preview, Carbon $ttlAgo): bool
    {
        return $preview->retrieved_at === null
            || Carbon::parse($preview->retrieved_at)->lt($ttlAgo);
    }

    /**
     * The row for $url, created if it does not exist yet.
     *
     * insertOrIgnore + re-select rather than find-then-insert: two saves that
     * carry the same new URL at the same moment (two replies quoting one link,
     * a double-submitted edit) would both miss on the find and the loser's
     * insert would hit the url_hash unique key — an integrity exception thrown
     * out of a Posted listener, i.e. a 500 on a post that has already been
     * saved. The loser now simply adopts the winner's row, and only the
     * request that actually inserted it dispatches the fetch
     * (wasRecentlyCreated).
     */
    private function findOrCreate(string $url, string $hash): ?Preview
    {
        // INSERT IGNORE would silently truncate an over-long URL into the
        // column instead of failing. Remote URLs are already capped by the
        // extractor's validator; this catches the self-links that bypass it.
        if (strlen($url) > UrlValidator::MAX_LEN) {
            return null;
        }

        $inserted = $this->db->table('ekumanov_link_previews')->insertOrIgnore([
            'url' => $url,
            'url_hash' => $hash,
            'created_at' => Carbon::now(),
        ]);

        $preview = Preview::where('url_hash', $hash)->first();

        if ($preview === null) {
            // Ignored for some reason other than the unique key. Nothing to
            // link; the post itself is fine.
            $this->log->warning('link-preview: could not create a preview row', ['url' => $url]);

            return null;
        }

        $preview->wasRecentlyCreated = $inserted > 0;

        return $preview;
    }

    /**
     * @param list<string> $currentUrls
     */
    private function pruneObsoletePivots(int $postId, array $currentUrls): void
    {
        $hashes = array_map(fn ($url) => sha1($url, true), $currentUrls);

        if ($hashes === []) {
            $this->db->table('ekumanov_link_preview_post')->where('post_id', $postId)->delete();
            return;
        }

        $keepIds = Preview::whereIn('url_hash', $hashes)->pluck('id')->all();

        $q = $this->db->table('ekumanov_link_preview_post')->where('post_id', $postId);
        if ($keepIds !== []) {
            $q->whereNotIn('preview_id', $keepIds);
        }
        $q->delete();
    }
}
