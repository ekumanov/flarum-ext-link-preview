<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Parser;

use Ekumanov\LinkPreview\Parser\HtmlFallbackParser;
use PHPUnit\Framework\TestCase;

final class HtmlFallbackParserTest extends TestCase
{
    private HtmlFallbackParser $p;

    protected function setUp(): void
    {
        $this->p = new HtmlFallbackParser();
    }

    public function test_empty_html_yields_empty_result(): void
    {
        $r = $this->p->parse('');
        $this->assertNull($r['fallback']);
        $this->assertSame([], $r['icons']);
    }

    public function test_extracts_title_and_description(): void
    {
        $html = <<<'HTML'
<html><head>
<title>  My Page  </title>
<meta name="description" content="A page description">
</head><body></body></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertSame(['title' => 'My Page', 'description' => 'A page description'], $r['fallback']);
    }

    public function test_handles_title_without_description(): void
    {
        $r = $this->p->parse('<html><head><title>Just title</title></head></html>');
        $this->assertSame(['title' => 'Just title', 'description' => null], $r['fallback']);
    }

    public function test_handles_description_without_title(): void
    {
        $html = '<html><head><meta name="description" content="solo"></head></html>';
        $r = $this->p->parse($html);
        $this->assertSame(['title' => null, 'description' => 'solo'], $r['fallback']);
    }

    public function test_description_meta_is_case_insensitive(): void
    {
        $html = '<html><head><title>x</title><meta name="DESCRIPTION" content="cap"></head></html>';
        $r = $this->p->parse($html);
        $this->assertSame('cap', $r['fallback']['description']);
    }

    public function test_extracts_icons_with_sizes(): void
    {
        $html = <<<'HTML'
<html><head>
<title>x</title>
<link rel="icon" type="image/png" sizes="32x32" href="https://example.com/icon-32.png">
<link rel="shortcut icon" href="https://example.com/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="https://example.com/apple-180.png">
</head></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertCount(3, $r['icons']);

        $first = $r['icons'][0];
        $this->assertSame('https://example.com/icon-32.png', $first['href']);
        $this->assertSame('image/png', $first['type']);
        $this->assertSame([['width' => 32, 'height' => 32]], $first['sizes']);

        $this->assertSame('https://example.com/favicon.ico', $r['icons'][1]['href']);
        $this->assertArrayNotHasKey('sizes', $r['icons'][1]);
    }

    public function test_icons_with_sizes_any_ignored(): void
    {
        $html = '<html><head><title>x</title><link rel="icon" sizes="any" href="https://example.com/i.svg"></head></html>';
        $r = $this->p->parse($html);
        $this->assertCount(1, $r['icons']);
        $this->assertArrayNotHasKey('sizes', $r['icons'][0]);
    }

    public function test_first_description_wins(): void
    {
        $html = <<<'HTML'
<html><head>
<title>t</title>
<meta name="description" content="first">
<meta name="description" content="second">
</head></html>
HTML;
        $r = $this->p->parse($html);
        $this->assertSame('first', $r['fallback']['description']);
    }

    public function test_utf8_preserved(): void
    {
        $r = $this->p->parse('<html><head><title>Café 日本語</title></head></html>');
        $this->assertSame('Café 日本語', $r['fallback']['title']);
    }

    public function test_no_title_no_description_no_icons(): void
    {
        $r = $this->p->parse('<html><head></head><body>x</body></html>');
        $this->assertNull($r['fallback']);
        $this->assertSame([], $r['icons']);
    }
}
