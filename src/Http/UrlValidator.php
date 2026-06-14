<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * First-pass URL sanity. Run BEFORE DNS resolution.
 *
 * Anything this rejects is either malformed, points at a non-fetchable scheme,
 * carries credentials we don't want to forward, or uses a port outside the
 * narrow set we allow. The IP-level checks happen later in PrivateIpFilter
 * after we've resolved the hostname.
 */
final class UrlValidator
{
    public const REASON_MALFORMED = 'malformed';
    public const REASON_TOO_LONG = 'too_long';
    public const REASON_BAD_SCHEME = 'bad_scheme';
    public const REASON_HAS_USERINFO = 'has_userinfo';
    public const REASON_NO_HOST = 'no_host';
    public const REASON_BAD_PORT = 'bad_port';

    public const MAX_LEN = 2048;

    public function __construct(
        /** @var list<string> */
        private readonly array $allowedSchemes = ['http', 'https'],
        /** @var list<int> */
        private readonly array $allowedPorts = [80, 443],
    ) {}

    /**
     * @return array{ok:true,scheme:string,host:string,port:int}|array{ok:false,reason:string}
     */
    public function validate(string $url): array
    {
        if ($url === '' || strlen($url) > self::MAX_LEN) {
            return ['ok' => false, 'reason' => $url === '' ? self::REASON_MALFORMED : self::REASON_TOO_LONG];
        }

        // parse_url returns false on seriously malformed input but is otherwise
        // forgiving. Validate explicitly that we got a scheme and host.
        $parts = parse_url($url);
        if ($parts === false || ! is_array($parts)) {
            return ['ok' => false, 'reason' => self::REASON_MALFORMED];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, $this->allowedSchemes, true)) {
            return ['ok' => false, 'reason' => self::REASON_BAD_SCHEME];
        }

        // Credentials in URL get implicitly forwarded by curl. Reject so we
        // don't accidentally leak a token or auth basic header to a remote.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'reason' => self::REASON_HAS_USERINFO];
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return ['ok' => false, 'reason' => self::REASON_NO_HOST];
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, $this->allowedPorts, true)) {
            return ['ok' => false, 'reason' => self::REASON_BAD_PORT];
        }

        return [
            'ok' => true,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
        ];
    }
}
