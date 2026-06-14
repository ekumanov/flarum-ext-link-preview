<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Http;

use Ekumanov\LinkPreview\Http\UrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlValidatorTest extends TestCase
{
    public function test_accepts_plain_http_url(): void
    {
        $r = (new UrlValidator())->validate('http://example.com/');
        $this->assertTrue($r['ok']);
        $this->assertSame('http', $r['scheme']);
        $this->assertSame('example.com', $r['host']);
        $this->assertSame(80, $r['port']);
    }

    public function test_accepts_https_url_with_path_and_query(): void
    {
        $r = (new UrlValidator())->validate('https://example.com/a/b?c=1#frag');
        $this->assertTrue($r['ok']);
        $this->assertSame(443, $r['port']);
    }

    public function test_explicit_port_443_allowed(): void
    {
        $r = (new UrlValidator())->validate('https://example.com:443/');
        $this->assertTrue($r['ok']);
        $this->assertSame(443, $r['port']);
    }

    #[DataProvider('rejectProvider')]
    public function test_rejects(string $url, string $expectedReason): void
    {
        $r = (new UrlValidator())->validate($url);
        $this->assertFalse($r['ok'], "expected $url to be rejected");
        $this->assertSame($expectedReason, $r['reason']);
    }

    public static function rejectProvider(): array
    {
        return [
            'empty'           => ['', UrlValidator::REASON_MALFORMED],
            'no-scheme'       => ['example.com/path', UrlValidator::REASON_BAD_SCHEME],
            'file-scheme'     => ['file:///etc/passwd', UrlValidator::REASON_BAD_SCHEME],
            'ftp-scheme'      => ['ftp://example.com/', UrlValidator::REASON_BAD_SCHEME],
            'gopher-scheme'   => ['gopher://example.com/', UrlValidator::REASON_BAD_SCHEME],
            'data-scheme'     => ['data:text/plain,hello', UrlValidator::REASON_BAD_SCHEME],
            'js-scheme'       => ['javascript:alert(1)', UrlValidator::REASON_BAD_SCHEME],
            'userinfo'        => ['http://admin:pw@example.com/', UrlValidator::REASON_HAS_USERINFO],
            'user-only'       => ['http://admin@example.com/', UrlValidator::REASON_HAS_USERINFO],
            'bad-port-22'     => ['http://example.com:22/', UrlValidator::REASON_BAD_PORT],
            'bad-port-8080'   => ['http://example.com:8080/', UrlValidator::REASON_BAD_PORT],
            'too-long'        => ['https://example.com/'.str_repeat('a', UrlValidator::MAX_LEN), UrlValidator::REASON_TOO_LONG],
            // parse_url returns false for "http://" — caught at the parse layer,
            // not the host-presence check, but the outcome (rejection) is correct.
            'just-scheme'     => ['http://', UrlValidator::REASON_MALFORMED],
        ];
    }

    public function test_custom_allowed_ports(): void
    {
        $v = new UrlValidator(allowedPorts: [80, 443, 8443]);
        $this->assertTrue($v->validate('https://example.com:8443/')['ok']);
        $this->assertFalse($v->validate('https://example.com:9000/')['ok']);
    }

    public function test_custom_allowed_schemes(): void
    {
        $v = new UrlValidator(allowedSchemes: ['https']);
        $this->assertFalse($v->validate('http://example.com/')['ok']);
        $this->assertTrue($v->validate('https://example.com/')['ok']);
    }
}
