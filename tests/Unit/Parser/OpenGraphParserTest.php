<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Parser;

use Ekumanov\LinkPreview\Parser\OpenGraphParser;
use PHPUnit\Framework\TestCase;

final class OpenGraphParserTest extends TestCase
{
    private OpenGraphParser $p;

    protected function setUp(): void
    {
        $this->p = new OpenGraphParser();
    }

    public function test_returns_null_for_empty_html(): void
    {
        $this->assertNull($this->p->parse(''));
        $this->assertNull($this->p->parse('   '));
    }

    public function test_returns_null_when_no_og_or_twitter_tags(): void
    {
        $html = '<html><head><title>Plain page</title></head><body>x</body></html>';
        $this->assertNull($this->p->parse($html));
    }

    public function test_parses_basic_og_fields(): void
    {
        $html = <<<'HTML'
<html><head>
<meta property="og:title" content="Hello">
<meta property="og:description" content="Page description">
<meta property="og:site_name" content="Example">
<meta property="og:url" content="https://example.com/page">
<meta property="og:type" content="article">
</head><body></body></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertNotNull($r);
        $this->assertSame('Hello', $r['title']);
        $this->assertSame('Page description', $r['description']);
        $this->assertSame('Example', $r['site_name']);
        $this->assertSame('https://example.com/page', $r['url']);
        $this->assertSame('article', $r['type']);
        $this->assertSame([], $r['images']);
    }

    public function test_parses_single_image_with_subproperties(): void
    {
        $html = <<<'HTML'
<html><head>
<meta property="og:title" content="t">
<meta property="og:image" content="https://example.com/img.jpg">
<meta property="og:image:secure_url" content="https://example.com/img.jpg">
<meta property="og:image:width" content="800">
<meta property="og:image:height" content="600">
<meta property="og:image:alt" content="alt text">
<meta property="og:image:type" content="image/jpeg">
</head></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertCount(1, $r['images']);
        $img = $r['images'][0];
        $this->assertSame('https://example.com/img.jpg', $img['url']);
        $this->assertSame('https://example.com/img.jpg', $img['secure_url']);
        $this->assertSame(800, $img['width']);
        $this->assertSame(600, $img['height']);
        $this->assertSame('alt text', $img['alt']);
        $this->assertSame('image/jpeg', $img['type']);
    }

    public function test_parses_multiple_images_groups_subproperties(): void
    {
        // Each og:image starts a new group; following sub-properties attach
        // to that group until the next og:image.
        $html = <<<'HTML'
<html><head>
<meta property="og:image" content="https://example.com/a.jpg">
<meta property="og:image:width" content="800">
<meta property="og:image:height" content="600">
<meta property="og:image" content="https://example.com/b.jpg">
<meta property="og:image:width" content="400">
</head></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertCount(2, $r['images']);
        $this->assertSame('https://example.com/a.jpg', $r['images'][0]['url']);
        $this->assertSame(800, $r['images'][0]['width']);
        $this->assertSame(600, $r['images'][0]['height']);
        $this->assertSame('https://example.com/b.jpg', $r['images'][1]['url']);
        $this->assertSame(400, $r['images'][1]['width']);
        $this->assertArrayNotHasKey('height', $r['images'][1]);
    }

    public function test_twitter_fallbacks_when_og_missing(): void
    {
        $html = <<<'HTML'
<html><head>
<meta name="twitter:title" content="Tweet title">
<meta name="twitter:description" content="Tweet desc">
<meta name="twitter:image" content="https://example.com/t.jpg">
<meta name="twitter:image:alt" content="Tweet image">
<meta name="twitter:site" content="@example">
</head></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertSame('Tweet title', $r['title']);
        $this->assertSame('Tweet desc', $r['description']);
        $this->assertSame('@example', $r['site_name']);
        $this->assertCount(1, $r['images']);
        $this->assertSame('https://example.com/t.jpg', $r['images'][0]['url']);
        $this->assertSame('Tweet image', $r['images'][0]['alt']);
    }

    public function test_og_wins_over_twitter_when_both_present(): void
    {
        $html = <<<'HTML'
<html><head>
<meta property="og:title" content="OG title">
<meta name="twitter:title" content="Tweet title">
</head></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertSame('OG title', $r['title']);
    }

    public function test_utf8_is_preserved(): void
    {
        $html = <<<'HTML'
<html><head>
<meta property="og:title" content="Café — ñoño 日本語 🎹">
</head></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertSame('Café — ñoño 日本語 🎹', $r['title']);
    }

    public function test_ignores_property_outside_head(): void
    {
        // Defensive: a stray <meta property="og:title"> in <body> shouldn't
        // be picked up. (Real sites do this with sub-articles.)
        $html = <<<'HTML'
<html><head><meta property="og:title" content="real"></head>
<body><meta property="og:title" content="fake from body"></body></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertSame('real', $r['title']);
    }

    public function test_empty_content_attribute_ignored(): void
    {
        $html = <<<'HTML'
<html><head>
<meta property="og:title" content="">
<meta property="og:description" content="actual desc">
</head></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertNull($r['title']);
        $this->assertSame('actual desc', $r['description']);
    }

    public function test_first_value_wins_for_duplicate_keys(): void
    {
        $html = <<<'HTML'
<html><head>
<meta property="og:title" content="first">
<meta property="og:title" content="second">
</head></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertSame('first', $r['title']);
    }

    public function test_malformed_html_does_not_crash(): void
    {
        $html = '<html><head><meta property="og:title" content="t"><body><p>unclosed</body></html>';
        $r = $this->p->parse($html);
        $this->assertNotNull($r);
        $this->assertSame('t', $r['title']);
    }
}
