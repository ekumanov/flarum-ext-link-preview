<?php

namespace Ekumanov\LinkPreview\Console;

use Carbon\Carbon;
use Ekumanov\LinkPreview\Job\FetchPreviewJob;
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
 */
class SweepStuckPreviewsCommand extends Command
{
    protected $signature = 'link-preview:sweep
                            {--limit=200 : Maximum rows to re-enqueue in one run}
                            {--age=300  : Minimum row age in seconds before a missed row is swept (default 5 min)}';

    protected $description = 'Re-dispatch FetchPreviewJob for placeholder preview rows whose worker job appears to have been dropped.';

    public function handle(ConnectionInterface $db, Queue $queue): int
    {
        $limit = (int) $this->option('limit');
        $ageSec = (int) $this->option('age');

        $cutoff = Carbon::now()->subSeconds($ageSec);
        $floor = Carbon::now()->subHours(6);

        $rows = $db->table('ekumanov_link_previews')
            ->select('id')
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

        foreach ($rows as $row) {
            $queue->push(new FetchPreviewJob((int) $row->id));
        }

        $this->info('Re-dispatched '.$rows->count().' stuck preview(s).');
        return 0;
    }
}
