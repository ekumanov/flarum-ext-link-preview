<?php

namespace Ekumanov\LinkPreview\Console;

use Ekumanov\LinkPreview\Fetch\FaviconProbe;
use Ekumanov\LinkPreview\Fetch\IconResolver;
use Ekumanov\LinkPreview\Icon\IconStore;
use Ekumanov\LinkPreview\Parser\IconPicker;
use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\LinkPreview\Settings\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * One-shot: give a site mark to cards that already render fine but never had
 * an icon stored.
 *
 * Every preview fetched since the extension shipped has had its declared
 * `<link rel="icon">` saved — those rows need nothing, they light up the next
 * time the card is rendered. This command is for the rest: pages that declared
 * no icon at all, where the only way to find one is to ask the origin for
 * `/favicon.ico`. On a live install with ~4000 cached URLs that was roughly 480
 * rows once self-links were excluded.
 *
 * What it deliberately does NOT do: re-fetch the page. Titles, descriptions,
 * thumbnails, `retrieved_at` and `fetch_attempts` are left exactly as they are,
 * so running this can neither age a cache entry nor turn a working card into a
 * failed one. The only column it writes is `icons`.
 *
 * Not scheduled. A backfill is a one-time catch-up; new rows get their probe
 * inside FetchPreviewJob.
 *
 *   php flarum link-preview:backfill-icons --dry-run
 *   php flarum link-preview:backfill-icons --limit=100 --delay=750
 */
class BackfillIconsCommand extends Command
{
    protected $signature = 'link-preview:backfill-icons
                            {--only= : Restrict to one pass: "probe" (rows with no icon), "validate" (measure + copy stored icons), or "prune" (delete unreferenced icon files). Default: validate + probe.}
                            {--limit=200 : Maximum rows to touch per pass in one run.}
                            {--host= : Only rows whose final URL is on this host (substring match).}
                            {--delay=500 : Milliseconds to wait between probes. Keep this non-zero.}
                            {--force : Run even when the "Probe for /favicon.ico" setting is off.}
                            {--dry-run : Show what would be probed without making any request or write.}';

    protected $description = 'Give older previews a site icon: measure the ones already stored, and probe /favicon.ico for those that have none.';

    public function handle(
        ConnectionInterface $db,
        FaviconProbe $probe,
        IconResolver $resolver,
        IconStore $store,
        IconPicker $picker,
        SettingsRepository $settings,
        LocalDiscussionResolver $localResolver,
    ): int {
        $dry = (bool) $this->option('dry-run');
        $only = strtolower(trim((string) $this->option('only')));

        if ($only !== '' && ! in_array($only, ['probe', 'validate', 'prune'], true)) {
            $this->error('--only must be "probe", "validate" or "prune".');
            return 1;
        }

        if ($only === 'prune') {
            $this->prunePass($db, $store, $picker, $settings, $dry);
            return 0;
        }

        if (! $settings->iconProbe() && ! $this->option('force')) {
            $this->error('Icon probing is switched off for this forum (Link Preview → "Probe for /favicon.ico").');
            $this->line('Enable it, or pass --force to run this backfill anyway.');
            return 1;
        }

        if ($only !== 'probe') {
            $this->validatePass($db, $resolver, $localResolver, $settings, $dry);
        }

        if ($only === 'validate') {
            return 0;
        }

        $limit = max(1, (int) $this->option('limit'));
        $hostFilter = strtolower(trim((string) $this->option('host')));
        $delayUs = max(0, (int) $this->option('delay')) * 1000;
        $maxBytes = $settings->faviconMaxBytes();

        // Candidate set from SQL is deliberately loose — "renders a card" is a
        // PHP decision (PostResourceFields), so the finer filtering happens
        // below rather than being duplicated as SQL that could drift from it.
        // Over-fetch because self-links and title-less rows drop out.
        $query = $db->table('ekumanov_link_previews')
            ->select('id', 'url', 'final_url', 'mime', 'opengraph', 'fallback')
            ->where('http_status', 200)
            ->whereNull('error')
            ->whereNotNull('retrieved_at')
            ->where(fn ($q) => $q->whereNull('icons')->orWhere('icons', '[]'))
            ->orderBy('id', 'asc');

        if ($hostFilter !== '') {
            $query->where(fn ($q) => $q->where('final_url', 'like', '%'.$hostFilter.'%')
                ->orWhere('url', 'like', '%'.$hostFilter.'%'));
        }

        $rows = $query->limit($limit * 4)->get();

        $stats = ['considered' => 0, 'skipped' => 0, 'probed' => 0, 'found' => 0, 'none' => 0, 'errors' => 0];
        $done = 0;

        foreach ($rows as $row) {
            if ($done >= $limit) {
                break;
            }

            $stats['considered']++;
            $target = $row->final_url ?: $row->url;

            if (! $this->rendersACard($row, $localResolver, $hostFilter, $target)) {
                $stats['skipped']++;
                continue;
            }

            $done++;

            if ($dry) {
                $this->line('  would probe '.$target);
                continue;
            }

            try {
                $icon = $probe->probe($target, $maxBytes);
            } catch (Throwable $e) {
                // Best-effort, same as the job: a probe that blows up leaves
                // the row untouched so a later run can try again.
                $stats['errors']++;
                $this->warn("preview {$row->id}: {$e->getMessage()}");
                continue;
            }

            $stats['probed']++;

            // A miss is recorded as an empty list, not left NULL: that is what
            // stops the next run — and the next TTL re-fetch — asking a site
            // with no favicon all over again.
            $db->table('ekumanov_link_previews')
                ->where('id', $row->id)
                ->update(['icons' => json_encode($icon === null ? [] : [$icon])]);

            if ($icon === null) {
                $stats['none']++;
            } else {
                $stats['found']++;
                $this->line('  '.$icon['href']);
            }

            if ($delayUs > 0) {
                usleep($delayUs);
            }
        }

        $this->info('Done. '.json_encode($stats));

        if ($dry) {
            $this->warn('(dry-run — nothing was requested or written)');
        } elseif ($done >= $limit) {
            $this->warn("Stopped at the --limit of {$limit}. Re-run to continue.");
        }

        return 0;
    }

