<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Http;

use Ekumanov\LinkPreview\Http\DefaultIpFilter;
use Ekumanov\LinkPreview\Http\ExecutorResult;
use Ekumanov\LinkPreview\Http\SafeHttpClient;
use Ekumanov\LinkPreview\Http\UrlValidator;
use Ekumanov\LinkPreview\Tests\Support\FakeExecutor;
use Ekumanov\LinkPreview\Tests\Support\SpyResolver;
use PHPUnit\Framework\TestCase;

final class SafeHttpClientTest extends TestCase
{
    public function test_rejects_invalid_scheme_before_resolving(): void
    {
        $resolver = new SpyResolver();
        $executor = new FakeExecutor();
        $client = $this->client($resolver, $executor);

        $r = $client->get('ftp://example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame(UrlValidator::REASON_BAD_SCHEME, $r['reason']);
        $this->assertSame([], $resolver->calls, 'resolver must not be called for bad scheme');
        $this->assertSame([], $executor->calls, 'executor must not be called for bad scheme');
    }

    public function test_rejects_userinfo_url(): void
    {
        $resolver = new SpyResolver();
        $executor = new FakeExecutor();
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://user:pw@example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame(UrlValidator::REASON_HAS_USERINFO, $r['reason']);
        $this->assertSame([], $executor->calls);
    }

    public function test_dns_failure_short_circuits(): void
    {
        $resolver = new SpyResolver(answers: []);   // no answer for example.com
        $executor = new FakeExecutor();
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame(SafeHttpClient::REASON_DNS_FAILED, $r['reason']);
        $this->assertSame(['example.com'], $resolver->calls);
        $this->assertSame([], $executor->calls);
    }

    public function test_private_ip_blocks_request(): void
    {
        $resolver = new SpyResolver(['internal.example.com' => ['10.0.0.5']]);
        $executor = new FakeExecutor();
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://internal.example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame(SafeHttpClient::REASON_SSRF, $r['reason']);
        $this->assertStringContainsString('10.0.0.5', $r['detail']);
        $this->assertSame([], $executor->calls, 'must not connect when any resolved IP is private');
    }

    public function test_mixed_public_private_resolution_is_blocked(): void
    {
        // Classic DNS-rebind setup: round-robin returning a public IP and a
        // private IP. We refuse the host entirely so a later cache hit on the
        // private one can't catch us out.
        $resolver = new SpyResolver(['rebind.example.com' => ['8.8.8.8', '169.254.169.254']]);
        $executor = new FakeExecutor();
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://rebind.example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame(SafeHttpClient::REASON_SSRF, $r['reason']);
    }

    public function test_v4_mapped_ipv6_loopback_blocked(): void
    {
        $resolver = new SpyResolver(['sneaky.example.com' => ['::ffff:127.0.0.1']]);
        $executor = new FakeExecutor();
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://sneaky.example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame(SafeHttpClient::REASON_SSRF, $r['reason']);
    }

    public function test_ip_literal_url_runs_through_filter(): void
    {
        // The Resolver short-circuits IP literals — but the IpFilter must
        // still run on them.
        $resolver = new SpyResolver(['127.0.0.1' => ['127.0.0.1']]);
        $executor = new FakeExecutor();
        $client = $this->client($resolver, $executor);

        $r = $client->get('http://127.0.0.1/');
        $this->assertFalse($r['ok']);
        $this->assertSame(SafeHttpClient::REASON_SSRF, $r['reason']);
    }

    public function test_successful_fetch_returns_body_and_headers(): void
    {
        $resolver = new SpyResolver(['example.com' => ['93.184.216.34']]);
        $executor = new FakeExecutor([
            'https://example.com/page' => ExecutorResult::ok(
                200,
                ['content-type' => 'text/html; charset=utf-8'],
                '<html><head><title>x</title></head></html>'
            ),
        ]);
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://example.com/page');
        $this->assertTrue($r['ok']);
        $this->assertSame(200, $r['status']);
        $this->assertSame('text/html; charset=utf-8', $r['contentType']);
        $this->assertStringContainsString('<title>x</title>', $r['body']);
        $this->assertCount(1, $executor->calls);
        $this->assertSame('93.184.216.34', $executor->calls[0]['ip']);
        $this->assertSame(443, $executor->calls[0]['port']);
    }

    public function test_redirect_to_public_is_followed_and_revalidated(): void
    {
        $resolver = new SpyResolver([
            'first.example.com' => ['93.184.216.34'],
            'second.example.com' => ['93.184.216.35'],
        ]);
        $executor = new FakeExecutor([
            'https://first.example.com/' => ExecutorResult::ok(
                301,
                ['location' => 'https://second.example.com/landing'],
                ''
            ),
            'https://second.example.com/landing' => ExecutorResult::ok(
                200,
                ['content-type' => 'text/html'],
                'final'
            ),
        ]);
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://first.example.com/');
        $this->assertTrue($r['ok']);
        $this->assertSame('final', $r['body']);
        $this->assertSame('https://second.example.com/landing', $r['finalUrl']);
        $this->assertSame(['first.example.com', 'second.example.com'], $resolver->calls,
            'both hops must be re-resolved (redirect is not trusted)');
        $this->assertCount(2, $executor->calls);
    }

    public function test_redirect_to_private_ip_is_blocked(): void
    {
        // 302 from a legit-looking site pointing at an internal host. The
        // re-resolve catches it.
        $resolver = new SpyResolver([
            'public.example.com' => ['93.184.216.34'],
            'admin.internal'    => ['10.0.0.5'],
        ]);
        $executor = new FakeExecutor([
            'https://public.example.com/' => ExecutorResult::ok(
                302,
                ['location' => 'https://admin.internal/'],
                ''
            ),
        ]);
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://public.example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame(SafeHttpClient::REASON_SSRF, $r['reason']);
        $this->assertSame(['public.example.com', 'admin.internal'], $resolver->calls);
        $this->assertCount(1, $executor->calls, 'second hop must be rejected before its request');
    }

    public function test_redirect_to_bad_scheme_is_blocked(): void
    {
        $resolver = new SpyResolver(['public.example.com' => ['93.184.216.34']]);
        $executor = new FakeExecutor([
            'https://public.example.com/' => ExecutorResult::ok(
                302,
                ['location' => 'file:///etc/passwd'],
                ''
            ),
        ]);
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://public.example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame(UrlValidator::REASON_BAD_SCHEME, $r['reason']);
        $this->assertCount(1, $executor->calls);
    }

    public function test_relative_redirect_resolves_against_base(): void
    {
        $resolver = new SpyResolver(['example.com' => ['93.184.216.34']]);
        $executor = new FakeExecutor([
            'https://example.com/a/b/c' => ExecutorResult::ok(301, ['location' => '/x'], ''),
            'https://example.com/x'     => ExecutorResult::ok(200, ['content-type' => 'text/html'], 'ok'),
        ]);
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://example.com/a/b/c');
        $this->assertTrue($r['ok']);
        $this->assertSame('https://example.com/x', $r['finalUrl']);
    }

    public function test_redirect_loop_terminates_with_too_many_redirects(): void
    {
        $resolver = new SpyResolver(['loop.example.com' => ['93.184.216.34']]);
        $executor = new FakeExecutor([
            'https://loop.example.com/' => ExecutorResult::ok(
                302,
                ['location' => 'https://loop.example.com/'],
                ''
            ),
        ]);
        $client = $this->client($resolver, $executor, maxRedirects: 3);

        $r = $client->get('https://loop.example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame(SafeHttpClient::REASON_TOO_MANY_REDIRECTS, $r['reason']);
        $this->assertCount(4, $executor->calls, 'initial request + 3 redirects, then refuse');
    }

    public function test_executor_body_too_large_surfaces(): void
    {
        $resolver = new SpyResolver(['big.example.com' => ['93.184.216.34']]);
        $executor = new FakeExecutor([
            'https://big.example.com/' => ExecutorResult::failure(
                ExecutorResult::ERR_BODY_TOO_LARGE,
                'exceeded 2097152 bytes'
            ),
        ]);
        $client = $this->client($resolver, $executor);

        $r = $client->get('https://big.example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame(ExecutorResult::ERR_BODY_TOO_LARGE, $r['reason']);
    }

    private function client(SpyResolver $resolver, FakeExecutor $executor, int $maxRedirects = 5): SafeHttpClient
    {
        return new SafeHttpClient(
            urlValidator: new UrlValidator(),
            resolver: $resolver,
            ipFilter: new DefaultIpFilter(),
            executor: $executor,
            maxRedirects: $maxRedirects,
        );
    }
}
