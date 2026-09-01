<?php

namespace Ekumanov\LinkPreview\Tests\Support;

use Ekumanov\LinkPreview\Http\ExecutorResult;
use Ekumanov\LinkPreview\Http\RequestExecutor;

/**
 * Returns canned responses keyed by URL. Records every call so tests can
 * verify the SSRF chain didn't slip a URL past validation.
 *
 * $agentResponses keys on "<user-agent>|<url>" and wins over $responses when
 * present — that's how the User-Agent fallback tests model a site that answers
 * one identity with a 403 and another with a page.
 */
final class FakeExecutor implements RequestExecutor
{
    /** @var list<array{url:string,host:string,ip:string,port:int,userAgent:?string}> */
    public array $calls = [];

    /**
     * @param array<string, ExecutorResult> $responses      keyed by URL
     * @param array<string, ExecutorResult> $agentResponses keyed by "<ua>|<url>"
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly array $agentResponses = [],
    ) {}

    public function execute(string $url, string $pinnedHost, string $pinnedIp, int $port, ?string $userAgent = null): ExecutorResult
    {
        $this->calls[] = [
            'url' => $url,
            'host' => $pinnedHost,
            'ip' => $pinnedIp,
            'port' => $port,
            'userAgent' => $userAgent,
        ];

        if ($userAgent !== null && isset($this->agentResponses[$userAgent.'|'.$url])) {
            return $this->agentResponses[$userAgent.'|'.$url];
        }

        return $this->responses[$url] ?? ExecutorResult::failure(ExecutorResult::ERR_CONNECT, "no canned response for $url");
    }

    /** @return list<?string> the User-Agent each call was made under, in order */
    public function userAgents(): array
    {
        return array_map(fn (array $c) => $c['userAgent'], $this->calls);
    }
}
