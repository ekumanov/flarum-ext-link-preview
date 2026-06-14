<?php

namespace Ekumanov\LinkPreview\Console;

use Carbon\Carbon;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Illuminate\Console\Command;

/**
 * Re-resolves every cached self-link preview against the local DB.
 *
 * Why this exists: previews for the forum's own discussion URLs that were
 * cached BEFORE the LocalDiscussionResolver shipped were fetched over HTTP
 * like any external URL. Whatever og:image the forum served at the time
 * (typically the forum logo, via an SEO extension) got baked into the
 * cached row — and a wide logo cropped into a 120×90 thumbnail looks bad.
 * The local resolver intentionally produces image-less cards (title +
 * first-post excerpt), but the stale rows never re-resolve on their own:
 * re-fetch only happens when a post containing the URL is saved again
 * after TTL expiry.
 *
 * This command rewrites them all in place, synchronously (no queue —
 * resolution is one local DB lookup per row, same code path FetchPreviewJob
 * uses for self-links). Idempotent; safe to re-run any time.
 */
class RefreshSelfLinksCommand extends Command
{
    protected $signature = 'link-preview:refresh-self
                            {--dry-run : List what would be refreshed without writing anything.}';

    protected $description = 'Re-resolve cached self-link previews from the local DB (replaces stale HTTP-fetched OG data, e.g. cropped logo thumbnails).';

    public function handle(LocalDiscussionResolver $resolver): int
    {
        $dry = (bool) $this->option('dry-run');

        $stats = ['scanned' => 0, 'self' => 0, 'refreshed' => 0, 'unviewable' => 0];

        Preview::query()->orderBy('id')->chunk(100, function ($previews) use ($resolver, $dry, &$stats) {
            foreach ($previews as $preview) {
                $stats['scanned']++;

                if ($resolver->parseSelfLink($preview->url) === null) {
                    continue;
                }
                $stats['self']++;

                $local = $resolver->resolve($preview->url);

                if ($dry) {
                    // A stale thumbnail can live in opengraph.images OR the
                    // legacy fallback.images column (older HTTP fetches stored
                    // an <img>/logo there); firstImage() reads both.
                    $hadImage = (bool) (data_get($preview->opengraph, 'images.0') ?: data_get($preview->fallback, 'images.0'));
                    $this->line(sprintf(
                        '  would refresh #%d %s%s → %s',
                        $preview->id,
                        $preview->url,
                        $hadImage ? ' (has stale image)' : '',
                        $local !== null ? 'ok' : 'not viewable'
                    ));
                    $local !== null ? $stats['refreshed']++ : $stats['unviewable']++;
                    continue;
                }

                $preview->retrieved_at = Carbon::now();
                if ($local !== null) {
                    $preview->http_status = 200;
                    $preview->opengraph = $local;
                    $preview->final_url = $preview->url;
                    $preview->error = null;
                    // Local data is authoritative and image-less; wipe any
                    // residual metadata from a prior HTTP fetch so a stale
                    // fallback/icon thumbnail can't leak through firstImage().
                    $preview->fallback = null;
                    $preview->icons = null;
                    $preview->api_resource = null;
                    $preview->mime = null;
                    $preview->exif = null;
                    $stats['refreshed']++;
                } else {
                    // Discussion deleted / hidden / restricted since it was
                    // cached: clear the stale metadata so nothing renders.
                    $preview->http_status = 0;
                    $preview->opengraph = null;
                    $preview->error = 'self_link_not_viewable';
                    $stats['unviewable']++;
                }
                $preview->save();
            }
        });

        $this->info('Done. '.json_encode($stats));
        if ($dry) {
            $this->warn('(dry-run — nothing was written)');
        }

        return 0;
    }
}