    /**
     * Mirrors the display layer's own refusals: an image URL renders inline as
     * an <img> rather than a card, and a row with no title produces nothing at
     * all. Probing either would be a request nobody ever sees the result of.
     *
     * Self-links are excluded outright — the forum's own favicon is already in
     * the reader's tab, and fetching our public hostname from our own server is
     * exactly what a CDN answers with a challenge.
     */
    private function rendersACard(object $row, LocalDiscussionResolver $localResolver, string $hostFilter, string $target): bool
    {
        if ($row->mime !== null && str_starts_with((string) $row->mime, 'image/')) {
            return false;
        }

        if ($localResolver->isSelfHost($row->url)) {
            return false;
        }

        // `like %host%` above can match a host appearing in a query string;
        // check the real host now that we can parse it.
        if ($hostFilter !== '' && ! str_contains(strtolower((string) parse_url($target, PHP_URL_HOST)), $hostFilter)) {
            return false;
        }

        return $this->title($row->opengraph) !== null || $this->title($row->fallback) !== null;
    }

    private function title(?string $json): ?string
    {
        $decoded = $json === null ? null : json_decode($json, true);
        $title = is_array($decoded) ? ($decoded['title'] ?? null) : null;

        return is_string($title) && trim($title) !== '' ? $title : null;
    }

