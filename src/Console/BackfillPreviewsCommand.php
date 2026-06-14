<?php

namespace Ekumanov\LinkPreview\Console;

use Carbon\Carbon;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\Job\FetchPreviewJob;
use Ekumanov\LinkPreview\Listener\UrlExtractor;
use Flarum\Post\CommentPost;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * One-shot backfill: replay URL-scanning over historical posts and enqueue
 * fetches for any URLs not already in the cache.
 *
 * Two scenarios this addresses:
 *   1. The adoption gap. A forum that installs this extension when it already
 *      has history won't have preview rows for URLs in those older posts.
 *      Backfill scans them and enqueues the missing fetches.
 *   2. Manual refresh of stale OG data. Pass `--force-refresh` to drop
 *      retrieved_at on matched preview rows, forcing the worker to re-fetch.
 *      Use sparingly: many sources rate-limit aggressive re-fetching.
 *
 * Safety:
 *   - Hard cap (default 200 posts/run) so a single invocation can't fill the
 *     queue. Run multiple times if you have a big backlog.
 *   - Posts processed oldest-first within the window so the user-visible
 *     order is "old discussions get cards first, recent already had them".
 *   - Per-host rate-limit and SafeHttpClient defenses still apply in the
 *     worker — backfill doesn't bypass any safety layer.
 *   - Does NOT rate-limit per-user (this is operator-initiated, not
 *     attacker-driven).
 *
 * Typical use:
 *   php flarum link-preview:backfill --since=2025-01-01 --limit=200
 *   (re-run with new --offset as needed, or schedule + walk away)
 */
class BackfillPreviewsCommand extends Command
{
    protected $signature = 'link-preview:backfill
                            {--since=  : Only scan posts created on or after this date (Y-m-d). Default: 30 days ago.}
                            {--until=  : Only scan posts created BEFORE this date (Y-m-d). Default: now.}
                            {--limit=200 : Maximum number of posts to scan in one run.}
                            {--offset=0 : Skip this many posts (paginate by re-running with offset += limit).}
                            {--force-refresh : Re-fetch URLs whose previews already exist (resets retrieved_at).}
                            {--dry-run : Print what WOULD be scanned/enqueued without writing anything.}';

    protected $description = 'Replay URL scanning over historical posts to backfill preview rows that pre-date the fetcher.';

    public function handle(
        ConnectionInterface $db,
        UrlExtractor $extractor,
        Queue $queue,
    ): int {
        $since = $this->option('since')
            ? Carbon::parse((string) $this->option('since'))->startOfDay()
            : Carbon::now()->subDays(30);

        $until = $this->option('until')
            ? Carbon::parse((string) $this->option('until'))->endOfDay()
            : Carbon::now();

        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $force = (bool) $this->option('force-refresh');
        $dry = (bool) $this->option('dry-run');

        $this->info("Backfill window: {$since->toDateString()} → {$until->toDateString()}");
        $this->info("Limit={$limit} offset={$offset} force-refresh=".($force ? 'YES' : 'no').' dry-run='.($dry ? 'YES' : 'no'));

        $rows = $db->table('posts')
            ->select('id')
            ->where('type', 'comment')
            ->where('is_private', 0)
            ->where('is_approved', 1)
            ->whereNull('hidden_at')
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $until)
            ->orderBy('created_at', 'asc')
            ->offset($offset)
            ->limit($limit)
            ->pluck('id');

        if ($rows->isEmpty()) {
            $this->info('No posts in window.');
            return 0;
        }

        $this->info('Scanning '.$rows->count().' post(s)...');

        $stats = ['scanned' => 0, 'urls' => 0, 'new_previews' => 0, 'enqueued' => 0, 'skipped_cached' => 0, 'errors' => 0];

        foreach ($rows as $postId) {
            $stats['scanned']++;
            try {
                $post = CommentPost::find($postId);
                if ($post === null) {
                    continue;
                }
                $html = $post->formatContent();
                $urls = $extractor->extract($html);
                $stats['urls'] += count($urls);

                foreach ($urls as $url) {
                    $hash = sha1($url, true);
                    $preview = Preview::where('url_hash', $hash)->first();
                    $isNew = false;

                    if ($preview === null) {
                        if ($dry) {
                            $stats['new_previews']++;
                            $stats['enqueued']++;
                            continue;
                        }
                        $preview = new Preview();
                        $preview->url = $url;
                        $preview->url_hash = $hash;
                        $preview->created_at = Carbon::now();
                        $preview->save();
                        $isNew = true;
                        $stats['new_previews']++;
                    } elseif (! $force && $preview->retrieved_at !== null) {
                        $stats['skipped_cached']++;
                    }

                    if (! $dry) {
                        // Idempotent pivot link (post → preview).
                        $db->table('ekumanov_link_preview_post')->insertOrIgnore([
                            'preview_id' => $preview->id,
                            'post_id'  => $postId,
                            'is_link'  => 1,
                        ]);

                        $needsFetch = $isNew
                            || $preview->retrieved_at === null
                            || ($force && ! $isNew);

                        if ($force && ! $isNew) {
                            $preview->retrieved_at = null;
                            $preview->save();
                        }

                        if ($needsFetch) {
                            $queue->push(new FetchPreviewJob($preview->id));
                            $stats['enqueued']++;
                        }
                    }
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->warn("post {$postId}: {$e->getMessage()}");
            }
        }

        $this->info('Done. '.json_encode($stats));
        if ($dry) {
            $this->warn('(dry-run — nothing was written or enqueued)');
        }

        return 0;
    }
}
