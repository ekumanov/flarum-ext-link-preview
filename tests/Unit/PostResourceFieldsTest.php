<?php

namespace Ekumanov\LinkPreview\Tests\Unit;

use Ekumanov\LinkPreview\Parser\IconPicker;
use Ekumanov\LinkPreview\PostResourceFields;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\Settings\SettingsRepository;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the card payload's new `favicon` key. buildPreview() is exercised
 * directly — the surrounding buildPreviews() needs an Api\Context and a loaded
 * relation, neither of which says anything about the field under test.
 */
final class PostResourceFieldsTest extends TestCase
{
    public function test_payload_carries_a_favicon_when_a_candidate_exists(): void
    {
        $payload = $this->build($this->preview(icons: [
            ['href' => '/favicon-32x32.png', 'type' => 'image/png', 'sizes' => [['width' => 32, 'height' => 32]]],
        ]));

        $this->assertSame('https://example.com/favicon-32x32.png', $payload['favicon']);
    }

    public function test_favicon_is_null_when_the_row_stored_no_icons(): void
    {
        $this->assertNull($this->build($this->preview(icons: null))['favicon']);
        $this->assertNull($this->build($this->preview(icons: []))['favicon']);
    }

    public function test_favicon_is_null_when_the_setting_is_off(): void
    {
        $payload = $this->build(
            $this->preview(icons: [['href' => '/favicon.ico']]),
            ['ekumanov-link-preview.show_favicons' => '0']
        );

        $this->assertNull($payload['favicon'], 'off must remove the URL from the payload, not merely hide it');
    }

    public function test_a_self_link_brand_image_becomes_the_site_mark(): void
    {
        // A self-link carries a brand image and no stored icons. The logo goes
        // in the 18px slot; the big slot stays empty rather than showing a
        // magnified logo.
        $preview = $this->preview(icons: null);
        $preview->opengraph = [
            'title' => 'A discussion',
            'images' => [['url' => 'https://example.com/logo.png', 'brand' => true]],
        ];

        $payload = $this->build($preview);

        $this->assertNull($payload['image'], 'a brand mark must not fill the thumbnail slot');
        $this->assertSame('https://example.com/logo.png', $payload['favicon']);
    }

    public function test_an_og_image_that_is_the_favicon_is_treated_as_a_brand_mark(): void
    {
        // Measured live (sympiano.com): the site declares one logo as both its
        // og:image and its favicon. Filling the card's banner with it produced
        // a giant cropped letter on mobile.
        $preview = $this->preview(icons: [['href' => 'https://example.com/logo-512.png']]);
        $preview->opengraph = [
            'title' => 'An article',
            'images' => [['url' => 'https://example.com/logo-512.png']],
        ];

        $payload = $this->build($preview);

        $this->assertNull($payload['image'], 'the logo must not be blown up into the banner');
        $this->assertSame('https://example.com/logo-512.png', $payload['favicon']);
    }

    public function test_a_real_thumbnail_and_a_favicon_coexist(): void
    {
        $preview = $this->preview(icons: [['href' => '/favicon.ico']]);
        $preview->opengraph = [
            'title' => 'An article',
            'images' => [['url' => 'https://example.com/hero.jpg']],
        ];

        $payload = $this->build($preview);

        $this->assertSame('https://example.com/hero.jpg', $payload['image']);
        $this->assertSame('https://example.com/favicon.ico', $payload['favicon']);
    }

    public function test_an_icon_measured_over_the_ceiling_is_not_served(): void
    {
        $payload = $this->build($this->preview(icons: [
            ['href' => '/huge.png', 'bytes' => 291728],
        ]));

        $this->assertNull($payload['favicon']);
    }

    public function test_an_icon_measured_under_the_ceiling_is_served(): void
    {
        $payload = $this->build($this->preview(icons: [
            ['href' => '/small.png', 'bytes' => 4000],
        ]));

        $this->assertSame('https://example.com/small.png', $payload['favicon']);
    }

    public function test_an_unmeasured_icon_is_still_served(): void
    {
        // Rows stored before measurement existed must not lose their marks.
        $payload = $this->build($this->preview(icons: [['href' => '/legacy.ico']]));

        $this->assertSame('https://example.com/legacy.ico', $payload['favicon']);
    }

    public function test_relative_icons_resolve_against_final_url(): void
    {
        $preview = $this->preview(icons: [['href' => '/favicon.ico']]);
        $preview->url = 'http://example.com/old-address';
        $preview->final_url = 'https://www.example.net/moved';

        $this->assertSame('https://www.example.net/favicon.ico', $this->build($preview)['favicon']);
    }

    /**
     * @param array<string,string> $settings
     * @return array<string,mixed>
     */
    private function build(Preview $preview, array $settings = []): array
    {
        $fields = new PostResourceFields(new IconPicker(), new SettingsRepository($this->settings($settings)));

        $post = new Post();
        $post->id = 7;

        $method = new ReflectionMethod($fields, 'buildPreview');

        return $method->invoke($fields, $preview, $post, false);
    }

    private function preview(mixed $icons): Preview
    {
        $preview = new Preview();
        $preview->id = 1;
        $preview->url = 'https://example.com/article';
        $preview->final_url = 'https://example.com/article';
        $preview->retrieved_at = '2026-09-01 00:00:00';
        $preview->http_status = 200;
        $preview->fallback = ['title' => 'An article', 'description' => null];
        $preview->icons = $icons;

        return $preview;
    }

    /** @param array<string,string> $values */
    private function settings(array $values): SettingsRepositoryInterface
    {
        return new class($values) implements SettingsRepositoryInterface {
            public function __construct(private array $values) {}

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
                unset($this->values[$keyLike]);
            }
        };
    }
}
