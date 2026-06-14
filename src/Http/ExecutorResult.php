<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * Carrier for what a RequestExecutor returns. Two shapes:
 *  - transport-ok: the request completed; status/headers/body are populated
 *    (status may still be 4xx/5xx — that's a *successful* fetch of an
 *    error response, distinct from a transport failure).
 *  - transport-error: connection refused, TLS handshake failed, timeout,
 *    body exceeded the byte cap, etc. SafeHttpClient surfaces these as
 *    reason='connect_failed' / 'timeout' / 'body_too_large'.
 */
final class ExecutorResult
{
    public const ERR_CONNECT = 'connect_failed';
    public const ERR_TIMEOUT = 'timeout';
    public const ERR_BODY_TOO_LARGE = 'body_too_large';
    public const ERR_PROTOCOL = 'protocol_error';

    private function __construct(
        public readonly bool $ok,
        public readonly int $status = 0,
        /** @var array<string,string> normalised lower-case header names */
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly ?string $error = null,
        public readonly ?string $errorDetail = null,
    ) {}

    /**
     * @param array<string,string> $headers
     */
    public static function ok(int $status, array $headers, string $body): self
    {
        return new self(ok: true, status: $status, headers: $headers, body: $body);
    }

    public static function failure(string $error, string $detail = ''): self
    {
        return new self(ok: false, error: $error, errorDetail: $detail);
    }
}
