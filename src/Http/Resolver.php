<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * Resolve a hostname to one or more IP literals.
 *
 * Pulled behind an interface so tests can drive the SSRF flow without DNS,
 * and so we can swap in a caching resolver later if we want.
 */
interface Resolver
{
    /**
     * @return list<string> Returns an empty list if resolution fails. Never throws.
     */
    public function resolve(string $host): array;
}
