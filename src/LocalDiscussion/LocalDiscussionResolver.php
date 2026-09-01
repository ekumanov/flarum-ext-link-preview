<?php

namespace Ekumanov\LinkPreview\LocalDiscussion;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use s9e\TextFormatter\Utils;

/**
 * Self-link short-circuit. When a posted URL points to our own forum
 * (e.g. https://forum.example.com/d/2449-some-topic), synthesise
 * OG metadata from the local discussions/posts tables instead of HTTP-fetching
 * ourselves. Three benefits:
 *
 *   1. No Cloudflare loopback challenge — our server never tries to reach
 *      its own public hostname.
 *   2. No SSRF surface — the URL never reaches SafeHttpClient, so even an
 *      attacker crafting a self-link gets no fetch.
 *   3. Faster — one DB lookup vs one HTTP request.
 *
 * Permission model: we resolve under a Guest scope. Only fully-public
 * discussions (visible to anyone, no restricted tags, not hidden/unapproved,
 * not BYOBU private) produce cards. Private discussions return null and the
 * caller falls back to plain hyperlink — same outcome a guest crawler would
 * see if they hit the URL directly.
 *
 * URL forms recognised (after stripping the forum base path):
 *   /d/{id}                         → discussion view
 *   /d/{id}-some-slug               → with slug
 *   /d/{id}/{postNumber}            → permalink to a post within
 *   /d/{id}-some-slug/{postNumber}  → same, with slug
 *   /t/{slug}, /t/{parent}/{child}  → tag page
 *   / (and the bare base path)      → forum index
 * The post-number permalink form is matched but ignored — the card always
 * describes the DISCUSSION, not a specific reply. Post-anchored URLs scroll
 * to the post in the browser but the OG card is the discussion's.
 *
 * Anything else on our own host — a user profile, a loose file, a sibling app
 * sharing the domain — resolves to null, and the caller records a permanent
 * local failure. It must NOT fall through to the HTTP fetcher: reaching our
 * own public hostname from our own server is what Cloudflare answers with a
 * managed challenge, so those URLs came back 403 forever. isSelfHost() is the
 * test for that, and it is deliberately host-wide rather than path-scoped.
 */
final class LocalDiscussionResolver
{
    /** Plain-text description excerpted from the first post, capped at this many chars. */
    private const DESCRIPTION_MAX = 200;

    public const KIND_DISCUSSION = 'discussion';
    public const KIND_TAG = 'tag';
    public const KIND_INDEX = 'index';
    public const KIND_OTHER = 'other';

    public function __construct(
        private readonly string $forumBaseUrl,
        private readonly SettingsRepositoryInterface $settings,
    ) {}

    /**
     * True when the URL points at the host this forum is served from, whatever
     * the path. Callers use this as the "never send this to HTTP" gate.
     */
    public function isSelfHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $baseHost = parse_url($this->forumBaseUrl, PHP_URL_HOST);

        if (! is_string($host) || ! is_string($baseHost)) {
            return false;
        }

