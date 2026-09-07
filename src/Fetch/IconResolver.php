<?php

namespace Ekumanov\LinkPreview\Fetch;

use Ekumanov\LinkPreview\Http\SafeHttpClient;
use Ekumanov\LinkPreview\Icon\IconStore;
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

    /**
     * How many times we will try to take our own copy of one icon before
     * giving up and leaving it hot-linked.
     *
     * Some hosts answer this server with a 409 or a timeout while answering
     * readers perfectly well — measured on a live install, 25 of 30 sampled
     * icons we could not fetch loaded fine from an ordinary connection. That is
     * IP reputation, not a broken icon, and no number of retries fixes it. So
     * after a few attempts we stop asking and fall back to exactly what the
     * card did before the proxy existed: hot-link it. The alternative — a
     * lettered chip — would take a working icon away from the reader to win a
     * privacy point on a domain the card is already linking to.
     */
    public const MAX_PROXY_TRIES = 3;

    public function __construct(
        private readonly SafeHttpClient $client,
        private readonly IconPicker $picker,
        private readonly IconStore $store,
    ) {}

    /**
     * @param  list<array<string,mixed>> $icons
     * @return array{icons:list<array<string,mixed>>,checks:int,changed:bool}
     */
    /**
     * @param  bool $proxy keep a copy on our own disk so readers never fetch
     *                     the icon from its source
     * @return array{icons:list<array<string,mixed>>,checks:int,changed:bool}
     */
    public function validate(array $icons, string $finalUrl, int $maxBytes, bool $proxy = false): array
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
            $tries = (int) ($entry['proxy_tries'] ?? 0);

            // We have asked for this one enough times. Leave it exactly as it
            // is — hot-linked — and stop spending requests on a host that has
            // made its position clear.
            if ($tries >= self::MAX_PROXY_TRIES) {
                break;
            }

            // Wanted on our own disk and not there yet — worth a fetch even if
            // we already know its size, because the size is all we kept. This
            // is what lets the proxy roll out over rows measured before it
            // existed, at one request each.
            $needsCopy = $proxy
                && ! isset($entry['stored'])
                && ! array_key_exists('proxy', $entry);

            // Already measured and still winning: it fits, we are done.
            if (isset($entry['bytes']) && ! $needsCopy) {
                break;
            }

            $checks++;
            $body = $this->fetch($top['url']);

            if ($body === null) {
                // Couldn't reach it. Count the attempt when we were trying to
                // take a copy, so a host that never answers us eventually
                // settles on hot-linking instead of being asked forever. A
                // pure measurement pass leaves no mark, exactly as before.
                if ($needsCopy) {
                    $icons[$top['index']]['proxy_tries'] = $tries + 1;
                    $changed = true;
                }

                break;
            }

            if ($body === false) {
                $icons[$top['index']]['bad'] = true; // definite: not a usable image
                $changed = true;
                continue;
            }

            $measured = strlen($body);
            $icons[$top['index']]['bytes'] = $measured;
            $changed = true;

            if ($measured > $maxBytes) {
                continue; // too heavy; the annotation promotes the next candidate
            }

            // It fits. Take our own copy while the bytes are still in hand —
            // this is the whole reason the proxy costs no extra request.
            if ($proxy) {
                $stored = $this->store->store($body);

                if ($stored !== null) {
                    $icons[$top['index']]['stored'] = $stored;
                    unset($icons[$top['index']]['proxy_tries']); // it answered in the end
                } elseif (IconStore::identify($body) === null) {
                    // A format we will never re-serve — an SVG, or something
                    // that is not an image at all. Recorded so the display
                    // layer shows a monogram rather than quietly hot-linking
                    // it after all.
                    $icons[$top['index']]['proxy'] = false;
                }
                // Otherwise the bytes were fine and the write failed: a
                // directory the worker cannot write, a full disk. Annotate
                // nothing, so the next run tries again instead of condemning
                // a perfectly good icon over a permissions mistake.
            }

            break; // the winner fits
        }

        return ['icons' => array_values($icons), 'checks' => $checks, 'changed' => $changed];
    }

    /**
     * @return string|false|null the body; false = a definite "not a usable
     *                           image"; null = we could not tell (transport
     *                           failure, so no verdict and no annotation)
     */
    private function fetch(string $url): string|false|null
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

        return $result['body'] === '' ? false : $result['body'];
    }
}