    /**
     * Measure the icons already stored on rows that render a card, so the
     * oversized ones stop being served. Only touches the `icons` column, and
     * only rows whose winning icon has never been measured — re-running is
     * cheap and idempotent.
     */
    private function validatePass(
        ConnectionInterface $db,
        IconResolver $resolver,
        LocalDiscussionResolver $localResolver,
        SettingsRepository $settings,
        bool $dry,
    ): void {
        $limit = max(1, (int) $this->option('limit'));
        $hostFilter = strtolower(trim((string) $this->option('host')));
        $delayUs = max(0, (int) $this->option('delay')) * 1000;
        $maxBytes = $settings->faviconMaxBytes();

        $query = $db->table('ekumanov_link_previews')
            ->select('id', 'url', 'final_url', 'mime', 'opengraph', 'fallback', 'icons')
            ->where('http_status', 200)
            ->whereNull('error')
            ->whereNotNull('retrieved_at')
            ->whereNotNull('icons')
            ->where('icons', '!=', '[]')
            // Rows whose icons have not been measured yet, or have been
            // measured but never copied to our own disk. A crude LIKE pair is
            // enough: it only has to narrow the scan, the real check is below.
            ->where(fn ($q) => $q->where('icons', 'not like', '%"bytes"%')
                ->orWhere(fn ($q2) => $q2->where('icons', 'not like', '%"stored"%')
                    ->where('icons', 'not like', '%"proxy"%')))
            ->orderBy('id', 'asc');

        if ($hostFilter !== '') {
            $query->where(fn ($q) => $q->where('final_url', 'like', '%'.$hostFilter.'%')
                ->orWhere('url', 'like', '%'.$hostFilter.'%'));
        }

        $rows = $query->limit($limit * 4)->get();

        $stats = ['considered' => 0, 'skipped' => 0, 'measured' => 0, 'oversized' => 0, 'unusable' => 0, 'requests' => 0, 'errors' => 0];
        $done = 0;

        $this->info('── validating stored icons');

        foreach ($rows as $row) {
            if ($done >= $limit) {
                break;
            }

            $stats['considered']++;
            $target = $row->final_url ?: $row->url;

            $icons = json_decode((string) $row->icons, true);
            if (! is_array($icons) || $icons === [] || ! $this->rendersACard($row, $localResolver, $hostFilter, $target)) {
                $stats['skipped']++;
                continue;
            }

            $done++;

            if ($dry) {
                $this->line('  would measure '.$target);
                continue;
            }

            try {
                $out = $resolver->validate($icons, $target, $maxBytes, $settings->proxyIcons());
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->warn("preview {$row->id}: {$e->getMessage()}");
                continue;
            }

            $stats['requests'] += $out['checks'];

            if (! $out['changed']) {
                continue;
            }

            $stats['measured']++;
            foreach ($out['icons'] as $icon) {
                if (($icon['bad'] ?? false) === true) {
                    $stats['unusable']++;
                } elseif (isset($icon['bytes']) && (int) $icon['bytes'] > $maxBytes) {
                    $stats['oversized']++;
                    $this->line(sprintf('  dropped %s KB  %s', round(((int) $icon['bytes']) / 1024), $icon['href']));
                }
            }

            $db->table('ekumanov_link_previews')
                ->where('id', $row->id)
                ->update(['icons' => json_encode($out['icons'])]);

            if ($delayUs > 0) {
                usleep($delayUs);
            }
        }

        $this->info('Validate pass: '.json_encode($stats));
    }

    /**
     * Delete stored icon files no preview row points at any more — rows that
     * were removed, re-fetched onto a different icon, or had one retired.
     *
     * Reads every row's icons rather than trusting a LIKE: a file is only
     * deleted once we have positively seen the whole corpus and it appeared in
     * none of it. Files are content-addressed, so the cost of being wrong is
     * one re-fetch, but deleting something still in use would blank a card.
     */
    private function prunePass(
        ConnectionInterface $db,
        IconStore $store,
        IconPicker $picker,
        SettingsRepository $settings,
        bool $dry,
    ): void {
        $referenced = [];

        $db->table('ekumanov_link_previews')
            ->select('icons')
            ->whereNotNull('icons')
            ->where('icons', 'like', '%"stored"%')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$referenced) {
                foreach ($rows as $row) {
                    foreach (json_decode((string) $row->icons, true) ?: [] as $icon) {
                        if (is_array($icon) && is_string($icon['stored'] ?? null)) {
                            $referenced[$icon['stored']] = true;
                        }
                    }
                }
            });

        $files = $store->all();
        $orphans = array_values(array_filter($files, fn (string $f) => ! isset($referenced[$f])));

        $this->info(sprintf(
            '── prune: %d file(s) on disk, %d still referenced, %d orphaned',
            count($files),
            count($referenced),
            count($orphans),
        ));

        if ($orphans === []) {
            return;
        }

        if ($dry) {
            foreach (array_slice($orphans, 0, 20) as $f) {
                $this->line('  would delete '.$f);
            }
            $this->warn('(dry-run — nothing was deleted)');
            return;
        }

        foreach ($orphans as $f) {
            $store->delete($f);
        }

        $this->info('Deleted '.count($orphans).' orphaned icon file(s).');
    }
}
