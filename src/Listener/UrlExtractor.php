<?php

namespace Ekumanov\LinkPreview\Listener;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Ekumanov\LinkPreview\Http\UrlValidator;
use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\LinkPreview\Settings\SettingsRepository;

/**
 * Pulls fetchable URLs out of a rendered post body.
 *
 *   - Only <a href> elements (no autolinking, no image src, no embedded media).
 *   - Each URL passes through UrlValidator first, so anything we wouldn't fetch
 *     anyway is excluded before downstream code sees it.
 *   - Deduped within a single body — a post linking the same URL three times
 *     creates one fetch, one card.
 *   - Honors whitelist/blacklist from settings.
 *   - Caps at maxUrlsPerPost so a wall-of-links post can't open a thousand
 *     fetches in one transaction. Cap enforced AFTER dedupe and filtering so
 *     50 copies of the same URL still count as 1.
 */
final class UrlExtractor
{
    /**
     * Hosts that Flarum 2.0 core auto-previews. We skip these at scan-time so
     * we don't waste a fetch + queue slot on something the front-end already
     * handles natively.
     */
    private const SKIP_HOSTS = [
        'youtube.com', 'www.youtube.com', 'm.youtube.com',
        'youtu.be',
        'youtube-nocookie.com', 'www.youtube-nocookie.com',
    ];

    /**
     * Path-suffix patterns whose URLs Flarum's formatter already renders
     * inline as <img>. These don't need OG fetching. (We can't ALWAYS tell a
     * URL is an image without fetching it, but the common case is "obvious
     * image extension in the path" — that's covered here cheaply.)
     */
    private const SKIP_EXTENSIONS = [
        '.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif', '.svg', '.bmp', '.ico',
    ];

    public function __construct(
        private readonly UrlValidator $urlValidator,
        private readonly SettingsRepository $settings,
        private readonly LocalDiscussionResolver $localResolver,
    ) {}

    /**
     * Test whether a host matches any entry in a list, with two ergonomic
     * affordances admins expect:
     *  - the `www.` prefix is normalised on both sides — `amazon.com` blocks
     *    `www.amazon.com` and vice versa.
     *  - entries starting with `*.` match any subdomain (`*.amazon.com`
     *    matches `smile.amazon.com`, `prime.amazon.com`, but NOT bare
     *    `amazon.com` — add that as a separate entry if you want both).
     *
     * @param list<string> $list
     */
    private static function hostMatches(string $host, array $list): bool
    {
        $normHost = self::normaliseHost($host);
        foreach ($list as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            if (str_starts_with($entry, '*.')) {
                $suffix = substr($entry, 1); // ".amazon.com"
                if (str_ends_with($host, $suffix) || str_ends_with($normHost, $suffix)) {
                    return true;
                }
                continue;
            }
            if (self::normaliseHost($entry) === $normHost) {
                return true;
            }
        }
        return false;
    }

    private static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @return list<string> deduped, validated URLs in document order
     */
    public function extract(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        // Skip anchors that aren't user-typed URLs:
        //   - class containing "Mention" → PostMention, UserMention, TagMention
        //     (s9e's mention plugins render these instead of <URL> tags)
        //   - inside any <blockquote> → quoted content from another post; the URL
        //     belongs to the quoted post, not this one. Embedding it here would
        //     duplicate whatever card the source post already shows.
        $anchors = $xpath->query(
            '//a[@href]'
                .'[not(contains(@class, "Mention"))]'
                .'[not(ancestor::blockquote)]'
        );
        if ($anchors === false) {
            return [];
        }

        $whitelist = $this->settings->whitelist();
        $blacklist = $this->settings->blacklist();
        $maxPerPost = $this->settings->maxUrlsPerPost();

        $seen = [];
        $out = [];

        foreach ($anchors as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;

            // Self-links bypass UrlValidator. The validator gates HTTP fetches
            // (scheme/port/userinfo), but self-links never reach the fetcher —
            // they're resolved against the local DB. That also lets dev
            // installs on non-standard ports (e.g. localhost:8081) work.
            $isSelfLink = $this->localResolver->parseSelfLink($href) !== null;
            $host = null;

            if (! $isSelfLink) {
                $v = $this->urlValidator->validate($href);
                if (! $v['ok']) {
                    continue;
                }
                $host = strtolower($v['host']);
            } else {
                $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            }
            if (in_array($host, self::SKIP_HOSTS, true)) {
                // Formatter / other extensions handle these (YouTube). No card needed.
                continue;
            }
            // Cheap path-extension check — skip obvious image URLs (the
            // formatter inlines them as <img>).
            $path = strtolower((string) parse_url($href, PHP_URL_PATH));
            foreach (self::SKIP_EXTENSIONS as $ext) {
                if (str_ends_with($path, $ext)) {
                    continue 2; // skip URL, next anchor
                }
            }

            if ($whitelist !== [] && ! self::hostMatches($host, $whitelist)) {
                continue;
            }
            if (self::hostMatches($host, $blacklist)) {
                continue;
            }

            $out[] = $href;
            if (count($out) >= $maxPerPost) {
                break;
            }
        }

        return $out;
    }
}
