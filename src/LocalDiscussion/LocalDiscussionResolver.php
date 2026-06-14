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
 * The post-number permalink form is matched but ignored — the card always
 * describes the DISCUSSION, not a specific reply. Post-anchored URLs scroll
 * to the post in the browser but the OG card is the discussion's.
 */
final class LocalDiscussionResolver
{
    /** Plain-text description excerpted from the first post, capped at this many chars. */
    private const DESCRIPTION_MAX = 200;

    public function __construct(
        private readonly string $forumBaseUrl,
        private readonly SettingsRepositoryInterface $settings,
    ) {}

    /**
     * Pure URL parsing — returns the discussion ID if the URL is a recognisable
     * self-link to this forum, or null otherwise. Exposed for unit testing;
     * the production path goes through resolve().
     */
    public function parseSelfLink(string $url): ?int
    {
        $parsedUrl = parse_url($url);
        if (! is_array($parsedUrl) || ! isset($parsedUrl['host'])) {
            return null;
        }

        $base = parse_url($this->forumBaseUrl);
        if (! is_array($base) || ! isset($base['host'])) {
            return null;
        }

        // Host match (with www. prefix normalisation on both sides).
        $normalise = static fn (string $h): string => str_starts_with(strtolower($h), 'www.')
            ? substr(strtolower($h), 4)
            : strtolower($h);

        if ($normalise($parsedUrl['host']) !== $normalise($base['host'])) {
            return null;
        }

        $basePath = rtrim($base['path'] ?? '', '/');
        $urlPath = $parsedUrl['path'] ?? '';

        if ($basePath !== '') {
            // Forum mounted under a sub-path. URL must start with it,
            // otherwise this is a different app on the same host.
            if (! str_starts_with($urlPath, $basePath.'/') && $urlPath !== $basePath) {
                return null;
            }
            $urlPath = substr($urlPath, strlen($basePath));
        }

        // Match /d/{numeric-id} optionally followed by -slug and/or /post-number.
        if (preg_match('#^/d/(\d+)(?:-[^/]*)?(?:/\d+)?/?$#', $urlPath, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
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
        $discussionId = $this->parseSelfLink($url);
        if ($discussionId === null) {
            return null;
        }

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
            'site_name' => (string) ($this->settings->get('forum_title') ?: parse_url($this->forumBaseUrl, PHP_URL_HOST)),
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
