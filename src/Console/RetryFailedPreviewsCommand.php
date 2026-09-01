<?php

namespace Ekumanov\LinkPreview\Console;

use Carbon\Carbon;
use Ekumanov\LinkPreview\Fetch\FailurePolicy;
use Ekumanov\LinkPreview\Job\FetchPreviewJob;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;

/**
 * Re-dispatches FetchPreviewJob for rows whose last fetch FAILED in a way that
 * might not fail again — a bot-block, a timeout, a 5xx. Complements
 * link-preview:sweep, which only rescues rows that were never fetched at all.
 *
 * Why this exists: a blocked URL used to be a dead row. The fetch recorded a
 * 403 and moved on, no retry was ever scheduled, and the post kept its bare
 * link forever even after the site stopped blocking us. On pianoclack that was
 * roughly a sixth of all failures — they had already started working again by
 * the time anyone looked.
 *
 * Backoff is per-row via `fetch_attempts`, so this is safe to schedule: a URL
 * that keeps failing is re-tried after 1h, 6h, 1d, 3d, 7d, 30d and then left
 * alone. Permanent failures (404, SSRF refusal, oversized body, non-HTML
 * media) are never selected — see FailurePolicy.
 *
 * Wired into the scheduler hourly from extend.php; also runs by hand:
 *   php flarum link-preview:retry-failed --dry-run
 */
class RetryFailedPreviewsCommand extends Command
{
    protected $signature = 'link-preview:retry-failed
                            {--limit=100 : Maximum rows to re-enqueue in one run.}
                            {--max-attempts= : Give up after this many consecutive failures. Default: '.FailurePolicy::MAX_ATTEMPTS.'.}
                            {--ignore-backoff : Re-try every eligible row now, regardless of when it last failed.}
                            {--dry-run : List what WOULD be re-tried without enqueueing anything.}';

    protected $description = 'Re-dispatch FetchPreviewJob for previews whose last fetch failed for a reason that may since have cleared.';

    public function handle(ConnectionInterface $db, Queue $queue): int
    {
        $limit = (int) $this->option('limit');
        $maxAttempts = $this->option('max-attempts') !== null
            ? (int) $this->option('max-attempts')
            : FailurePolicy::MAX_ATTEMPTS;
        $ignoreBackoff = (bool) $this->option('ignore-backoff');
        $dry = (bool) $this->option('dry-run');

        // Candidate set is deliberately wide — the retryable/permanent split
        // lives in FailurePolicy, in PHP, so the two callers can't drift.
        // Over-fetch (limit * 4) because most candidates are filtered out
        // below, by classification and then by backoff.
        $rows = $db->table('ekumanov_link_previews')
            ->select('id', 'url', 'error', 'http_status', 'retrieved_at', 'fetch_attempts')
            ->whereNotNull('error')
            ->whereNotNull('retrieved_at')
            ->where('fetch_attempts', '<', $maxAttempts)
            ->orderBy('retrieved_at', 'asc')
            ->limit($limit * 4)
            ->get();

        $now = Carbon::now();
        $due = [];

        foreach ($rows as $row) {
            if (! FailurePolicy::isRetryable($row->error, $row->http_status === null ? null : (int) $row->http_status)) {
                continue;
            }

            $attempts = (int) ($row->fetch_attempts ?? 0);

            if (! $ignoreBackoff) {
                $readyAt = Carbon::parse($row->retrieved_at)
                    ->addSeconds(FailurePolicy::backoffSeconds($attempts));

                if ($readyAt->greaterThan($now)) {
                    continue;
                }
            }

            $due[] = $row;

            if (count($due) >= $limit) {
                break;
            }
        }

        if ($due === []) {
            $this->info('No failed previews are due for a retry.');
            return 0;
        }

        foreach ($due as $row) {
            if ($dry) {
                $this->line(sprintf(
                    '  [%d attempt(s)] %s  (%s)',
                    (int) ($row->fetch_attempts ?? 0),
                    $row->url,
                    (string) $row->error,
                ));
                continue;
            }

            // The job's own TTL guard would no-op a row fetched recently, so
            // clear retrieved_at to mark it explicitly due.
            $db->table('ekumanov_link_previews')
                ->where('id', $row->id)
                ->update(['retrieved_at' => null]);

            $queue->push(new FetchPreviewJob((int) $row->id));
        }

        $this->info(($dry ? 'Would re-try ' : 'Re-dispatched ').count($due).' failed preview(s).');

        if ($dry) {
            $this->warn('(dry-run — nothing was enqueued)');
        }

        return 0;
    }
}
