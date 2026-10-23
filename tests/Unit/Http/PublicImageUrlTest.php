<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Http;

use Ekumanov\LinkPreview\Http\PublicImageUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicImageUrlTest extends TestCase
{
    #[DataProvider('accepted')]
    public function test_accepts_https_on_a_public_host(string $url): void
    {
        $this->assertSame($url, PublicImageUrl::filter($url));
    }

    public static function accepted(): array
    {
        return [
            'plain' => ['https://www.productionvoices.com/wp-content/uploads/hero.jpg'],
            'query string' => ['https://i.ytimg.com/vi/abc/maxresdefault.jpg?v=2'],
            'public ipv4' => ['https://93.184.216.34/img.png'],
            'local-looking label mid-name' => ['https://local.example-cdn.com/img.png'],
            'non-default port' => ['https://cdn.example.org:8443/img.png'],
        ];
    }

    public function test_upgrades_plain_http_on_a_public_host(): void
    {
        $this->assertSame(
            'https://static1.squarespace.com/static/5a15de02/hero.jpg',
            PublicImageUrl::filter('http://static1.squarespace.com/static/5a15de02/hero.jpg')
        );
        $this->assertSame('https://www.Roland.com/og.png', PublicImageUrl::filter('HTTP://www.Roland.com/og.png'));
    }

    #[DataProvider('rejected')]
    public function test_rejects_what_a_reader_should_not_be_sent_to(string $url): void
    {
        $this->assertNull(PublicImageUrl::filter($url));
    }

    public static function rejected(): array
    {
        return [
            // The live case: a WordPress og:image leaking its dev hostname.
            'leaked dev host over http' => ['http://productionvoice.local/wp-content/uploads/2022/02/300-Grand-Main-Screen-Shot-Crop.jpg'],
            'leaked dev host over https' => ['https://productionvoice.local/img.jpg'],
            'localhost' => ['https://localhost/img.png'],
            // Seen live on altitude.audio: an Astro dev server in og:image.
            'localhost over http with port' => ['http://localhost:4321/meta-images/crossfeed-meta-image.png'],
            'rfc1918 over http' => ['http://10.0.0.7/img.png'],
            'ftp' => ['ftp://files.example-shop.com/img.png'],
            'localhost subdomain' => ['https://app.localhost/img.png'],
            'trailing-dot local' => ['https://nas.local./img.png'],
            'home.arpa' => ['https://printer.home.arpa/img.png'],
            'internal' => ['https://wiki.corp.internal/img.png'],
            'lan' => ['https://router.lan/img.png'],
            'single label' => ['https://intranet/img.png'],
            'loopback ipv4' => ['https://127.0.0.1/img.png'],
            'rfc1918' => ['https://192.168.1.20/img.png'],
            'loopback ipv6' => ['https://[::1]/img.png'],
            'ula ipv6' => ['https://[fd00::1]/img.png'],
            'credentials' => ['https://user:pass@cdn.example-shop.com/img.png'],
            'protocol-relative' => ['//cdn.example-shop.com/img.png'],
            'relative' => ['/uploads/img.png'],
            'data uri' => ['data:image/png;base64,AAAA'],
            'javascript' => ['javascript:alert(1)'],
            'empty' => [''],
        ];
    }
}
