<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * Production IP filter — delegates to PrivateIpFilter::isPrivate().
 */
final class DefaultIpFilter implements IpFilter
{
    public function isPrivate(string $ip): bool
    {
        return PrivateIpFilter::isPrivate($ip);
    }
}
