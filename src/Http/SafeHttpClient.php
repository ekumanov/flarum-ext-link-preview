<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * SSRF-hardened GET client.
 *
 * Each request goes through the full validation chain. On every redirect hop
 * the chain runs again — never trust a Location header to point somewhere
 * we'd already approved.
 *
 *   1. UrlValidator: scheme/host/port/userinfo/length.
 *   2. Resolver: hostname → IPs (or short-circuit for IP literals).
 *   3. IpFilter: every resolved IP must be public. If ANY is private we
 *      refuse the host entirely — this catches DNS rebind setups where
 *      multiple A records mix public + private.
 *   4. Pin the chosen IP and hand off to RequestExecutor.
 *   5. If the response is a 3xx with a Location, recurse with the new URL,
 *      decrementing the redirect budget.
 *
 * Returns a result array:
 *   ['ok' => true,  'status' => int, 'finalUrl' => string,
 *    'contentType' => string, 'headers' => array<string,string>, 'body' => string]
 *   ['ok' => false, 'reason' => string, 'detail' => string]
 *
 * Reasons mirror UrlValidator + ExecutorResult, plus:
 *   - 'ssrf_private_ip': host resolved to a non-public address
 *   - 'dns_failed':      no A/AAAA records
 *   - 'too_many_redirects'
 */
final class SafeHttpClient
{
    public const REASON_SSRF = 'ssrf_private_ip';
    public const REASON_DNS_FAILED = 'dns_failed';
    public const REASON_TOO_MANY_REDIRECTS = 'too_many_redirects';

    public function __construct(
        private readonly UrlValidator $urlValidator,
        private readonly Resolver $resolver,
        private readonly IpFilter $ipFilter,
        private readonly RequestExecutor $executor,
        private readonly int $maxRedirects = 5,
    ) {}

    /**
     * @return array{ok:true,status:int,finalUrl:string,contentType:string,headers:array<string,string>,body:string}
     *        |array{ok:false,reason:string,detail:string}
     */
    public function get(string $url): array
    {
        return $this->doGet($url, $this->maxRedirects);
    }

    /**
     * @return array{ok:true,status:int,finalUrl:string,contentType:string,headers:array<string,string>,body:string}
     *        |array{ok:false,reason:string,detail:string}
     */
    private function doGet(string $url, int $redirectsLeft): array
    {
        $v = $this->urlValidator->validate($url);
        if (! $v['ok']) {
            return self::fail($v['reason'], $url);
        }

        $ips = $this->resolver->resolve($v['host']);
        if ($ips === []) {
            return self::fail(self::REASON_DNS_FAILED, $v['host']);
        }

        // If ANY resolved IP is private, refuse the whole host. This is the
        // anti-rebind defense: even if we pick a public IP for the connect,
        // a misbehaving cache or future re-resolve could land on the private
        // one. Cheapest fix: never fetch a host that *can* resolve private.
        foreach ($ips as $ip) {
            if ($this->ipFilter->isPrivate($ip)) {
                return self::fail(self::REASON_SSRF, "{$v['host']} -> $ip");
            }
        }

        $pinnedIp = $ips[0];
        $result = $this->executor->execute($url, $v['host'], $pinnedIp, $v['port']);

        if (! $result->ok) {
            return self::fail($result->error ?? 'unknown', $result->errorDetail ?? '');
        }

        // 3xx with Location → recurse on the new URL. We *don't* trust the
        // Location to be safe; doGet() re-runs the whole chain.
        if ($result->status >= 300 && $result->status < 400 && isset($result->headers['location'])) {
            if ($redirectsLeft <= 0) {
                return self::fail(self::REASON_TOO_MANY_REDIRECTS, $url);
            }
            $next = self::resolveLocation($url, $result->headers['location']);
            if ($next === null) {
                return self::fail(UrlValidator::REASON_MALFORMED, "bad Location: {$result->headers['location']}");
            }
            return $this->doGet($next, $redirectsLeft - 1);
        }

        return [
            'ok' => true,
            'status' => $result->status,
            'finalUrl' => $url,
            'contentType' => $result->headers['content-type'] ?? '',
            'headers' => $result->headers,
            'body' => $result->body,
        ];
    }

    /**
     * @return array{ok:false,reason:string,detail:string}
     */
    private static function fail(string $reason, string $detail): array
    {
        return ['ok' => false, 'reason' => $reason, 'detail' => $detail];
    }

    /**
     * Resolve a Location header value against the request URL, handling the
     * common cases: absolute URL, scheme-relative (//host/path), root-relative
     * (/path), or relative (path). Returns null on malformed input.
     */
    private static function resolveLocation(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        // Absolute URL.
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $location) === 1) {
            return $location;
        }

        $bp = parse_url($base);
        if (! is_array($bp) || ! isset($bp['scheme'], $bp['host'])) {
            return null;
        }
        $scheme = $bp['scheme'];
        $host = $bp['host'];
        $port = isset($bp['port']) ? ':'.$bp['port'] : '';

        // Scheme-relative: //host/path
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        // Root-relative: /path
        if (str_starts_with($location, '/')) {
            return "$scheme://$host$port$location";
        }
        // Relative: combine with base path's directory.
        $path = $bp['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);
        return "$scheme://$host$port$dir$location";
    }
}
