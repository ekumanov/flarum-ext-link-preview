<?php

namespace Ekumanov\LinkPreview\Console;

use Carbon\Carbon;
use Ekumanov\LinkPreview\Fetch\FailurePolicy;
use Ekumanov\LinkPreview\Job\FetchPreviewJob;
use Ekumanov\LinkPreview\PreviewChangeNotifier;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;

/**
 * Re-dispatches FetchPreviewJob for preview rows that have a placeholder but no
 * retrieved_at — i.e. the original job got lost (queue crash, Redis flush,
 * worker died mid-fetch).
 *
 * Wired into Flarum's scheduler at 5-minute intervals from extend.php; can
 * also be triggered manually via `php flarum link-preview:sweep`.
 *
 * Looks back 6h max — older rows are someone else's problem (e.g. a manual
 * import that never had a job dispatched in the first place). Note the floor
 * is on created_at, so zeroing retrieved_at on an ancient row will NOT get it
 * swept — use `link-preview:backfill --force-refresh` to force-refetch old
 * URLs, or `link-preview:refresh-self` for the forum's own discussion links.
 *
 * Bounded per row: each re-dispatch is counted in `sweep_dispatches`, which the
 * job resets whenever it reaches an outcome. A row still pending after
 * MAX_DISPATCHES re-dispatches is not "a lost job" any more — it is a fetch
 * that keeps dying (a job overrunning its timeout takes the worker down with
 * it) or a queue that is not running. Either way re-dispatching every five
 * minutes for six hours is the wrong answer, so the row is recorded as a
 * `stuck:` failure. That reason is retryable: link-preview:retry-failed takes
 * it from there with its normal backoff, which also covers the queue-outage
 * case once the worker is back.
 */
class SweepStuckPreviewsCommand extends Command
{
    protected $signature = 'link-preview:sweep
                            {--limit=200 : Maximum rows to re-enqueue in one run}
                            {--age=300  : Minimum row age in seconds before a missed row is swept (default 5 min)}';

    protected $description = 'Re-dispatch FetchPreviewJob for placeholder preview rows whose worker job appears to have been dropped.';

    /** Re-dispatches of one pending row before the sweep gives up on it. */
    public const MAX_DISPATCHES = 3;

    public function handle(ConnectionInterface $db, Queue $queue, PreviewChangeNotifier $notifier): int
    {
        $limit = (int) $this->option('limit');
        $ageSec = (int) $this->option('age');

        $cutoff = Carbon::now()->subSeconds($ageSec);
        $floor = Carbon::now()->subHours(6);

        $rows = $db->table('ekumanov_link_previews')
            ->select('id', 'sweep_dispatches', 'fetch_attempts')
            ->whereNull('retrieved_at')
            ->where('created_at', '<', $cutoff)
            ->where('created_at', '>', $floor)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No stuck previews to sweep.');
            return 0;
        }

        $dispatched = 0;
        $givenUp = [];

        foreach ($rows as $row) {
            $sent = (int) $row->sweep_dispatches;

            if ($sent >= self::MAX_DISPATCHES) {
                $error = 'stuck: fetch never finished after '.$sent.' re-dispatches';

                // Guarded on retrieved_at so a job that finished between our
                // SELECT and now keeps its real outcome.
                $db->table('ekumanov_link_previews')
                    ->where('id', $row->id)
                    ->whereNull('retrieved_at')
                    ->update([
                        'retrieved_at' => Carbon::now(),
                        'http_status' => 0,
                        'error' => $error,
                        'fetch_attempts' => FailurePolicy::attemptsAfterFailure($error, 0, (int) $row->fetch_attempts),
                        'sweep_dispatches' => 0,
                    ]);

                $givenUp[] = (int) $row->id;
                continue;
            }

            $db->table('ekumanov_link_previews')
                ->where('id', $row->id)
                ->update(['sweep_dispatches' => $sent + 1]);

            $queue->push(new FetchPreviewJob((int) $row->id));
            $dispatched++;
        }

        // Those rows were rendering as skeletons; now they render nothing.
        if ($givenUp !== []) {
            $notifier->previewsChanged($givenUp);
            $this->warn('Gave up on '.count($givenUp).' preview(s) whose fetch never finished.');
        }

        $this->info('Re-dispatched '.$dispatched.' stuck preview(s).');
        return 0;
    }
}
