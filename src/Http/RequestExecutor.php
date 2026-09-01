<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * Carry out a single HTTP request against a *pre-validated* host:port + IP.
 *
 * Pulled behind an interface so SafeHttpClient's redirect+SSRF logic can be
 * unit-tested without real network I/O. The production implementation is
 * CurlRequestExecutor.
 *
 * Implementations MUST NOT follow redirects on their own — SafeHttpClient
 * re-runs the full URL+DNS+IP check on every redirect hop, so the executor
 * just performs one request and returns whatever the server said.
 */
interface RequestExecutor
{
    /**
     * $userAgent overrides the implementation's default for this one request.
     * SafeHttpClient uses it to re-try a bot-blocked URL under a different
     * identity; null means "use whatever you were configured with".
     *
     * @return ExecutorResult
     */
    public function execute(string $url, string $pinnedHost, string $pinnedIp, int $port, ?string $userAgent = null): ExecutorResult;
}
