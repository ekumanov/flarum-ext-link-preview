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
}
