<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Job;

use Ekumanov\LinkPreview\Fetch\FaviconProbe;
use Ekumanov\LinkPreview\Http\DefaultIpFilter;
use Ekumanov\LinkPreview\Http\ExecutorResult;
use Ekumanov\LinkPreview\Http\RequestExecutor;
use Ekumanov\LinkPreview\Http\SafeHttpClient;
use Ekumanov\LinkPreview\Http\UrlValidator;
use Ekumanov\LinkPreview\Job\FetchPreviewJob;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\Settings\SettingsRepository;
use Ekumanov\LinkPreview\Tests\Support\SpyResolver;
use Flarum\Settings\SettingsRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The favicon probe is best-effort by contract: whatever it does, the preview
 * it belongs to must come out of the job exactly as it would have without it.
 */
final class FetchPreviewJobTest extends TestCase
{
    public function test_a_throwing_probe_yields_null_and_never_fails_the_preview(): void
    {
        $preview = new Preview();
        $preview->id = 1;
        $preview->url = 'https://example.com/article';
        $preview->final_url = 'https://example.com/article';
        $preview->http_status = 200;
        $preview->error = null;
        $preview->fetch_attempts = 0;

        $probe = new FaviconProbe(new SafeHttpClient(
            urlValidator: new UrlValidator(),
            resolver: new SpyResolver(['example.com' => ['93.184.216.34']]),
            ipFilter: new DefaultIpFilter(),
            executor: new class implements RequestExecutor {
                public function execute(string $url, string $pinnedHost, string $pinnedIp, int $port, ?string $userAgent = null): ExecutorResult
                {
                    throw new RuntimeException('curl exploded');
                }
            },
        ));

        $method = new ReflectionMethod(FetchPreviewJob::class, 'probeIcon');
        $result = $method->invoke(
            new FetchPreviewJob(1),
            $probe,
            $preview,
            new SettingsRepository($this->emptySettings()),
            new NullLogger(),
        );

        $this->assertNull($result);
        $this->assertNull($preview->error, 'a failed probe must not error the preview');
        $this->assertSame(0, $preview->fetch_attempts, 'a failed probe must not consume a retry');
        $this->assertSame(200, $preview->http_status);
    }

    private function emptySettings(): SettingsRepositoryInterface
    {
        return new class implements SettingsRepositoryInterface {
            public function all(): array { return []; }
            public function get(string $key, mixed $default = null): mixed { return null; }
            public function set(string $key, mixed $value): void {}
            public function delete(string $keyLike): void {}
        };
    }
}
