<?php

namespace Ekumanov\LinkPreview\Listener;

use Carbon\Carbon;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\Job\FetchPreviewJob;
use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\LinkPreview\RateLimit\UrlSubmissionLimiter;
use Ekumanov\LinkPreview\Settings\SettingsRepository;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
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

        $allowed = $this->limiter->consume($actor, count($urls));
        if ($allowed < count($urls)) {
            $this->log->info('link-preview: user hit hourly URL limit', [
                'user_id' => $actor->id, 'submitted' => count($urls), 'allowed' => $allowed,
            ]);
        }
        if ($allowed === 0) {
            return;
        }

        $ttlAgo = Carbon::now()->subSeconds($this->settings->ttlSeconds());

        foreach (array_slice($urls, 0, $allowed) as $url) {
            $hash = sha1($url, true);

            $preview = Preview::where('url_hash', $hash)->first();
            $isNew = false;

            if ($preview === null) {
                $preview = new Preview();
                $preview->url = $url;
                $preview->url_hash = $hash;
                $preview->created_at = Carbon::now();
                $preview->save();
                $isNew = true;
            }

            // Link the preview to this post. Use insertOrIgnore so repeats are
            // idempotent (e.g. revising the same post twice with the same URL).
            $this->db->table('ekumanov_link_preview_post')->insertOrIgnore([
                'preview_id' => $preview->id,
                'post_id'  => $post->id,
                'is_link'  => 1,
            ]);

            $needsFetch = $isNew
                || $preview->retrieved_at === null
                || Carbon::parse($preview->retrieved_at)->lt($ttlAgo);

            if ($needsFetch) {
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
                    } else {
                        $preview->http_status = 0;
                        $preview->error = 'self_link_not_viewable';
                    }
                    $preview->save();
                } else {
                    $this->queue->push(new FetchPreviewJob($preview->id));
                }
            }
        }
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
