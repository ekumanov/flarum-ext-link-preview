<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Http;

use Ekumanov\LinkPreview\Http\PrivateIpFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrivateIpFilterTest extends TestCase
{
    #[DataProvider('privateProvider')]
    public function test_rejects_private(string $ip, string $reason): void
    {
        $this->assertTrue(
            PrivateIpFilter::isPrivate($ip),
            "expected $ip to be classified private ($reason)"
        );
    }

    #[DataProvider('publicProvider')]
    public function test_accepts_public(string $ip): void
    {
        $this->assertFalse(
            PrivateIpFilter::isPrivate($ip),
            "expected $ip to be classified public"
        );
    }

    public static function privateProvider(): array
    {
        return [
            // IPv4 private (RFC1918)
            'rfc1918-10/8'          => ['10.0.0.1', 'RFC1918 10/8'],
            'rfc1918-10-edge'       => ['10.255.255.254', 'RFC1918 10/8 upper edge'],
            'rfc1918-172.16'        => ['172.16.0.1', 'RFC1918 172.16/12 lower'],
            'rfc1918-172.31'        => ['172.31.255.254', 'RFC1918 172.16/12 upper'],
            'rfc1918-192.168'       => ['192.168.0.1', 'RFC1918 192.168/16'],
            // Loopback
            'loopback-127'          => ['127.0.0.1', 'loopback'],
            'loopback-127-edge'     => ['127.255.255.254', 'loopback upper'],
            // Link-local
            'linklocal-169.254'     => ['169.254.169.254', 'AWS metadata service'],
            // CGN
            'cgn-100.64'            => ['100.64.0.1', 'RFC6598 CGN'],
            'cgn-100.127'           => ['100.127.255.254', 'RFC6598 CGN upper'],
            // TEST-NET
            'testnet-1'             => ['192.0.2.42', 'TEST-NET-1'],
            'testnet-2'             => ['198.51.100.42', 'TEST-NET-2'],
            'testnet-3'             => ['203.0.113.42', 'TEST-NET-3'],
            // Benchmark
            'benchmark-198.18'      => ['198.18.0.1', 'RFC2544 benchmark'],
            'benchmark-198.19'      => ['198.19.255.254', 'RFC2544 benchmark upper'],
            // Multicast / reserved / broadcast
            'multicast'             => ['224.0.0.1', 'multicast'],
            'reserved-240'          => ['240.0.0.1', 'reserved 240/4'],
            'broadcast'             => ['255.255.255.255', 'limited broadcast'],
            'this-network'          => ['0.0.0.0', 'this network'],
            // IPv6 loopback / link-local / ULA / multicast / unspecified
            'ipv6-loopback'         => ['::1', 'IPv6 loopback'],
            'ipv6-unspec'           => ['::', 'IPv6 unspecified'],
            'ipv6-linklocal'        => ['fe80::1', 'IPv6 link-local'],
            'ipv6-ula-lower'        => ['fc00::1', 'IPv6 ULA lower bound'],
            'ipv6-ula-upper'        => ['fdff:ffff:ffff:ffff:ffff:ffff:ffff:fffe', 'IPv6 ULA upper bound'],
            'ipv6-multicast'        => ['ff02::1', 'IPv6 multicast'],
            'ipv6-doc'              => ['2001:db8::1', 'IPv6 doc range'],
            // IPv4-mapped IPv6 — these MUST be caught (otherwise loopback is bypassable)
            'v4-mapped-loopback'    => ['::ffff:127.0.0.1', 'IPv4-mapped loopback'],
            'v4-mapped-rfc1918'     => ['::ffff:10.0.0.1', 'IPv4-mapped RFC1918'],
            'v4-mapped-aws-meta'    => ['::ffff:169.254.169.254', 'IPv4-mapped AWS metadata'],
            // IPv4-compatible IPv6 (deprecated)
            'v4-compat-loopback'    => ['::127.0.0.1', 'IPv4-compatible loopback'],
            // Malformed → treat as private (fail-closed)
            'garbage'               => ['not-an-ip', 'malformed'],
            'empty'                 => ['', 'empty'],
            'half-ipv4'             => ['1.2.3', 'half-formed IPv4'],
        ];
    }

    public static function publicProvider(): array
    {
        return [
            'google-dns'        => ['8.8.8.8'],
            'cloudflare-dns'    => ['1.1.1.1'],
            'rfc1918-edge-just-above' => ['11.0.0.1'],
            'rfc1918-172.32-just-above' => ['172.32.0.1'],
            'rfc1918-192.167-just-below' => ['192.167.255.254'],
            'rfc1918-192.169-just-above' => ['192.169.0.1'],
            'loopback-126.255' => ['126.255.255.254'],
            'loopback-128' => ['128.0.0.1'],
            'cgn-100.63-below' => ['100.63.255.254'],
            'cgn-100.128-above' => ['100.128.0.1'],
            'github'           => ['140.82.114.4'],
            'ipv6-google-dns'  => ['2001:4860:4860::8888'],
            'ipv6-cloudflare'  => ['2606:4700:4700::1111'],
        ];
    }
}
