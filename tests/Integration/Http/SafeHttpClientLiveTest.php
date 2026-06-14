<?php

namespace Ekumanov\LinkPreview\Tests\Integration\Http;

use Ekumanov\LinkPreview\Http\CurlRequestExecutor;
use Ekumanov\LinkPreview\Http\DefaultIpFilter;
use Ekumanov\LinkPreview\Http\DnsResolver;
use Ekumanov\LinkPreview\Http\SafeHttpClient;
use Ekumanov\LinkPreview\Http\UrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Hits the real internet (example.com — IANA-reserved, very stable). Skips
 * if outbound DNS fails so the suite doesn't break in airplane mode.
 *
 * Run with: vendor/bin/phpunit --testsuite=integration
 */
final class SafeHttpClientLiveTest extends TestCase
{
    private SafeHttpClient $client;

    protected function setUp(): void
    {
        if (! function_exists('dns_get_record') || @dns_get_record('example.com', DNS_A) === false) {
            $this->markTestSkipped('No outbound DNS; live test skipped.');
        }

        $this->client = new SafeHttpClient(
            urlValidator: new UrlValidator(),
            resolver: new DnsResolver(),
            ipFilter: new DefaultIpFilter(),
            executor: new CurlRequestExecutor(
                connectTimeoutSec: 5,
                totalTimeoutSec: 10,
                maxBytes: 2 * 1024 * 1024,
            ),
        );
    }

    public function test_fetches_example_dot_com(): void
    {
        $r = $this->client->get('https://example.com/');
        $this->assertTrue($r['ok'], 'expected success, got: '.json_encode($r));
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('text/html', $r['contentType']);
        $this->assertStringContainsString('Example Domain', $r['body']);
    }

    public function test_real_loopback_url_blocked_by_ssrf(): void
    {
        $r = $this->client->get('http://127.0.0.1/');
        $this->assertFalse($r['ok']);
        $this->assertSame(SafeHttpClient::REASON_SSRF, $r['reason']);
    }

    public function test_real_aws_metadata_blocked_by_ssrf(): void
    {
        $r = $this->client->get('http://169.254.169.254/latest/meta-data/');
        $this->assertFalse($r['ok']);
        $this->assertSame(SafeHttpClient::REASON_SSRF, $r['reason']);
    }

    public function test_localhost_hostname_blocked_by_ssrf(): void
    {
        $r = $this->client->get('http://localhost/');
        $this->assertFalse($r['ok']);
        $this->assertSame(SafeHttpClient::REASON_SSRF, $r['reason']);
    }
}
