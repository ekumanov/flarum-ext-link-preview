<?php

namespace Ekumanov\LinkPreview\Parser;

/**
 * Picks the one favicon a card should show out of everything a page declared.
 *
 * Input is the decoded `ekumanov_link_previews.icons` column — the raw list
 * HtmlFallbackParser scraped from `<link rel="...icon...">` — plus the row's
 * final_url. Output is one absolute https URL, or null when the page gave us
 * nothing usable.
 *
 * Pure: no I/O, no settings, no container. Everything it knows comes from its
 * two arguments, which is what makes the ranking testable against the real
 * shapes found in the production table.
 */
final class IconPicker
{
    /**
     * The slot on the card is 18x18 CSS pixels, so a 32-64px source covers
     * a 2x display with no upscaling and no needless bytes. Anything further
     * from that window ranks lower — a 180x180 apple-touch-icon is a fine
     * last resort, a 16x16 is blurry on retina.
     */
    private const IDEAL_MIN = 32;
    private const IDEAL_MAX = 64;

    /**
     * `mask-icon` (and Safari's pinned-tab SVG) is a single-colour silhouette
     * meant to be tinted by the browser chrome. Rendered as an ordinary <img>
     * it is a black blob. Of 4252 production rows, zero declare a mask icon
     * and nothing else, so skipping these never costs us a candidate.
     */
    private const MASK_REL_MARKER = 'mask-icon';
    private const MASK_HREF_MARKER = 'safari-pinned-tab';

    /**
     * @param mixed  $icons    the decoded `icons` column; anything not shaped
     *                         like a list of {href, type?, sizes?} is ignored
     * @param string $finalUrl the URL the fetch actually landed on — relative
     *                         hrefs resolve against this, NOT against og:url,
     *                         which pages get wrong (several production rows
     *                         claim http:// in og:url while serving https)
     */
    public function pick(mixed $icons, string $finalUrl, ?int $maxBytes = null): ?string
    {
        return $this->rank($icons, $finalUrl, $maxBytes)[0]['url'] ?? null;
    }

    /**
     * Every usable candidate, best first. Callers that need to write back to
     * the stored list (IconResolver, recording a measured size) get each
     * candidate's index in the original `icons` array alongside its URL.
     *
     * @param  ?int $maxBytes when given, a candidate whose size has already
     *              been measured and exceeds this is dropped. Candidates with
     *              no recorded size are kept — most stored rows pre-date
     *              measurement and dropping them would blank their cards.
     * @return list<array{url:string,index:int}>
     */
    public function rank(mixed $icons, string $finalUrl, ?int $maxBytes = null): array
    {
        if (! is_array($icons) || $icons === []) {
            return [];
        }

        $base = parse_url($finalUrl);
        if (! is_array($base) || ! isset($base['host'])) {
            return [];
        }

        $candidates = [];

        foreach ($icons as $index => $icon) {
            if (! is_array($icon)) {
                continue;
            }

            // Measured and found wanting: either the origin gave us something
            // that isn't a usable image, or it is too heavy for an 18px slot.
            if (($icon['bad'] ?? false) === true) {
                continue;
            }
            if ($maxBytes !== null && isset($icon['bytes']) && (int) $icon['bytes'] > $maxBytes) {
                continue;
            }

            $href = is_string($icon['href'] ?? null) ? trim($icon['href']) : '';
            if ($href === '' || self::isMaskIcon($icon, $href)) {
                continue;
            }

            $url = self::absolutize($href, $base);
            if ($url === null) {
                continue;
            }

            $type = is_string($icon['type'] ?? null) ? strtolower(trim($icon['type'])) : '';

            $candidates[] = [
                'index' => $index,
                'url' => $url,
                // A candidate that was already https outranks one we had to
                // upgrade — see rankScheme(). Recorded before the upgrade so
                // the two are still distinguishable.
                'wasHttp' => str_starts_with(strtolower($href), 'http://'),
                'size' => self::bestSize($icon['sizes'] ?? null, $url),
                'type' => $type !== '' ? $type : self::typeFromExtension($url),
            ];
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, function (array $a, array $b): int {
            return self::rankScheme($a) <=> self::rankScheme($b)
                ?: self::rankSize($a['size']) <=> self::rankSize($b['size'])
                ?: self::rankType($a['type']) <=> self::rankType($b['type']);
        });

        return array_map(
            fn (array $c) => ['url' => self::forceHttps($c['url']), 'index' => $c['index']],
            $candidates,
        );
    }

