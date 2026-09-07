<?php

namespace Ekumanov\LinkPreview\Fetch;

use Ekumanov\LinkPreview\Http\SafeHttpClient;
use Ekumanov\LinkPreview\Parser\IconPicker;

/**
 * Measures what a page's declared icons actually weigh, and records it.
 *
 * The ranking in IconPicker works from what a page *says* — rel, sizes, type,
 * filename. Measured against a live install, none of that predicts bytes: the
 * two heaviest icons found (292 KB and 216 KB, for an 18px slot) were a plain
 * `logo.png` and an unsized `favicon.ico`, both of which every metadata-based
 * heuristic ranks perfectly well. The only way to know is to fetch it.
 *
 * So the chosen icon is fetched once, server-side, at preview-fetch time, and
 * its size written back into the `icons` column as `bytes`. From then on the
 * picker can skip it for free on every render. An icon that answers with a
 * non-image or an error is marked `bad` and never chosen again.
 *
 * Bounded: at most MAX_CHECKS requests per row, and each rejection promotes the
 * next-best candidate, so a page that declares one huge icon and one sane one
 * ends up on the sane one. A transport failure stops the walk rather than
 * condemning the icon — a timeout today should not blank a card forever.
 */
final class IconResolver
{
    /**
     * Enough to get past a page that declares a giant apple-touch-icon and a
     * reasonable favicon.ico (the common shape), without turning one preview
     * fetch into a crawl of every icon a site has ever shipped.
     */
    public const MAX_CHECKS = 3;

    public function __construct(
        private readonly SafeHttpClient $client,
        private readonly IconPicker $picker,
    ) {}

    /**
     * @param  list<array<string,mixed>> $icons
     * @return array{icons:list<array<string,mixed>>,checks:int,changed:bool}
     */
    public function validate(array $icons, string $finalUrl, int $maxBytes): array
    {
        $checks = 0;
        $changed = false;

        while ($checks < self::MAX_CHECKS) {
            $ranked = $this->picker->rank($icons, $finalUrl, $maxBytes);
            if ($ranked === []) {
                break; // nothing left worth measuring
            }

            $top = $ranked[0];
            $entry = $icons[$top['index']] ?? [];

            // Already measured and still winning: it fits, we are done.
            if (isset($entry['bytes'])) {
                break;
            }

            $checks++;
            $measured = $this->measure($top['url']);

            if ($measured === null) {
                // Couldn't reach it. Leave the entry untouched so a later run
                // can try again, and stop — we can't make progress right now.
                break;
            }

            if ($measured === false) {
                $icons[$top['index']]['bad'] = true; // definite: not a usable image
            } else {
                $icons[$top['index']]['bytes'] = $measured;
            }

            $changed = true;

            if ($measured !== false && $measured <= $maxBytes) {
                break; // the winner fits
            }
        }

        return ['icons' => array_values($icons), 'checks' => $checks, 'changed' => $changed];
    }

    /**
     * @return int|false|null bytes; false = a definite "not a usable image";
     *                        null = we could not tell (transport failure)
     */
    private function measure(string $url): int|false|null
    {
        $result = $this->client->get($url);

        if ($result['ok'] !== true) {
            return null; // timeout, DNS, refused — no verdict
        }

        if ($result['status'] !== 200) {
            return false;
        }

        if (! str_starts_with(strtolower(trim($result['contentType'])), 'image/')) {
            return false;
        }

        $bytes = strlen($result['body']);

        return $bytes === 0 ? false : $bytes;
    }
}
