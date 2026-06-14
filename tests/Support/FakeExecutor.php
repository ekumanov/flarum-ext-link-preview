<?php

namespace Ekumanov\LinkPreview\Tests\Support;

use Ekumanov\LinkPreview\Http\ExecutorResult;
use Ekumanov\LinkPreview\Http\RequestExecutor;

/**
 * Returns canned responses keyed by URL. Records every call so tests can
 * verify the SSRF chain didn't slip a URL past validation.
 */
final class FakeExecutor implements RequestExecutor
{
    /** @var list<array{url:string,host:string,ip:string,port:int}> */
    public array $calls = [];

    /**
     * @param array<string, ExecutorResult> $responses keyed by URL
     */
    public function __construct(private readonly array $responses = []) {}

    public function execute(string $url, string $pinnedHost, string $pinnedIp, int $port): ExecutorResult
    {
        $this->calls[] = ['url' => $url, 'host' => $pinnedHost, 'ip' => $pinnedIp, 'port' => $port];
        return $this->responses[$url] ?? ExecutorResult::failure(ExecutorResult::ERR_CONNECT, "no canned response for $url");
    }
}
