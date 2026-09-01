<?php

namespace Ekumanov\LinkPreview\Tests\Unit\LocalDiscussion;

use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\LinkPreview\Tests\Unit\Listener\InMemorySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * URL-parsing tests only. The DB lookup path (`resolve()`) is exercised via
 * the integration smoke test on the mirror — too much Eloquent surface to
 * mock here cheaply.
 */
final class LocalDiscussionResolverTest extends TestCase
{
    #[DataProvider('selfLinkProvider')]
    public function test_self_link_detected(string $forumBase, string $url, int $expectedId): void
    {
        $r = new LocalDiscussionResolver($forumBase, new InMemorySettings());
        $this->assertSame($expectedId, $r->parseSelfLink($url));
    }

    public static function selfLinkProvider(): array
    {
        return [
            // subpath-style: forum mounted under /forum
            'subpath-bare-id'         => ['https://myforum.com/forum', 'https://myforum.com/forum/d/2449', 2449],
            'subpath-with-slug'       => ['https://myforum.com/forum', 'https://myforum.com/forum/d/2449-some-topic', 2449],
            'subpath-with-postnum'    => ['https://myforum.com/forum', 'https://myforum.com/forum/d/2449/5', 2449],
            'subpath-slug-and-postnum' => ['https://myforum.com/forum', 'https://myforum.com/forum/d/2449-some-topic/5', 2449],
            'subpath-trailing-slash'  => ['https://myforum.com/forum', 'https://myforum.com/forum/d/2449-some-topic/', 2449],

            // root-mounted forum (e.g. https://example.com/d/...)
            'root-bare-id'            => ['https://example.com', 'https://example.com/d/42', 42],
            'root-with-slug'          => ['https://example.com', 'https://example.com/d/42-some-slug', 42],

            // www. on either side
            'www-on-url'              => ['https://myforum.com/forum', 'https://www.myforum.com/forum/d/2449', 2449],
            'www-on-base'             => ['https://www.myforum.com/forum', 'https://myforum.com/forum/d/2449', 2449],

            // schemes can differ — we don't care about scheme, just host+path
            'http-vs-https'           => ['https://myforum.com/forum', 'http://myforum.com/forum/d/2449', 2449],
        ];
    }

    #[DataProvider('notSelfLinkProvider')]
    public function test_non_self_link_returns_null(string $forumBase, string $url): void
    {
        $r = new LocalDiscussionResolver($forumBase, new InMemorySettings());
        $this->assertNull($r->parseSelfLink($url));
    }

    public static function notSelfLinkProvider(): array
    {
        return [
            'different-host'      => ['https://myforum.com/forum', 'https://example.com/forum/d/2449'],
            'different-subdomain' => ['https://myforum.com/forum', 'https://staging.myforum.com/forum/d/2449'],

            // path patterns that aren't a discussion view
            'home-page'           => ['https://myforum.com/forum', 'https://myforum.com/forum/'],
            'tag-page'            => ['https://myforum.com/forum', 'https://myforum.com/forum/t/general'],
            'user-page'           => ['https://myforum.com/forum', 'https://myforum.com/forum/u/alice'],
            'admin'               => ['https://myforum.com/forum', 'https://myforum.com/forum/admin'],
            'static-page'         => ['https://myforum.com/forum', 'https://myforum.com/forum/p/1-about'],

            // forum mounted under /forum, URL outside it
            'wrong-subpath'       => ['https://myforum.com/forum', 'https://myforum.com/d/2449'],
            'root-extra-path'    => ['https://myforum.com/forum', 'https://myforum.com/blog/d/2449'],

            // non-numeric IDs / malformed
            'non-numeric-id'      => ['https://myforum.com/forum', 'https://myforum.com/forum/d/abc-slug'],
            'no-id'               => ['https://myforum.com/forum', 'https://myforum.com/forum/d/'],
            'just-d'              => ['https://myforum.com/forum', 'https://myforum.com/forum/d'],
            'empty-url'           => ['https://myforum.com/forum', ''],
            'invalid-url'         => ['https://myforum.com/forum', 'not a url'],
        ];
    }

