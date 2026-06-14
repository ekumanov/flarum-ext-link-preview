<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * Real DNS lookup via PHP's dns_get_record(). Returns both A and AAAA so the
 * SSRF check sees every address an honest client could end up connecting to —
 * not just the first one the system happens to pick.
 *
 * Empty result indicates lookup failure (NXDOMAIN, network issue, etc.) and
 * the caller should treat it as a non-fetchable host.
 */
final class DnsResolver implements Resolver
{
    public function resolve(string $host): array
    {
        // If the host is already an IP literal, dns_get_record returns nothing
        // useful. Short-circuit so the rest of the pipeline still works.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $r) {
            if (isset($r['ip']) && is_string($r['ip'])) {
                $ips[] = $r['ip'];
            } elseif (isset($r['ipv6']) && is_string($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
