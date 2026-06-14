<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Listener;

use Ekumanov\LinkPreview\Http\UrlValidator;
use Ekumanov\LinkPreview\Listener\UrlExtractor;
use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\LinkPreview\Settings\SettingsRepository;
use Flarum\Settings\SettingsRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class UrlExtractorTest extends TestCase
{
    public function test_extracts_basic_anchor_hrefs(): void
    {
        $html = '<p>see <a href="https://example.com/a">a</a> and <a href="https://other.com/b">b</a></p>';
        $this->assertSame(
            ['https://example.com/a', 'https://other.com/b'],
            $this->extractor()->extract($html)
        );
    }

    public function test_dedupes_repeated_links(): void
    {
        $html = '<p><a href="https://example.com/x">1</a> <a href="https://example.com/x">2</a> <a href="https://example.com/x">3</a></p>';
        $this->assertSame(['https://example.com/x'], $this->extractor()->extract($html));
    }

    public function test_skips_invalid_schemes(): void
    {
        $html = '<a href="javascript:alert(1)">bad</a><a href="ftp://x/">also bad</a><a href="https://good.com/">good</a>';
        $this->assertSame(['https://good.com/'], $this->extractor()->extract($html));
    }

    public function test_skips_userinfo_urls(): void
    {
        $html = '<a href="https://user:pw@example.com/">bad</a><a href="https://example.com/">good</a>';
        $this->assertSame(['https://example.com/'], $this->extractor()->extract($html));
    }

    public function test_respects_max_urls_per_post(): void
    {
        // 5 unique URLs, cap at 3
        $html = '';
        for ($i = 1; $i <= 5; $i++) {
            $html .= "<a href=\"https://example.com/$i\">l</a>";
        }
        $this->assertCount(3, $this->extractor(maxUrls: 3)->extract($html));
    }

    public function test_dedup_happens_before_cap(): void
    {
        // 100 copies of the same URL + 1 different. Cap at 2. Result: both unique URLs.
        $html = str_repeat('<a href="https://example.com/same">x</a>', 100)
              . '<a href="https://example.com/diff">y</a>';
        $this->assertSame(
            ['https://example.com/same', 'https://example.com/diff'],
            $this->extractor(maxUrls: 2)->extract($html)
        );
    }

    public function test_whitelist_blocks_off_list_hosts(): void
    {
        $html = '<a href="https://allowed.com/x">a</a><a href="https://blocked.com/y">b</a>';
        $r = $this->extractor(whitelist: ['allowed.com'])->extract($html);
        $this->assertSame(['https://allowed.com/x'], $r);
    }

    public function test_blacklist_filters_listed_hosts(): void
    {
        $html = '<a href="https://noisy.com/x">a</a><a href="https://example.com/y">b</a>';
        $r = $this->extractor(blacklist: ['noisy.com'])->extract($html);
        $this->assertSame(['https://example.com/y'], $r);
    }

    public function test_blacklist_normalises_www_prefix_on_either_side(): void
    {
        // entry without www, URL with www
        $html = '<a href="https://www.amazon.com/dp/X">a</a><a href="https://example.com/">good</a>';
        $r = $this->extractor(blacklist: ['amazon.com'])->extract($html);
        $this->assertSame(['https://example.com/'], $r);

        // entry with www, URL without www
        $html2 = '<a href="https://amazon.com/dp/X">a</a><a href="https://example.com/">good</a>';
        $r2 = $this->extractor(blacklist: ['www.amazon.com'])->extract($html2);
        $this->assertSame(['https://example.com/'], $r2);
    }

    public function test_blacklist_supports_subdomain_wildcard(): void
    {
        $html = '
            <a href="https://prime.amazon.com/x">a</a>
            <a href="https://smile.amazon.com/y">b</a>
            <a href="https://amazon.com/z">c</a>
            <a href="https://example.com/q">good</a>
        ';
        // *.amazon.com matches sub.amazon.com but NOT bare amazon.com
        $r = $this->extractor(blacklist: ['*.amazon.com'])->extract($html);
        $this->assertSame(['https://amazon.com/z', 'https://example.com/q'], $r);

        // Combine *.amazon.com + amazon.com to catch all amazon
        $r2 = $this->extractor(blacklist: ['amazon.com', '*.amazon.com'])->extract($html);
        $this->assertSame(['https://example.com/q'], $r2);
    }

    public function test_whitelist_also_normalises_www_and_supports_wildcard(): void
    {
        $html = '
            <a href="https://www.example.com/a">a</a>
            <a href="https://sub.example.com/b">b</a>
            <a href="https://other.com/c">c</a>
        ';
        $r = $this->extractor(whitelist: ['example.com', '*.example.com'])->extract($html);
        $this->assertSame(['https://www.example.com/a', 'https://sub.example.com/b'], $r);
    }

    public function test_returns_empty_for_empty_input(): void
    {
        $this->assertSame([], $this->extractor()->extract(''));
        $this->assertSame([], $this->extractor()->extract('<p>no links here</p>'));
    }

    public function test_anchors_without_href_ignored(): void
    {
        $html = '<a name="anchor">x</a><a href="https://example.com/">good</a>';
        $this->assertSame(['https://example.com/'], $this->extractor()->extract($html));
    }

    public function test_youtube_hosts_skipped(): void
    {
        // Flarum 2.0 auto-previews YouTube; we must NOT waste fetches on it.
        $html = '
            <a href="https://www.youtube.com/watch?v=abc">yt</a>
            <a href="https://youtu.be/abc">yt-short</a>
            <a href="https://m.youtube.com/watch?v=abc">yt-mobile</a>
            <a href="https://youtube-nocookie.com/preview/abc">yt-nocookie</a>
            <a href="https://example.com/article">good</a>
        ';
        $this->assertSame(['https://example.com/article'], $this->extractor()->extract($html));
    }

    public function test_image_url_extensions_skipped(): void
    {
        // Formatter inlines these as <img>; no card needed.
        $html = '
            <a href="https://cdn.example.com/photo.jpg">img</a>
            <a href="https://cdn.example.com/PHOTO.PNG">caps</a>
            <a href="https://cdn.example.com/anim.gif">gif</a>
            <a href="https://cdn.example.com/modern.webp">webp</a>
            <a href="https://cdn.example.com/vec.svg">svg</a>
            <a href="https://example.com/article">good</a>
        ';
        $this->assertSame(['https://example.com/article'], $this->extractor()->extract($html));
    }

    public function test_query_after_image_extension_does_not_break_skip(): void
    {
        // Many CDNs serve images with query params (`?v=`, signed URLs). The
        // PATH still ends in .jpg → skip. We use parse_url's path component.
        $html = '<a href="https://cdn.example.com/photo.jpg?v=2">cdn</a><a href="https://example.com/page">good</a>';
        $this->assertSame(['https://example.com/page'], $this->extractor()->extract($html));
    }

    public function test_self_links_bypass_url_validator(): void
    {
        // A self-link on a non-standard port (e.g. dev) would fail UrlValidator's
        // port check (only 80/443 allowed). But since self-links never reach
        // the HTTP fetcher, they should be allowed through.
        $html = '
            <a href="http://forum.example.invalid:8081/d/42">self on non-standard port</a>
            <a href="http://other.example.com:8081/page">other on non-standard port</a>
            <a href="https://example.com/page">good</a>
        ';
        $r = $this->extractor(forumBase: 'http://forum.example.invalid:8081')->extract($html);
        $this->assertSame(
            ['http://forum.example.invalid:8081/d/42', 'https://example.com/page'],
            $r,
            'Self-link passes despite bad port; non-self-link with same port still rejected'
        );
    }

    public function test_self_links_still_subject_to_blacklist(): void
    {
        // Admin-controlled blacklist applies even to self-links.
        $html = '<a href="https://forum.example.invalid/d/42">a</a>';
        $r = $this->extractor(blacklist: ['forum.example.invalid'])->extract($html);
        $this->assertSame([], $r);
    }

    public function test_skips_post_mention_anchors(): void
    {
        // Flarum's mentions plugin renders <POSTMENTION> tags as anchors with
        // class="PostMention". These point at /d/{id}/{n} self-links — embedding
        // them as cards would mean every reply to a thread gets a card to the
        // thread itself. Production bug that caused the regression on 2026-05-16.
        $html = '
            <blockquote class="uncited"><div><p>
              <a href="https://forum.example.invalid/d/2449/13" class="PostMention" data-id="55508">
                <i class="icon fas fa-reply"></i>PASHKULI
              </a> quoted content
            </p></div></blockquote>
            <p>My actual reply with <a href="https://example.com/real-url">a real URL</a></p>
        ';
        $r = $this->extractor()->extract($html);
        $this->assertSame(['https://example.com/real-url'], $r);
    }

    public function test_skips_user_mention_anchors(): void
    {
        $html = '
            <p>Hey <a href="/u/alice" class="UserMention" data-id="2">@alice</a>,
            check out <a href="https://example.com/page">this</a></p>
        ';
        $r = $this->extractor()->extract($html);
        $this->assertSame(['https://example.com/page'], $r);
    }

    public function test_skips_anchors_inside_blockquote(): void
    {
        // Quoted content reproduces another post's URLs. We shouldn't card those
        // — they belong to the quoted post.
        $html = '
            <blockquote><p>Original poster said: <a href="https://example.com/quoted">quoted url</a></p></blockquote>
            <p>My reply with <a href="https://example.com/my-url">my url</a></p>
        ';
        $r = $this->extractor()->extract($html);
        $this->assertSame(['https://example.com/my-url'], $r);
    }

    public function test_nested_blockquote_anchors_also_skipped(): void
    {
        $html = '
            <blockquote><div><p><a href="https://example.com/deep">deep</a></p></div></blockquote>
            <p><a href="https://example.com/own">own</a></p>
        ';
        $r = $this->extractor()->extract($html);
        $this->assertSame(['https://example.com/own'], $r);
    }

    private function extractor(int $maxUrls = 10, array $whitelist = [], array $blacklist = [], string $forumBase = 'https://forum.example.invalid'): UrlExtractor
    {
        $settings = new InMemorySettings([
            'ekumanov-link-preview.max_urls_per_post' => (string) $maxUrls,
            'ekumanov-link-preview.whitelist' => implode(',', $whitelist),
            'ekumanov-link-preview.blacklist' => implode(',', $blacklist),
        ]);
        return new UrlExtractor(
            new UrlValidator(),
            new SettingsRepository($settings),
            new LocalDiscussionResolver($forumBase, $settings),
        );
    }
}

/**
 * Minimal in-memory implementation of Flarum's settings interface, just for
 * driving UrlExtractor's tests.
 */
final class InMemorySettings implements SettingsRepositoryInterface
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values = []) {}

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $keyLike): void
    {
        // Real Flarum supports SQL LIKE patterns; tests don't need that.
        unset($this->values[$keyLike]);
    }
}
