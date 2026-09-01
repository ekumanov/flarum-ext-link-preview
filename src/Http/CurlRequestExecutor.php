<?php

namespace Ekumanov\LinkPreview\Http;

/**
 * curl-backed executor with three load-bearing defenses:
 *
 *  1. CURLOPT_RESOLVE pins the resolved IP for this host:port. The TLS / Host
 *     header still uses $pinnedHost, but the actual TCP target is fixed —
 *     even if DNS flips between resolve-time and connect-time (the classic
 *     DNS rebinding pattern), curl connects to the IP we already vetted.
 *
 *  2. CURLOPT_PROTOCOLS_STR locks the wire protocol to http(s). A redirect
 *     with a gopher:// or file:// Location couldn't traverse curl even if
 *     SafeHttpClient's redirect handler somehow failed to re-validate.
 *
 *  3. CURLOPT_WRITEFUNCTION returns -1 once $maxBytes is exceeded, causing
 *     curl to abort the transfer with CURLE_WRITE_ERROR. A malicious origin
 *     can't keep streaming megabytes at us — we cut the connection.
 *
 * Redirects are disabled here (FOLLOWLOCATION=false). SafeHttpClient re-runs
 * the full URL/DNS/IP validation chain on each hop instead.
 */
final class CurlRequestExecutor implements RequestExecutor
{
    /**
     * Plain desktop Chrome. Measured 2026-09-01 against the 54 hosts that were
     * blocking this fetcher on pianoclack: the previous
     * 'Mozilla/5.0 (compatible; FlarumLinkPreview/1.0) Chrome/126.0.0.0' was
     * rejected outright by CDN bot rules (SiteGround, Fastly and friends read
     * the "compatible;" shape as a crawler), while this string is accepted.
     * Adding realistic Accept / Sec-Fetch-* headers on top recovered exactly
     * zero further hosts, so we don't bother — what remains is IP-reputation
     * and TLS-fingerprint blocking, which no header can talk its way past.
     */
    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    public function __construct(
        private readonly int $connectTimeoutSec = 5,
        private readonly int $totalTimeoutSec = 10,
        private readonly int $maxBytes = 2097152,
        private readonly string $userAgent = self::DEFAULT_USER_AGENT,
    ) {}

    public function execute(string $url, string $pinnedHost, string $pinnedIp, int $port, ?string $userAgent = null): ExecutorResult
    {
        $ch = curl_init();
        if ($ch === false) {
            return ExecutorResult::failure(ExecutorResult::ERR_CONNECT, 'curl_init failed');
        }

        $body = '';
        $bytes = 0;
        $headers = [];

        // For IPv6, CURLOPT_RESOLVE wants the literal in square brackets in the
        // host position; the IP itself goes after the colons.
        // Format: "HOST:PORT:ADDRESS" — ADDRESS can be IPv4 or IPv6.
        $resolveDirective = "{$pinnedHost}:{$port}:{$pinnedIp}";

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [$resolveDirective],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSec,
            CURLOPT_TIMEOUT => $this->totalTimeoutSec,
            CURLOPT_USERAGENT => $userAgent ?? $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html, application/xhtml+xml; q=0.9, */*; q=0.1',
                'Accept-Language: en;q=0.9,*;q=0.5',
            ],
            // Lock to http(s) only — refuses to traverse a gopher/file/dict
            // redirect even before we re-validate.
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            // We don't want curl writing the body via its default — capture
            // through WRITEFUNCTION so we can enforce the byte cap.
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($_ch, string $chunk) use (&$body, &$bytes) {
                $len = strlen($chunk);
                if ($bytes + $len > $this->maxBytes) {
                    // Returning a value < strlen($chunk) makes curl abort with
                    // CURLE_WRITE_ERROR; we detect that below and surface as
                    // body_too_large.
                    return -1;
                }
                $bytes += $len;
                $body .= $chunk;
                return $len;
            },
            CURLOPT_HEADERFUNCTION => function ($_ch, string $line) use (&$headers) {
                $len = strlen($line);
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with(strtolower($trimmed), 'http/')) {
                    return $len;
                }
                $colon = strpos($trimmed, ':');
                if ($colon !== false) {
                    $name = strtolower(substr($trimmed, 0, $colon));
                    $value = ltrim(substr($trimmed, $colon + 1));
                    $headers[$name] = $value;
                }
                return $len;
            },
            // Prevent curl from doing anything clever with cookies/SSL session
            // state between calls. Each fetch is independent.
            CURLOPT_COOKIEFILE => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        curl_setopt_array($ch, $opts);

        $execOk = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($execOk === false) {
            return match ($errno) {
                CURLE_WRITE_ERROR => ExecutorResult::failure(ExecutorResult::ERR_BODY_TOO_LARGE, "exceeded {$this->maxBytes} bytes"),
                CURLE_OPERATION_TIMEOUTED => ExecutorResult::failure(ExecutorResult::ERR_TIMEOUT, "exceeded {$this->totalTimeoutSec}s"),
                CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST => ExecutorResult::failure(ExecutorResult::ERR_CONNECT, "curl errno $errno"),
                default => ExecutorResult::failure(ExecutorResult::ERR_PROTOCOL, "curl errno $errno"),
            };
        }

        return ExecutorResult::ok($status, $headers, $body);
    }
}
