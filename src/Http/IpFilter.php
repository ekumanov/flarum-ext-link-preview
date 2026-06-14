<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * Decide whether a resolved IP should be considered non-fetchable.
 *
 * Wrapped behind an interface so tests (or future allowlist features) can
 * substitute custom rules without touching the SafeHttpClient flow.
 */
interface IpFilter
{
    public function isPrivate(string $ip): bool;
}