    // --- host-wide self detection ---------------------------------------

    #[DataProvider('selfHostProvider')]
    public function test_self_host_detection(string $forumBase, string $url, bool $expected): void
    {
        $r = new LocalDiscussionResolver($forumBase, new InMemorySettings());
        $this->assertSame($expected, $r->isSelfHost($url));
    }

    public static function selfHostProvider(): array
    {
        $base = 'https://myforum.com/forum';

        return [
            // The regression this fixes: same host, outside the mount path.
            // These used to fall through to the HTTP fetcher and come back 403
            // forever, because reaching our own hostname from our own server
            // is what Cloudflare answers with a challenge.
            'loose file at root'  => [$base, 'https://myforum.com/671b925b-966a-449a', true],
            'tag page'            => [$base, 'https://myforum.com/forum/t/some-tag', true],
            'discussion'          => [$base, 'https://myforum.com/forum/d/2449', true],
            'index'               => [$base, 'https://myforum.com/forum/', true],
            'www variant'         => [$base, 'https://www.myforum.com/anything', true],
            'different host'      => [$base, 'https://example.com/forum/d/1', false],
            'subdomain is not us' => [$base, 'https://cdn.myforum.com/x.html', false],
            'not a url'           => [$base, 'not a url at all', false],
        ];
    }

    #[DataProvider('selfPathProvider')]
    public function test_self_path_classification(string $url, string $expectedKind, string $expectedValue): void
    {
        $r = new LocalDiscussionResolver('https://myforum.com/forum', new InMemorySettings());
        $parsed = $r->parseSelfPath($url);

        $this->assertNotNull($parsed);
        $this->assertSame($expectedKind, $parsed['kind']);
        $this->assertSame($expectedValue, $parsed['value']);
    }

    public static function selfPathProvider(): array
    {
        return [
            'discussion'      => ['https://myforum.com/forum/d/2449-slug', LocalDiscussionResolver::KIND_DISCUSSION, '2449'],
            'index bare'      => ['https://myforum.com/forum', LocalDiscussionResolver::KIND_INDEX, ''],
            'index slash'     => ['https://myforum.com/forum/', LocalDiscussionResolver::KIND_INDEX, ''],
            'tag'             => ['https://myforum.com/forum/t/pianos', LocalDiscussionResolver::KIND_TAG, 'pianos'],
            'tag trailing'    => ['https://myforum.com/forum/t/pianos/', LocalDiscussionResolver::KIND_TAG, 'pianos'],
            // A child tag is addressed by its own slug, so the last segment wins.
            'nested tag'      => ['https://myforum.com/forum/t/parent/child', LocalDiscussionResolver::KIND_TAG, 'child'],
            'user profile'    => ['https://myforum.com/forum/u/someone', LocalDiscussionResolver::KIND_OTHER, ''],
            'outside mount'   => ['https://myforum.com/671b925b', LocalDiscussionResolver::KIND_OTHER, ''],
        ];
    }

    public function test_self_path_is_null_for_foreign_hosts(): void
    {
        $r = new LocalDiscussionResolver('https://myforum.com/forum', new InMemorySettings());
        $this->assertNull($r->parseSelfPath('https://example.com/forum/d/1'));
    }

    /** parseSelfLink() keeps its old contract: discussions only. */
    public function test_parse_self_link_still_only_matches_discussions(): void
    {
        $r = new LocalDiscussionResolver('https://myforum.com/forum', new InMemorySettings());

        $this->assertSame(2449, $r->parseSelfLink('https://myforum.com/forum/d/2449'));
        $this->assertNull($r->parseSelfLink('https://myforum.com/forum/t/pianos'));
        $this->assertNull($r->parseSelfLink('https://myforum.com/forum/'));
    }
}
