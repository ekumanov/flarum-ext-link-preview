<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * An executor that can be told to give up sooner than its configured timeout.
 *
 * SafeHttpClient uses this to fit a request into what is left of a caller's
 * overall budget (FetchPreviewJob has a hard worker timeout, and a job that
 * overruns it takes the whole worker process down with it).
 *
 * A separate interface rather than a new parameter on RequestExecutor on
 * purpose: adding even an optional parameter to an interface is a fatal error
 * for every existing implementation that omits it. Executors that do not
 * implement this simply run with their own timeout, as before.
 */
interface TimeBoundedExecutor extends RequestExecutor
{
    /**
     * Same contract as execute(), but the whole request — connect included —
     * must finish within $maxSeconds, or fail as a timeout.
     */
    public function executeWithin(
        string $url,
        string $pinnedHost,
        string $pinnedIp,
        int $port,
        ?string $userAgent,
        float $maxSeconds,
    ): ExecutorResult;
}
