<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Fetch;

use Ekumanov\LinkPreview\Fetch\IconResolver;
use Ekumanov\LinkPreview\Http\DefaultIpFilter;
use Ekumanov\LinkPreview\Http\ExecutorResult;
use Ekumanov\LinkPreview\Http\SafeHttpClient;
use Ekumanov\LinkPreview\Http\UrlValidator;
use Ekumanov\LinkPreview\Parser\IconPicker;
use Ekumanov\LinkPreview\Tests\Support\FakeExecutor;
use Ekumanov\LinkPreview\Tests\Support\SpyResolver;
use PHPUnit\Framework\TestCase;

/**
 * The cases are the ones measured on a live install: a page whose only icon is
 * a 292 KB logo, and a page that declares a heavy apple-touch-icon next to a
 * usable favicon.ico (the shape that makes the walk worth having).
 */
final class IconResolverTest extends TestCase
{
    private const MAX = 32768;

    public function test_records_the_size_of_an_icon_that_fits(): void
    {
        $r = $this->resolve(
            [['href' => '/favicon.ico']],
            ['https://example.com/favicon.ico' => $this->image(4000)],
        );

        $this->assertSame(4000, $r['icons'][0]['bytes']);
        $this->assertSame(1, $r['checks']);
        $this->assertTrue($r['changed']);
    }

    public function test_records_an_oversized_icon_so_the_picker_skips_it(): void
    {
        $r = $this->resolve(
            [['href' => '/assets/logo-512.png']],
            ['https://example.com/assets/logo-512.png' => $this->image(291728)],
        );

        $this->assertSame(291728, $r['icons'][0]['bytes']);
        $this->assertNull(
            (new IconPicker())->pick($r['icons'], 'https://example.com/', self::MAX),
            'an icon measured over the ceiling must stop being chosen'
        );
    }

    public function test_an_oversized_winner_promotes_the_next_candidate(): void
    {
        // hackaday's shape: a 49 KB apple-touch-icon declared at 192px, and a
        // 20 KB favicon.ico with no declared size.
        $icons = [
            ['href' => '/favicon.ico', 'rel' => 'shortcut icon'],
            ['href' => '/img/logo_1024x1024.png', 'rel' => 'apple-touch-icon', 'sizes' => [['width' => 192, 'height' => 192]]],
        ];

        $r = $this->resolve($icons, [
            'https://example.com/img/logo_1024x1024.png' => $this->image(48876),
            'https://example.com/favicon.ico' => $this->image(20073),
        ]);

        $this->assertSame(2, $r['checks'], 'measures the winner, then the promoted runner-up');
        $this->assertSame(
            'https://example.com/favicon.ico',
            (new IconPicker())->pick($r['icons'], 'https://example.com/', self::MAX)
        );
    }

    public function test_stops_after_max_checks(): void
    {
        $icons = [];
        $responses = [];
        for ($i = 0; $i < 6; $i++) {
            $icons[] = ['href' => "/icon$i.png", 'type' => 'image/png'];
            $responses["https://example.com/icon$i.png"] = $this->image(500000);
        }

        $r = $this->resolve($icons, $responses);

        $this->assertSame(IconResolver::MAX_CHECKS, $r['checks']);
    }

    public function test_marks_a_non_image_response_unusable(): void
    {
        $r = $this->resolve(
            [['href' => '/favicon.ico']],
            ['https://example.com/favicon.ico' => ExecutorResult::ok(200, ['content-type' => 'text/html'], '<html>')],
        );

        $this->assertTrue($r['icons'][0]['bad']);
        $this->assertNull((new IconPicker())->pick($r['icons'], 'https://example.com/', self::MAX));
    }

    public function test_marks_a_404_unusable(): void
    {
        $r = $this->resolve(
            [['href' => '/favicon.ico']],
            ['https://example.com/favicon.ico' => ExecutorResult::ok(404, ['content-type' => 'text/html'], 'no')],
        );

        $this->assertTrue($r['icons'][0]['bad']);
    }

    public function test_a_transport_failure_leaves_the_icon_alone(): void
    {
        // A timeout today must not blank the card forever — no annotation, so
        // a later run tries again.
        $r = $this->resolve([['href' => '/favicon.ico']], []);

        $this->assertArrayNotHasKey('bytes', $r['icons'][0]);
        $this->assertArrayNotHasKey('bad', $r['icons'][0]);
        $this->assertFalse($r['changed']);
    }

    public function test_does_not_re_measure_an_icon_that_already_fits(): void
    {
        $r = $this->resolve(
            [['href' => '/favicon.ico', 'bytes' => 4000]],
            ['https://example.com/favicon.ico' => $this->image(4000)],
        );

        $this->assertSame(0, $r['checks'], 'a measured, fitting icon is left alone');
        $this->assertFalse($r['changed']);
    }

    public function test_returns_empty_walk_when_nothing_is_choosable(): void
    {
        $r = $this->resolve([['href' => 'data:image/png;base64,AA']], []);

        $this->assertSame(0, $r['checks']);
    }

    private function image(int $bytes): ExecutorResult
    {
        return ExecutorResult::ok(200, ['content-type' => 'image/png'], str_repeat('x', $bytes));
    }

    /**
     * @param  list<array<string,mixed>>    $icons
     * @param  array<string, ExecutorResult> $responses
     * @return array{icons:list<array<string,mixed>>,checks:int,changed:bool}
     */
    private function resolve(array $icons, array $responses): array
    {
        $client = new SafeHttpClient(
            urlValidator: new UrlValidator(),
            resolver: new SpyResolver(['example.com' => ['93.184.216.34']]),
            ipFilter: new DefaultIpFilter(),
            executor: new FakeExecutor($responses),
        );

        return (new IconResolver($client, new IconPicker()))
            ->validate($icons, 'https://example.com/page', self::MAX);
    }
}