    /**
     * Is this URL one of the icons the page declared?
     *
     * Deliberately ignores every filter `rank()` applies. Whether an og:image
     * is really just the site's logo has nothing to do with whether that logo
     * is small enough to serve — and the heaviest logos are exactly the ones
     * the ceiling rejects, so asking the filtered list would miss precisely
     * the cases worth catching.
     */
    public function matchesAnyIcon(mixed $icons, string $finalUrl, string $url): bool
    {
        if (! is_array($icons) || $icons === []) {
            return false;
        }

        $base = parse_url($finalUrl);
        if (! is_array($base) || ! isset($base['host'])) {
            return false;
        }

        $needle = self::forceHttps($url);

        foreach ($icons as $icon) {
            $href = is_array($icon) && is_string($icon['href'] ?? null) ? trim($icon['href']) : '';
            if ($href === '') {
                continue;
            }
            $resolved = self::absolutize($href, $base);
            if ($resolved !== null && self::forceHttps($resolved) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefer a candidate that was served over https already. We still upgrade
     * a lone http:// href rather than dropping it (63 production rows would
     * otherwise lose their mark) — a browser would block it as mixed content
     * anyway, and the front-end drops the image on error, so the worst case is
     * the card we already have today.
     */
    private static function rankScheme(array $candidate): int
    {
        return $candidate['wasHttp'] ? 1 : 0;
    }

    /**
     * Distance from the ideal window, with undeclared sizes landing between
     * "in the window" and "wrong size". A bare `/favicon.ico` with no `sizes`
     * attribute is a reasonable guess; a declared 16x16 is definitely blurry.
     */
    private static function rankSize(?int $size): int
    {
        if ($size === null) {
            return 1000;
        }
        if ($size >= self::IDEAL_MIN && $size <= self::IDEAL_MAX) {
            return 0;
        }

        return $size > self::IDEAL_MAX
            ? $size - self::IDEAL_MAX          // 180 → 116
            : (self::IDEAL_MIN - $size) * 10;  // 16 → 160: undersized hurts more
    }

    /**
     * PNG and SVG beat .ico. A multi-resolution .ico bundles every size the
     * site ever shipped — routinely 15 KB+ — to fill an 18px box.
     */
    private static function rankType(string $type): int
    {
        return match (true) {
            str_contains($type, 'svg') => 0,
            str_contains($type, 'png') => 0,
            str_contains($type, 'webp'), str_contains($type, 'jpeg'), str_contains($type, 'jpg') => 1,
            str_contains($type, 'icon'), str_contains($type, 'ico') => 3,
            default => 2,
        };
    }

    private static function isMaskIcon(array $icon, string $href): bool
    {
        $rel = is_string($icon['rel'] ?? null) ? strtolower($icon['rel']) : '';

        return str_contains($rel, self::MASK_REL_MARKER)
            || str_contains(strtolower($href), self::MASK_HREF_MARKER);
    }

    /**
     * Largest declared square-ish dimension, or null when the page declared
     * none. `sizes="any"` is already dropped by HtmlFallbackParser.
     */
    private static function bestSize(mixed $sizes, string $url): ?int
    {
        $best = null;

        if (is_array($sizes)) {
            foreach ($sizes as $size) {
                if (! is_array($size)) {
                    continue;
                }
                $w = (int) ($size['width'] ?? 0);
                $h = (int) ($size['height'] ?? 0);
                $dim = min($w, $h);
                if ($dim > 0 && ($best === null || $dim > $best)) {
                    $best = $dim;
                }
            }
        }

        if ($best !== null) {
            return $best;
        }

        // Fall back to a size baked into the filename — `favicon-32x32.png`
        // is a near-universal convention and is often declared without a
        // `sizes` attribute.
        if (preg_match('/(\d{2,4})x(\d{2,4})/', $url, $m) === 1) {
            $dim = min((int) $m[1], (int) $m[2]);
            return $dim > 0 ? $dim : null;
        }

        return null;
    }

    private static function typeFromExtension(string $url): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return match (true) {
            str_ends_with($path, '.svg') => 'image/svg+xml',
            str_ends_with($path, '.png') => 'image/png',
            str_ends_with($path, '.ico') => 'image/x-icon',
            str_ends_with($path, '.webp') => 'image/webp',
            str_ends_with($path, '.jpg'), str_ends_with($path, '.jpeg') => 'image/jpeg',
            default => '',
        };
    }

    /**
     * Resolve an href against the final URL. Rejects anything that isn't
     * http/https once resolved — `data:` and `javascript:` hrefs exist in the
     * wild and neither belongs in an <img src> we hand to every reader.
     *
     * @param array<string,mixed> $base parse_url() of the final URL
     */
    private static function absolutize(string $href, array $base): ?string
    {
        $scheme = strtolower((string) ($base['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }

        $host = (string) $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        // Absolute: keep as-is, but only if it's a web URL.
        if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $href, $m) === 1) {
            $hrefScheme = strtolower($m[1]);
            if ($hrefScheme !== 'http' && $hrefScheme !== 'https') {
                return null;
            }
            return parse_url($href, PHP_URL_HOST) !== null ? $href : null;
        }

        // Protocol-relative: //cdn.example.com/icon.png
        if (str_starts_with($href, '//')) {
            return parse_url($scheme.':'.$href, PHP_URL_HOST) !== null ? $scheme.':'.$href : null;
        }

        // Root-relative: /favicon.ico
        if (str_starts_with($href, '/')) {
            return "$scheme://$host$port$href";
        }

        // Document-relative: favicon.ico, ../img/favicon.png
        $path = (string) ($base['path'] ?? '/');
        $slash = strrpos($path, '/');
        $dir = $slash === false ? '/' : substr($path, 0, $slash + 1);

        return "$scheme://$host$port$dir$href";
    }

    /**
     * A card sits inside an https page on any forum worth deploying, so an
     * http icon is blocked as mixed content before it can even 404. Upgrade
     * and let the browser decide.
     */
    private static function forceHttps(string $url): string
    {
        return str_starts_with(strtolower($url), 'http://')
            ? 'https://'.substr($url, 7)
            : $url;
    }
}
