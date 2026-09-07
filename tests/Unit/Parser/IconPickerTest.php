<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Parser;

use Ekumanov\LinkPreview\Parser\IconPicker;
use PHPUnit\Framework\TestCase;

/**
 * Table-driven against the icon shapes actually present in the production
 * `ekumanov_link_previews.icons` column (4252 rows sampled 2026-09-07):
 * 4178 absolute hrefs, 258 relative, 23 protocol-relative, 63 http://.
 */
final class IconPickerTest extends TestCase
{
    private IconPicker $picker;

    protected function setUp(): void
    {
        $this->picker = new IconPicker();
    }

    // ─── empty / malformed input ──────────────────────────────────────

    public function test_returns_null_for_empty_icons(): void
    {
        $this->assertNull($this->picker->pick([], 'https://example.com/page'));
    }

    public function test_returns_null_for_null_icons(): void
    {
        $this->assertNull($this->picker->pick(null, 'https://example.com/page'));
    }

    public function test_returns_null_for_scalar_icons_column(): void
    {
        // A legacy row whose JSON decoded to something that isn't a list.
        $this->assertNull($this->picker->pick('favicon.ico', 'https://example.com/'));
    }

    public function test_skips_entries_that_are_not_arrays_or_have_no_href(): void
    {
        $icons = ['garbage', ['type' => 'image/png'], ['href' => '   '], ['href' => '/ok.png']];

        $this->assertSame('https://example.com/ok.png', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_returns_null_when_final_url_has_no_host(): void
    {
        $this->assertNull($this->picker->pick([['href' => '/favicon.ico']], 'not a url'));
    }

    // ─── mask icons ───────────────────────────────────────────────────

    public function test_skips_mask_icon_by_rel(): void
    {
        $icons = [
            ['href' => '/mono.svg', 'rel' => 'mask-icon'],
            ['href' => '/favicon.ico'],
        ];

        $this->assertSame('https://example.com/favicon.ico', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_skips_safari_pinned_tab_by_filename(): void
    {
        // Rows written before the parser stored `rel` have only the filename
        // to go on — every one of them uses Apple's conventional name.
        $icons = [
            ['href' => '/safari-pinned-tab.svg'],
            ['href' => '/favicon.ico'],
        ];

        $this->assertSame('https://example.com/favicon.ico', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_returns_null_when_the_only_icon_is_a_mask(): void
    {
        $this->assertNull($this->picker->pick([['href' => '/safari-pinned-tab.svg']], 'https://example.com/'));
    }

    // ─── size preference ──────────────────────────────────────────────

    public function test_prefers_32px_over_16px_and_180px(): void
    {
        $icons = [
            ['href' => '/apple-touch-icon.png', 'sizes' => [['width' => 180, 'height' => 180]]],
            ['href' => '/favicon-16x16.png', 'type' => 'image/png', 'sizes' => [['width' => 16, 'height' => 16]]],
            ['href' => '/favicon-32x32.png', 'type' => 'image/png', 'sizes' => [['width' => 32, 'height' => 32]]],
        ];

        $this->assertSame('https://example.com/favicon-32x32.png', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_apple_touch_icon_beats_a_16px_icon(): void
    {
        // Oversized only needs downscaling; undersized is visibly blurry at 2x.
        $icons = [
            ['href' => '/favicon-16x16.png', 'type' => 'image/png', 'sizes' => [['width' => 16, 'height' => 16]]],
            ['href' => '/apple-touch-icon.png', 'type' => 'image/png', 'sizes' => [['width' => 180, 'height' => 180]]],
        ];

        $this->assertSame('https://example.com/apple-touch-icon.png', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_reads_a_size_out_of_the_filename_when_sizes_is_absent(): void
    {
        $icons = [
            ['href' => '/favicon-16x16.png', 'type' => 'image/png'],
            ['href' => '/favicon-48x48.png', 'type' => 'image/png'],
        ];

        $this->assertSame('https://example.com/favicon-48x48.png', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_declared_size_wins_over_an_undeclared_one(): void
    {
        $icons = [
            ['href' => '/favicon.ico'],
            ['href' => '/icon.png', 'type' => 'image/png', 'sizes' => [['width' => 48, 'height' => 48]]],
        ];

        $this->assertSame('https://example.com/icon.png', $this->picker->pick($icons, 'https://example.com/'));
    }

    // ─── type preference ──────────────────────────────────────────────

    public function test_prefers_png_over_ico_at_the_same_size(): void
    {
        $icons = [
            ['href' => '/favicon.ico', 'type' => 'image/x-icon', 'sizes' => [['width' => 32, 'height' => 32]]],
            ['href' => '/favicon.png', 'type' => 'image/png', 'sizes' => [['width' => 32, 'height' => 32]]],
        ];

        $this->assertSame('https://example.com/favicon.png', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_prefers_svg_over_ico_when_neither_declares_a_size(): void
    {
        $icons = [
            ['href' => '/favicon.ico'],
            ['href' => '/icon.svg', 'type' => 'image/svg+xml'],
        ];

        $this->assertSame('https://example.com/icon.svg', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_infers_type_from_the_extension_when_none_is_declared(): void
    {
        $icons = [
            ['href' => '/favicon.ico'],
            ['href' => '/favicon.png'],
        ];

        $this->assertSame('https://example.com/favicon.png', $this->picker->pick($icons, 'https://example.com/'));
    }

    // ─── absolutization ───────────────────────────────────────────────

    public function test_resolves_root_relative_href_against_final_url(): void
    {
        $icons = [['href' => '/favicon.ico']];

        $this->assertSame(
            'https://cdn.example.com/favicon.ico',
            $this->picker->pick($icons, 'https://cdn.example.com/deep/page.html?x=1#frag')
        );
    }

    public function test_resolves_document_relative_href_against_the_final_url_directory(): void
    {
        $icons = [['href' => 'favicon.ico']];

        $this->assertSame(
            'https://example.com/blog/favicon.ico',
            $this->picker->pick($icons, 'https://example.com/blog/post.html')
        );
    }

    public function test_resolves_protocol_relative_href(): void
    {
        $icons = [['href' => '//cdn.other.net/icon.png']];

        $this->assertSame('https://cdn.other.net/icon.png', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_keeps_the_port_of_the_final_url(): void
    {
        $icons = [['href' => '/favicon.ico']];

        $this->assertSame('https://example.com:8443/favicon.ico', $this->picker->pick($icons, 'https://example.com:8443/x'));
    }

    public function test_absolute_href_is_returned_unchanged(): void
    {
        // A 2022-era production row: one absolute href, nothing else declared.
        $icons = [['href' => 'https://www.nordkeyboards.com/sites/all/themes/nordkeyboards/favicon.ico']];

        $this->assertSame(
            'https://www.nordkeyboards.com/sites/all/themes/nordkeyboards/favicon.ico',
            $this->picker->pick($icons, 'https://www.nordkeyboards.com/anything')
        );
    }

    public function test_resolves_relative_href_against_final_url_not_og_url(): void
    {
        // The Nord row's og:url claims http:// while the page really is https.
        // final_url is the only trustworthy base.
        $icons = [['href' => '/favicon-32x32.png', 'type' => 'image/png', 'sizes' => [['width' => 32, 'height' => 32]]]];

        $this->assertSame(
            'https://www.nordkeyboards.com/favicon-32x32.png',
            $this->picker->pick($icons, 'https://www.nordkeyboards.com/sounds/sample-library/?page=2')
        );
    }

    // ─── scheme safety ────────────────────────────────────────────────

    public function test_rejects_data_uri_href(): void
    {
        $this->assertNull($this->picker->pick([['href' => 'data:image/png;base64,AAAA']], 'https://example.com/'));
    }

    public function test_rejects_javascript_href(): void
    {
        $this->assertNull($this->picker->pick([['href' => 'javascript:alert(1)']], 'https://example.com/'));
    }

    public function test_upgrades_a_lone_http_href_to_https(): void
    {
        $icons = [['href' => 'http://example.com/favicon.ico']];

        $this->assertSame('https://example.com/favicon.ico', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_prefers_an_https_sibling_over_an_http_one(): void
    {
        // Even when the http candidate is the better size — an upgraded URL
        // may not answer on 443, and we have a sibling that certainly does.
        $icons = [
            ['href' => 'http://example.com/favicon-32x32.png', 'type' => 'image/png', 'sizes' => [['width' => 32, 'height' => 32]]],
            ['href' => 'https://example.com/favicon.ico'],
        ];

        $this->assertSame('https://example.com/favicon.ico', $this->picker->pick($icons, 'https://example.com/'));
    }

    public function test_relative_href_on_an_http_page_is_upgraded(): void
    {
        $icons = [['href' => '/favicon.ico']];

        $this->assertSame('https://legacy.example.com/favicon.ico', $this->picker->pick($icons, 'http://legacy.example.com/'));
    }

    // ─── the full production shape ────────────────────────────────────

    public function test_picks_the_32px_png_out_of_the_nordkeyboards_row(): void
    {
        $icons = json_decode(<<<'JSON'
        [{"href": "/favicon.ico"},
         {"href": "/apple-touch-icon.png", "sizes": [{"width":180,"height":180}]},
         {"href": "/favicon-32x32.png", "type": "image/png", "sizes": [{"width":32,"height":32}]},
         {"href": "/favicon-16x16.png", "type": "image/png", "sizes": [{"width":16,"height":16}]},
         {"href": "/safari-pinned-tab.svg"}]
        JSON, true);

        $this->assertSame(
            'https://www.nordkeyboards.com/favicon-32x32.png',
            $this->picker->pick($icons, 'https://www.nordkeyboards.com/sounds/sample-library/?instrument=piano')
        );
    }

    public function test_a_probed_icon_is_picked_like_any_other(): void
    {
        // Phase 3 writes {"href": "...", "probed": true}; the extra key must
        // not change the ranking or trip the shape checks.
        $icons = [['href' => 'https://example.com/favicon.ico', 'probed' => true]];

        $this->assertSame('https://example.com/favicon.ico', $this->picker->pick($icons, 'https://example.com/'));
    }
}
