<?php

namespace Ekumanov\LinkPreview\Fetch;

use Ekumanov\LinkPreview\Http\SafeHttpClient;

/**
 * Last resort for a page that declares no `<link rel="icon">` at all: ask its
 * origin for the conventional `/favicon.ico`, once.
 *
 * This is deliberately the *only* fallback. A third-party favicon service
 * (Google's `s2/favicons` and friends) would be one line of code and would
 * also hand that third party a list of every domain this forum links to,
 * looked up from every reader's browser. A server-side probe to the same host
 * whose page we just fetched adds no new party and no new client-side request.
 *
 * Everything about it is best-effort. A refusal, a redirect to a marketing
 * page, an HTML soft-404 dressed as a 200, a 40 KB multi-resolution .ico —
 * all of them mean "no icon", never "this preview failed".
 */
final class FaviconProbe
{
    public const PATH = '/favicon.ico';

    public function __construct(private readonly SafeHttpClient $client) {}

    /**
     * @param  string $pageUrl  the URL the page fetch landed on
     * @param  int    $maxBytes size ceiling; a larger body is discarded
     * @return array{href:string,probed:true}|null an `icons` entry, marked so a
     *         later backfill can tell a guess from a declared icon
     */
    public function probe(string $pageUrl, int $maxBytes): ?array
    {
        $origin = self::origin($pageUrl);
        if ($origin === null) {
            return null;
        }

        // Straight through SafeHttpClient — same validation, DNS pinning,
        // private-IP refusal and redirect re-checking as any other fetch. A
        // favicon URL is still an attacker-influenced URL.
        $result = $this->client->get($origin.self::PATH);

        if ($result['ok'] !== true || $result['status'] !== 200) {
            return null;
        }

        // A great many servers answer an unknown path with a 200 and their
        // homepage. Content-type is what separates a real icon from that.
        if (! str_starts_with(strtolower(trim($result['contentType'])), 'image/')) {
            return null;
        }

        $bytes = strlen($result['body']);
        if ($bytes === 0 || $bytes > $maxBytes) {
            return null;
        }

        // finalUrl, not the URL we asked for: an http origin that redirects to
        // https (or /favicon.ico → /assets/favicon.png) should be recorded
        // where the bytes actually are, so readers don't repeat the hop.
        return ['href' => $result['finalUrl'], 'probed' => true];
    }

    /**
     * scheme://host[:port] of a web URL, or null for anything else.
     */
    private static function origin(string $url): ?string
    {
        $p = parse_url($url);
        if (! is_array($p) || ! isset($p['scheme'], $p['host'])) {
            return null;
        }

        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return $scheme.'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }
}
