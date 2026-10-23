<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * Decides whether a page-declared image URL is fit to hand to every reader's
 * browser as a card thumbnail.
 *
 * The og:image is hot-linked, so whatever a site writes there is fetched by
 * the reader, not by us. Sites get it wrong in two ways that hurt the forum:
 *
 *  - plain http:// — the forum page drops to "not fully secure" (mixed
 *    content), whether or not the image ever loads. Upgraded to https, as
 *    IconPicker does for icons: nearly every such host serves both, and one
 *    that doesn't fails into the card's missing-image placeholder;
 *  - a host on the reader's own network — a WordPress install that leaks its
 *    dev hostname (seen live: `http://productionvoice.local/...`), localhost,
 *    a private IP. Chrome answers with a "wants to access devices on your
 *    local network" permission prompt, which reads like an attack.
 *
 * Syntactic only: this runs on every render, so it cannot resolve DNS. A
 * public name that resolves to a private address still gets through, and the
 * browser's own local-network gate is what stops that one.
 */
final class PublicImageUrl
{
    /**
     * Suffixes that never name a public host: RFC 6761/6762/8375 special-use
     * names plus the de-facto private ones routers and dev setups hand out.
     */
    private const PRIVATE_SUFFIXES = [
        'localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan',
        'home', 'home.arpa', 'corp', 'private', 'test', 'invalid', 'example',
    ];

    /**
     * The URL as an https URL when it is an absolute http(s) URL on a public
     * host, otherwise null.
     */
    public static function filter(string $url): ?string
    {
        $url = trim($url);
        if (str_starts_with(strtolower($url), 'http://')) {
            $url = 'https://'.substr($url, 7);
        }

        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        return $host !== '' && self::isPublicHost($host) ? $url : null;
    }

    private static function isPublicHost(string $host): bool
    {
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return ! PrivateIpFilter::isPrivate($ip);
        }

        // A single label (`intranet`, `nas`) only resolves through a local
        // search domain.
        if (! str_contains($host, '.')) {
            return false;
        }

        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return false;
            }
        }

        return true;
    }
}
