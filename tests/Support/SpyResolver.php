<?php

namespace Ekumanov\LinkPreview\Tests\Support;

use Ekumanov\LinkPreview\Http\Resolver;

/**
 * Records every host that gets resolved and returns a pre-programmed answer.
 * If a host is queried that wasn't programmed, the test fails — that catches
 * code paths that resolve hosts we didn't expect them to.
 */
final class SpyResolver implements Resolver
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param array<string, list<string>> $answers map host => list of IPs
     */
    public function __construct(private readonly array $answers = []) {}

    public function resolve(string $host): array
    {
        $this->calls[] = $host;
        return $this->answers[$host] ?? [];
    }
}
