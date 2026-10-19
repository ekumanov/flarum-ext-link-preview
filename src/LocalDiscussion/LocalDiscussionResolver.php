<?php

namespace Ekumanov\LinkPreview\LocalDiscussion;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Flarum\User\User;
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
 * Discussion links are resolved when they are READ, not when they are
 * posted. The stored row is only a marker (see resolve()); the title, the
 * excerpt and — for a link to a reply — whose reply it is are looked up by
 * resolveFor() each time a post is serialized, under the reader's own
 * permissions. Nothing a discussion or reply said is ever written into the
 * previews table, so nothing can outlive a hide, a delete, an edit or a move
 * into a restricted tag, and a reader is never shown more than they could
 * open themselves.
 *
 * Tag pages and the index are still resolved once, under a Guest scope, and
 * stored like a fetched page: they change rarely and say nothing private.
 *
 * URL forms recognised (after stripping the forum base path):
 *   /d/{id}                         → discussion view
 *   /d/{id}-some-slug               → with slug
 *   /d/{id}/{postNumber}            → permalink to a post within
 *   /d/{id}-some-slug/{postNumber}  → same, with slug
 *   /t/{slug}, /t/{parent}/{child}  → tag page
 *   / (and the bare base path)      → forum index
 * A post number makes the card describe that reply — its author, date and
 * an excerpt — under the discussion's title. When the reader cannot see that
 * reply (hidden, deleted, unapproved, or the number names no comment) the
 * card falls back to the discussion's.
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

    /**
     * What a discussion link stores in `opengraph` instead of the discussion
     * itself. PostResourceFields sees it and resolves the card live.
     */
    public const LIVE_MARKER = 'discussion';

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
     * The discussion a self-link addresses and, when it names one, the post
     * number after it.
     *
     * @return array{discussion:int,post:?int}|null
     */
    public function parseSelfTarget(string $url): ?array
    {
        $parsed = $this->parseSelfPath($url);

        if ($parsed === null || $parsed['kind'] !== self::KIND_DISCUSSION) {
            return null;
        }

        return [
            'discussion' => (int) $parsed['value'],
            'post' => isset($parsed['post']) ? (int) $parsed['post'] : null,
        ];
    }

    /**
     * Classify a self-hosted URL into the thing it addresses. Returns null when
     * the URL is not ours at all, or sits outside the forum's mount path.
     *
     * @return array{kind:string,value:string,post?:string}|null
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
        if (preg_match('#^/d/(\d+)(?:-[^/]*)?(?:/(\d+))?/?$#', $urlPath, $m) === 1) {
            $parsed = ['kind' => self::KIND_DISCUSSION, 'value' => $m[1]];

            if (isset($m[2]) && $m[2] !== '') {
                $parsed['post'] = $m[2];
            }

            return $parsed;
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
     * What to STORE for a self-link, or null when there is nothing to store
     * (not ours, or a tag a guest cannot see). The returned shape matches the
     * `opengraph` JSON column the rest of the extension reads.
     *
     * A discussion link stores only a marker, whatever the discussion's state:
     * whether there is a card, and what it says, is decided per reader by
     * resolveFor(). Tag pages and the index store their card.
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $url): ?array
    {
        $parsed = $this->parseSelfPath($url);
        if ($parsed === null) {
            return null;
        }

        return match ($parsed['kind']) {
            self::KIND_DISCUSSION => ['live' => self::LIVE_MARKER, 'url' => $url],
            self::KIND_TAG => $this->resolveTag($url, $parsed['value']),
            self::KIND_INDEX => $this->resolveIndex($url),
            default => null,
        };
    }

    /**
     * The card for a link to a discussion or a reply, as `$actor` may see it,
     * or null when they may not see the discussion at all.
     *
     * Same shape as a stored card, plus `reply` when the link named a post the
     * actor can see: {number, author, time}.
     *
     * @return array<string,mixed>|null
     */
    public function resolveFor(string $url, User $actor): ?array
    {
        $target = $this->parseSelfTarget($url);
        if ($target === null) {
            return null;
        }

        $discussion = Discussion::query()
            ->whereVisibleTo($actor)
            ->with('firstPost')
            ->find($target['discussion']);

        if ($discussion === null) {
            return null;
        }

        $title = trim((string) $discussion->title);
        if ($title === '') {
            return null; // shouldn't happen, but defensively
        }

        $card = [
            'title' => $title,
            'description' => $this->excerpt($discussion->firstPost),
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

        if ($target['post'] !== null && ($reply = $this->visibleReply($discussion, $target['post'], $actor))) {
            $card['description'] = $this->excerpt($reply);
            $card['reply'] = [
                'number' => (int) $reply->number,
                'author' => $reply->user ? (string) $reply->user->display_name : null,
                'time' => $reply->created_at?->toIso8601String(),
            ];
        }

        return $card;
    }

    /**
     * The comment at this number, if the actor may see it. Post visibility
     * covers what discussion visibility does not: a hidden reply, one awaiting
     * approval, a private one.
     */
    private function visibleReply(Discussion $discussion, int $number, User $actor): ?Post
    {
        return Post::query()
            ->whereVisibleTo($actor)
            ->where('discussion_id', $discussion->id)
            ->where('number', $number)
            ->where('type', 'comment')
            ->with('user')
            ->first();
    }

    /**
     * Tag pages. Guest-scoped and stored, so a restricted tag produces
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

    private function excerpt(?Post $post): ?string
    {
        if ($post === null) {
            return null;
        }

        $rawContent = $post->getRawOriginal('content');
        if (! is_string($rawContent) || $rawContent === '') {
            return null;
        }

        // A reply usually opens by quoting the post it answers. That text is
        // someone else's, so the excerpt starts after it — unless the post is
        // nothing but a quote, when the quote is all there is to show.
        $plain = self::plainText(Utils::removeTag($rawContent, 'QUOTE'))
            ?: self::plainText($rawContent);

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

    /**
     * Stored post XML as one line of readable text. A mention's stored text is
     * its markup (`@"Alice"#p123`), so mentions are swapped for `@Alice` first;
     * removeFormatting then strips the remaining tags and markup.
     */
    private static function plainText(string $xml): string
    {
        $xml = (string) preg_replace_callback(
            '#<(POSTMENTION|USERMENTION)\b([^>]*)>.*?</\1>#s',
            fn (array $m) => preg_match('#\bdisplayname="([^"]*)"#', $m[2], $name) === 1 ? '@'.$name[1] : '',
            $xml
        );

        return trim((string) preg_replace('/\s+/', ' ', Utils::removeFormatting($xml)));
    }
}
