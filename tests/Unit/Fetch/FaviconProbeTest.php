<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Fetch;

use Ekumanov\LinkPreview\Fetch\FaviconProbe;
use Ekumanov\LinkPreview\Http\DefaultIpFilter;
use Ekumanov\LinkPreview\Http\ExecutorResult;
use Ekumanov\LinkPreview\Http\SafeHttpClient;
use Ekumanov\LinkPreview\Http\UrlValidator;
use Ekumanov\LinkPreview\Tests\Support\FakeExecutor;
use Ekumanov\LinkPreview\Tests\Support\SpyResolver;
use PHPUnit\Framework\TestCase;

final class FaviconProbeTest extends TestCase
{
    private const MAX = 204800;

    public function test_accepts_a_200_image_response(): void
    {
        $probe = $this->probe([
            'https://example.com/favicon.ico' => ExecutorResult::ok(200, ['content-type' => 'image/x-icon'], 'BINARY'),
        ], $executor);

        $this->assertSame(
            ['href' => 'https://example.com/favicon.ico', 'probed' => true],
            $probe->probe('https://example.com/some/deep/page?a=1', self::MAX)
        );
        $this->assertCount(1, $executor->calls, 'exactly one request per probe');
    }

    public function test_rejects_an_html_200(): void
    {
        // The common soft-404: an unknown path answered with the homepage.
        $probe = $this->probe([
            'https://example.com/favicon.ico' => ExecutorResult::ok(200, ['content-type' => 'text/html; charset=utf-8'], '<html></html>'),
        ], $executor);

        $this->assertNull($probe->probe('https://example.com/', self::MAX));
    }

    public function test_rejects_an_oversized_body(): void
    {
        $probe = $this->probe([
            'https://example.com/favicon.ico' => ExecutorResult::ok(200, ['content-type' => 'image/vnd.microsoft.icon'], str_repeat('x', 300)),
        ], $executor);

        $this->assertNull($probe->probe('https://example.com/', 256));
    }

    public function test_accepts_a_body_exactly_at_the_ceiling(): void
    {
        $probe = $this->probe([
            'https://example.com/favicon.ico' => ExecutorResult::ok(200, ['content-type' => 'image/png'], str_repeat('x', 256)),
        ], $executor);

        $this->assertNotNull($probe->probe('https://example.com/', 256));
    }

    public function test_rejects_an_empty_body(): void
    {
        $probe = $this->probe([
            'https://example.com/favicon.ico' => ExecutorResult::ok(200, ['content-type' => 'image/png'], ''),
        ], $executor);

        $this->assertNull($probe->probe('https://example.com/', self::MAX));
    }

    public function test_rejects_a_404(): void
    {
        $probe = $this->probe([
            'https://example.com/favicon.ico' => ExecutorResult::ok(404, ['content-type' => 'text/html'], 'nope'),
        ], $executor);

        $this->assertNull($probe->probe('https://example.com/', self::MAX));
    }

    public function test_rejects_a_transport_failure(): void
    {
        $probe = $this->probe([], $executor); // no canned response → connect_failed

        $this->assertNull($probe->probe('https://example.com/', self::MAX));
    }

    public function test_probes_the_origin_not_the_page_path(): void
    {
        $probe = $this->probe([
            'https://example.com/favicon.ico' => ExecutorResult::ok(200, ['content-type' => 'image/png'], 'PNG'),
        ], $executor);

        $probe->probe('https://example.com/2019/03/an-article.html', self::MAX);

        $this->assertSame('https://example.com/favicon.ico', $executor->calls[0]['url']);
    }

    public function test_inherits_the_port_allowlist(): void
    {
        // The origin URL keeps the page's port, so a page on a port the
        // validator refuses gets its favicon refused by the same rule — no
        // second, laxer path to the network.
        $probe = $this->probe([
            'https://example.com:8443/favicon.ico' => ExecutorResult::ok(200, ['content-type' => 'image/png'], 'PNG'),
        ], $executor);

        $this->assertNull($probe->probe('https://example.com:8443/page', self::MAX));
        $this->assertSame([], $executor->calls);
    }

    public function test_records_the_redirect_target_not_the_requested_url(): void
    {
        $probe = $this->probe([
            'https://example.com/favicon.ico' => ExecutorResult::ok(301, ['location' => '/assets/icon.png'], ''),
            'https://example.com/assets/icon.png' => ExecutorResult::ok(200, ['content-type' => 'image/png'], 'PNG'),
        ], $executor);

        $this->assertSame(
            ['href' => 'https://example.com/assets/icon.png', 'probed' => true],
            $probe->probe('https://example.com/', self::MAX)
        );
    }

    public function test_never_leaves_the_ssrf_chain(): void
    {
        // A page that came back from a host resolving to a private address
        // must not get a favicon request either.
        $probe = $this->probe([], $executor, ['internal.example.com' => ['10.0.0.5']]);

        $this->assertNull($probe->probe('https://internal.example.com/page', self::MAX));
        $this->assertSame([], $executor->calls, 'the executor must never be reached');
    }

    public function test_returns_null_for_a_non_web_page_url(): void
    {
        $probe = $this->probe([], $executor);

        $this->assertNull($probe->probe('mailto:someone@example.com', self::MAX));
        $this->assertNull($probe->probe('not a url at all', self::MAX));
        $this->assertSame([], $executor->calls);
    }

    /**
     * @param array<string, ExecutorResult>       $responses
     * @param array<string, list<string>>|null    $dns
     */
    private function probe(array $responses, ?FakeExecutor &$executor, ?array $dns = null): FaviconProbe
    {
        $executor = new FakeExecutor($responses);

        return new FaviconProbe(new SafeHttpClient(
            urlValidator: new UrlValidator(),
            resolver: new SpyResolver($dns ?? ['example.com' => ['93.184.216.34']]),
            ipFilter: new DefaultIpFilter(),
            executor: $executor,
        ));
    }
}