        return self::normaliseHost($host) === self::normaliseHost($baseHost);
    }

    /**
     * Pure URL parsing — returns the discussion ID if the URL is a recognisable
     * self-link to this forum, or null otherwise. Exposed for unit testing;
     * the production path goes through resolve().
     */
    public function parseSelfLink(string $url): ?int
    {
        $parsed = $this->parseSelfPath($url);

        return $parsed !== null && $parsed['kind'] === self::KIND_DISCUSSION
            ? (int) $parsed['value']
            : null;
    }

    /**
     * Classify a self-hosted URL into the thing it addresses. Returns null when
     * the URL is not ours at all, or sits outside the forum's mount path.
     *
     * @return array{kind:string,value:string}|null
     */
    public function parseSelfPath(string $url): ?array
    {
        if (! $this->isSelfHost($url)) {
            return null;
        }

        $base = parse_url($this->forumBaseUrl);
        if (! is_array($base)) {
            return null;
        }

        $basePath = rtrim($base['path'] ?? '', '/');
        $urlPath = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        if ($basePath !== '') {
            // Forum mounted under a sub-path. URL must start with it,
            // otherwise this is a different app on the same host.
            if (! str_starts_with($urlPath, $basePath.'/') && $urlPath !== $basePath) {
                return ['kind' => self::KIND_OTHER, 'value' => ''];
            }
            $urlPath = substr($urlPath, strlen($basePath));
        }

        if ($urlPath === '' || $urlPath === '/') {
            return ['kind' => self::KIND_INDEX, 'value' => ''];
        }

        // /d/{numeric-id} optionally followed by -slug and/or /post-number.
        if (preg_match('#^/d/(\d+)(?:-[^/]*)?(?:/\d+)?/?$#', $urlPath, $m) === 1) {
            return ['kind' => self::KIND_DISCUSSION, 'value' => $m[1]];
        }

        // /t/{slug} or /t/{parent}/{child} — a child tag is addressed by its
        // own slug, so the last segment is the one to look up.
        // Delimiter is ~ here, not #: the path segments are matched with a
        // negated class and a # delimiter would end the pattern inside it.
        if (preg_match('~^/t/([^/]+(?:/[^/]+)*?)/?$~', $urlPath, $m) === 1) {
            $segments = explode('/', $m[1]);

            return ['kind' => self::KIND_TAG, 'value' => end($segments)];
        }

        return ['kind' => self::KIND_OTHER, 'value' => ''];
    }

    private static function normaliseHost(string $host): string
    {
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Resolve a URL into OpenGraph-shaped data, or null if not a self-link
     * or the target discussion isn't publicly viewable. The returned shape
     * matches the `opengraph` JSON column the rest of the extension reads,
     * so locally-synthesised data is indistinguishable from an HTTP-fetched
     * card by the time the display layer renders it.
     *
     * @return array{title:string,description:?string,site_name:string,url:string,type:string,images:list<array>}|null
     */
    public function resolve(string $url): ?array
    {
        $parsed = $this->parseSelfPath($url);
        if ($parsed === null) {
            return null;
        }

        return match ($parsed['kind']) {
            self::KIND_DISCUSSION => $this->resolveDiscussion($url, (int) $parsed['value']),
            self::KIND_TAG => $this->resolveTag($url, $parsed['value']),
            self::KIND_INDEX => $this->resolveIndex($url),
            default => null,
        };
    }

    /**
     * @return array{title:string,description:?string,site_name:string,url:string,type:string,images:list<array>}|null
     */
    private function resolveDiscussion(string $url, int $discussionId): ?array
    {
        // Guest scope — only synthesise for discussions visible to the world.
        // Anything hidden/unapproved/restricted/private returns null and the
        // caller falls back to plain hyperlink.
        $discussion = Discussion::query()
            ->whereVisibleTo(new Guest())
            ->with('firstPost')
            ->find($discussionId);

        if ($discussion === null) {
            return null;
        }

        $title = trim((string) $discussion->title);
        if ($title === '') {
            return null; // shouldn't happen, but defensively
        }

        return [
            'title' => $title,
            'description' => $this->excerptFirstPost($discussion),
            'site_name' => $this->siteName(),
            'url' => $url,
            'type' => 'article',
            // Self-links carry no per-discussion thumbnail (first-post images
            // are out of scope), so brand the card with the forum's social
            // share image when one is configured (fof/seo). Flagged `brand` so
            // the display layer renders it as a small favicon beside the site
            // name instead of a blown-up logo in the thumbnail slot. Absent
            // setting (or no fof/seo) → no image → text-only card, as before.
            'images' => $this->brandImage(),
        ];
    }

    /**
     * Tag pages. Guest-scoped like discussions, so a restricted tag produces
     * no card — the roster of tags a guest can't see stays unenumerable.
     * Returns null when flarum/tags isn't installed, which is why the class is
     * referenced lazily rather than imported.
     *
     * @return array{title:string,description:?string,site_name:string,url:string,type:string,images:list<array>}|null
     */
    private function resolveTag(string $url, string $slug): ?array
    {
        if ($slug === '' || ! class_exists(\Flarum\Tags\Tag::class)) {
            return null;
        }

        $tag = \Flarum\Tags\Tag::query()
            ->whereVisibleTo(new Guest())
            ->where('slug', $slug)
            ->first();

        if ($tag === null) {
            return null;
        }

        $title = trim((string) $tag->name);
        if ($title === '') {
            return null;
        }

        $description = trim((string) $tag->description);

        return [
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'site_name' => $this->siteName(),
            'url' => $url,
            'type' => 'website',
            'images' => $this->brandImage(),
        ];
    }

    /**
     * The forum index. Always public — if a guest can't see the index they
     * can't have been shown the link either.
     *
     * @return array{title:string,description:?string,site_name:string,url:string,type:string,images:list<array>}
     */
    private function resolveIndex(string $url): array
    {
        $description = trim((string) $this->settings->get('forum_description'));

        return [
            'title' => $this->siteName(),
            'description' => $description !== '' ? $description : null,
            'site_name' => $this->siteName(),
            'url' => $url,
            'type' => 'website',
            'images' => $this->brandImage(),
        ];
    }

    private function siteName(): string
    {
        return (string) ($this->settings->get('forum_title')
            ?: parse_url($this->forumBaseUrl, PHP_URL_HOST));
    }

    /**
     * @return list<array{url:string,brand:true}>
     */
    private function brandImage(): array
    {
        $url = trim((string) $this->settings->get('seo_social_media_image_url'));

        return $url !== '' ? [['url' => $url, 'brand' => true]] : [];
    }

    private function excerptFirstPost(Discussion $discussion): ?string
    {
        $firstPost = $discussion->firstPost;
        if ($firstPost === null) {
            return null;
        }

        $rawContent = $firstPost->getRawOriginal('content');
        if (! is_string($rawContent) || $rawContent === '') {
            return null;
        }

        // removeFormatting strips s9e XML tags and BBCode markup, leaving plain text.
        $plain = Utils::removeFormatting($rawContent);
        $plain = trim((string) preg_replace('/\s+/', ' ', $plain));

        if ($plain === '') {
            return null;
        }

        if (mb_strlen($plain) <= self::DESCRIPTION_MAX) {
            return $plain;
        }

        $excerpt = mb_substr($plain, 0, self::DESCRIPTION_MAX);
        $excerpt = rtrim($excerpt, " .,;:!?");

        return $excerpt.'…';
    }
}
