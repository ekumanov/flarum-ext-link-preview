<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Icon;

use Ekumanov\LinkPreview\Icon\IconStore;
use Ekumanov\LinkPreview\Tests\Support\TempDisk;
use PHPUnit\Framework\TestCase;

/**
 * These bytes arrive from an attacker-influenceable URL and are about to be
 * served from the forum's own origin, so what this class refuses matters more
 * than what it accepts.
 */
final class IconStoreTest extends TestCase
{
    private string $root;
    private IconStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/lp-store-'.bin2hex(random_bytes(6));
        $this->store = new IconStore(TempDisk::make($this->root));
    }

    protected function tearDown(): void
    {
        TempDisk::cleanup($this->root);
    }

    // ─── what we refuse ───────────────────────────────────────────────

    public function test_refuses_svg(): void
    {
        // The whole reason SVG is excluded: served from our origin, this runs.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

        $this->assertNull($this->store->store($svg));
        $this->assertNull(IconStore::identify($svg));
    }

    public function test_refuses_html_dressed_as_an_icon(): void
    {
        $this->assertNull($this->store->store('<!doctype html><html><body>hi</body></html>'));
    }

    public function test_refuses_a_php_payload(): void
    {
        // One production row's icon href ends in `.php`. The URL never decides
        // what we store — the bytes do.
        $this->assertNull($this->store->store('<?php system($_GET["c"]); ?>'.str_repeat(' ', 40)));
    }

    public function test_refuses_a_cursor_masquerading_as_an_icon(): void
    {
        // ICO type 2 is a cursor, not an icon.
        $this->assertNull($this->store->store("\x00\x00\x02\x00".str_repeat("\x00", 40)));
    }

    public function test_refuses_a_body_too_short_to_be_an_image(): void
    {
        $this->assertNull($this->store->store("\x89PNG\r\n\x1a\n"));
    }

    public function test_refuses_an_empty_body(): void
    {
        $this->assertNull($this->store->store(''));
    }

    // ─── what we accept ───────────────────────────────────────────────

    /**
     * @dataProvider acceptedFormats
     */
    public function test_accepts_and_labels_a_real_image(string $bytes, string $expectedExt): void
    {
        $name = $this->store->store($bytes);

        $this->assertNotNull($name);
        $this->assertStringEndsWith('.'.$expectedExt, $name);
        $this->assertTrue($this->store->exists($name));
    }

    public static function acceptedFormats(): array
    {
        $pad = str_repeat("\x00", 40);

        return [
            'png' => ["\x89PNG\r\n\x1a\n".$pad, 'png'],
            'gif87' => ['GIF87a'.$pad, 'gif'],
            'gif89' => ['GIF89a'.$pad, 'gif'],
            'jpeg' => ["\xFF\xD8\xFF\xE0".$pad, 'jpg'],
            'ico' => ["\x00\x00\x01\x00".$pad, 'ico'],
            'webp' => ['RIFF'."\x00\x00\x00\x00".'WEBP'.$pad, 'webp'],
        ];
    }

    public function test_the_extension_comes_from_the_bytes_not_the_source(): void
    {
        // A PNG served from `/evil.php` is still stored as a .png.
        $name = $this->store->store("\x89PNG\r\n\x1a\n".str_repeat("\x00", 40));

        $this->assertStringEndsWith('.png', $name);
    }

    public function test_the_filename_is_content_addressed_and_carries_nothing_remote(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n".str_repeat("\x00", 40);

        $name = $this->store->store($bytes);

        $this->assertSame(hash('sha256', $bytes).'.png', $name);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\.png$/', $name);
    }

    public function test_identical_bytes_collapse_onto_one_file(): void
    {
        $bytes = 'GIF89a'.str_repeat("\x01", 40);

        $a = $this->store->store($bytes);
        $b = $this->store->store($bytes);

        $this->assertSame($a, $b);
        $this->assertCount(1, $this->store->all());
    }

    public function test_different_bytes_get_different_files(): void
    {
        $this->store->store("\x89PNG\r\n\x1a\n".str_repeat("\x01", 40));
        $this->store->store("\x89PNG\r\n\x1a\n".str_repeat("\x02", 40));

        $this->assertCount(2, $this->store->all());
    }

    // ─── lifecycle ────────────────────────────────────────────────────

    public function test_url_is_built_from_the_disk(): void
    {
        $name = $this->store->store("\x89PNG\r\n\x1a\n".str_repeat("\x00", 40));

        $this->assertSame('https://forum.test/assets/icons/'.$name, $this->store->url($name));
    }

    public function test_delete_removes_the_file(): void
    {
        $name = $this->store->store("\x89PNG\r\n\x1a\n".str_repeat("\x00", 40));

        $this->store->delete($name);

        $this->assertFalse($this->store->exists($name));
        $this->assertSame([], $this->store->all());
    }

    public function test_a_store_with_no_disk_degrades_instead_of_throwing(): void
    {
        // What the service provider hands back when the assets directory is
        // read-only: cards fall back to hot-linking rather than 500ing.
        $store = new IconStore();

        $this->assertFalse($store->isAvailable());
        $this->assertNull($store->store("\x89PNG\r\n\x1a\n".str_repeat("\x00", 40)));
        $this->assertNull($store->url('anything.png'));
        $this->assertFalse($store->exists('anything.png'));
        $this->assertSame([], $store->all());
        $store->delete('anything.png'); // must not throw
    }
}
