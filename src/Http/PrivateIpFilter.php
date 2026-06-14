<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * Reject IPs that belong to non-routable, internal, or test-net ranges.
 *
 * Used by SafeHttpClient after DNS resolution and on every redirect hop. If
 * isPrivate() returns true, the connection must not be made — that resolved IP
 * is either internal to our network, a loopback, a link-local helper, or a
 * documentation/multicast range an honest external site has no business using.
 *
 * Catches IPv4-mapped IPv6 (::ffff:a.b.c.d) by extracting the embedded v4 and
 * re-checking — without this, an attacker could write http://[::ffff:127.0.0.1]/
 * and bypass the v4 loopback check.
 */
final class PrivateIpFilter
{
    /** @var list<array{0:string,1:int}> packed-network/prefix pairs for IPv4 */
    private const IPV4_BLOCKED = [
        ['0.0.0.0', 8],          // "this network" + unspecified
        ['10.0.0.0', 8],         // RFC1918 private
        ['100.64.0.0', 10],      // RFC6598 carrier-grade NAT
        ['127.0.0.0', 8],        // loopback
        ['169.254.0.0', 16],     // link-local
        ['172.16.0.0', 12],      // RFC1918 private
        ['192.0.0.0', 24],       // RFC6890 protocol assignments
        ['192.0.2.0', 24],       // TEST-NET-1 (RFC5737)
        ['192.168.0.0', 16],     // RFC1918 private
        ['198.18.0.0', 15],      // benchmark (RFC2544)
        ['198.51.100.0', 24],    // TEST-NET-2
        ['203.0.113.0', 24],     // TEST-NET-3
        ['224.0.0.0', 4],        // multicast
        ['240.0.0.0', 4],        // reserved (incl. 255.255.255.255 broadcast)
    ];

    /** @var list<array{0:string,1:int}> for IPv6 */
    private const IPV6_BLOCKED = [
        ['::', 128],             // unspecified
        ['::1', 128],            // loopback
        ['fc00::', 7],           // unique-local (ULA)
        ['fe80::', 10],          // link-local
        ['ff00::', 8],           // multicast
        ['100::', 64],           // discard prefix (RFC6666)
        ['2001:db8::', 32],      // documentation
        ['64:ff9b::', 96],       // NAT64
    ];

    public static function isPrivate(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            // Malformed IP — treat as unsafe so callers don't accidentally fall
            // through to "well it didn't match any block, must be public".
            return true;
        }

        if (strlen($packed) === 4) {
            return self::matchesAny($packed, self::IPV4_BLOCKED);
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d). RFC4291 §2.5.5.2 — extract the
        // embedded v4 and re-check, otherwise the v4 rules are trivially
        // bypassed.
        $v4MappedPrefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
        if (str_starts_with($packed, $v4MappedPrefix)) {
            $embedded = inet_ntop(substr($packed, 12));
            return $embedded === false || self::isPrivate($embedded);
        }

        // IPv4-compatible IPv6 (::a.b.c.d, deprecated but still resolvable).
        // First 96 bits zero, last 32 not zero → embedded v4.
        $v4CompatPrefix = str_repeat("\x00", 12);
        if (str_starts_with($packed, $v4CompatPrefix) && substr($packed, 12) !== $v4CompatPrefix) {
            $embedded = inet_ntop(substr($packed, 12));
            return $embedded === false || self::isPrivate($embedded);
        }

        return self::matchesAny($packed, self::IPV6_BLOCKED);
    }

    /**
     * @param list<array{0:string,1:int}> $blocks
     */
    private static function matchesAny(string $packed, array $blocks): bool
    {
        $bitLen = strlen($packed) * 8;
        foreach ($blocks as [$net, $prefix]) {
            $netPacked = inet_pton($net);
            if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
                continue;
            }
            if (self::sharesPrefix($packed, $netPacked, $prefix, $bitLen)) {
                return true;
            }
        }
        return false;
    }

    private static function sharesPrefix(string $a, string $b, int $prefixBits, int $totalBits): bool
    {
        if ($prefixBits <= 0) {
            return true;
        }
        if ($prefixBits > $totalBits) {
            return false;
        }

        $fullBytes = intdiv($prefixBits, 8);
        if ($fullBytes > 0 && substr($a, 0, $fullBytes) !== substr($b, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefixBits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        // Compare the top $remainingBits of the next byte.
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($a[$fullBytes]) & $mask) === (ord($b[$fullBytes]) & $mask);
    }
}
