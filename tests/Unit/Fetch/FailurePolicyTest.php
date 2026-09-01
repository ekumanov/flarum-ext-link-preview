<?php

namespace Ekumanov\LinkPreview\Tests\Unit\Fetch;

use Ekumanov\LinkPreview\Fetch\FailurePolicy;
use PHPUnit\Framework\TestCase;

final class FailurePolicyTest extends TestCase
{
    public function test_a_clean_row_is_never_retried(): void
    {
        $this->assertFalse(FailurePolicy::isRetryable(null, 200));
        $this->assertFalse(FailurePolicy::isRetryable('', 200));
    }

    /**
     * The whole point of the change: a bot-block has to be distinguishable
     * from "this site has no OpenGraph tags", and only the former comes back.
     */
    public function test_bot_blocks_are_retryable(): void
    {
        foreach ([401, 403, 406, 429] as $status) {
            $this->assertTrue(
                FailurePolicy::isRetryable(FailurePolicy::httpError($status), $status),
                "status $status should be retryable",
            );
        }
    }

    public function test_transient_server_errors_are_retryable(): void
    {
        foreach ([500, 502, 503, 504] as $status) {
            $this->assertTrue(FailurePolicy::isRetryable(FailurePolicy::httpError($status), $status));
        }
    }

    public function test_settled_answers_are_not_retryable(): void
    {
        foreach ([404, 410, 400, 451] as $status) {
            $this->assertFalse(
                FailurePolicy::isRetryable(FailurePolicy::httpError($status), $status),
                "status $status should not be retryable",
            );
        }
    }

    public function test_transport_failures_are_retryable(): void
    {
        $this->assertTrue(FailurePolicy::isRetryable('timeout: exceeded 10s', 0));
        $this->assertTrue(FailurePolicy::isRetryable('connect_failed: curl errno 7', 0));
        $this->assertTrue(FailurePolicy::isRetryable('dns_failed: nowhere.example', 0));
    }

    public function test_permanent_local_refusals_are_not_retryable(): void
    {
        $this->assertFalse(FailurePolicy::isRetryable('ssrf_private_ip: h -> 10.0.0.1', 0));
        $this->assertFalse(FailurePolicy::isRetryable('body_too_large: exceeded 2097152 bytes', 0));
        $this->assertFalse(FailurePolicy::isRetryable('media_url: no HTML to parse', 0));
        $this->assertFalse(FailurePolicy::isRetryable('self_link_not_viewable', 0));
        $this->assertFalse(FailurePolicy::isRetryable('too_many_redirects: https://a/', 0));
    }

    /**
     * Legacy rows were written when http_status was a tinyint, so every status
     * of 256 or more was clamped to 255. The reason prefix in `error` is the
     * trustworthy field, and it must win over the mangled column.
     */
    public function test_error_prefix_beats_a_clamped_status_column(): void
    {
        $this->assertTrue(FailurePolicy::isRetryable('http_403: server answered 403', 255));
        $this->assertFalse(FailurePolicy::isRetryable('http_404: server answered 404', 255));
    }

    public function test_legacy_clamped_rows_are_retryable_once(): void
    {
        // There is no HTTP 255 — it is a tinyint clamp of some status >= 256.
        // We cannot tell a 403 from a 404 here, so one re-fetch recovers the
        // truth and the normal backoff keeps it from becoming a habit.
        $this->assertTrue(FailurePolicy::isRetryable('legacy_status: status unrecoverable, re-fetch to find out', 255));
        $this->assertTrue(FailurePolicy::isRetryable('legacy_status: status unrecoverable, re-fetch to find out', null));
    }

    /**
     * The predecessor extension stored the exception class as the error. Its
     * network failures are worth another look; its size/decode failures are not.
     */
    public function test_predecessor_extension_residue_is_classified(): void
    {
        $this->assertTrue(FailurePolicy::isRetryable('GuzzleHttp\\Exception\\ConnectException', 200));
        $this->assertTrue(FailurePolicy::isRetryable('GuzzleHttp\\Exception\\RequestException', 200));

        $this->assertFalse(FailurePolicy::isRetryable('Kilowhat\\RichEmbeds\\Exceptions\\BodyTooLarge', 200));
        $this->assertFalse(FailurePolicy::isRetryable('Intervention\\Image\\Exception\\NotReadableException', 200));
        $this->assertFalse(FailurePolicy::isRetryable('GuzzleHttp\\Exception\\TooManyRedirectsException', 200));
        $this->assertFalse(FailurePolicy::isRetryable('RuntimeException', 200));
    }

    public function test_backoff_grows_and_then_plateaus(): void
    {
        $this->assertSame(3600, FailurePolicy::backoffSeconds(0));
        $this->assertSame(3600, FailurePolicy::backoffSeconds(1));
        $this->assertSame(21600, FailurePolicy::backoffSeconds(2));
        $this->assertSame(86400, FailurePolicy::backoffSeconds(3));

        $plateau = FailurePolicy::backoffSeconds(FailurePolicy::MAX_ATTEMPTS);
        $this->assertSame($plateau, FailurePolicy::backoffSeconds(FailurePolicy::MAX_ATTEMPTS + 50));
        $this->assertSame(2592000, $plateau);
    }

    public function test_reason_of_splits_on_the_first_colon(): void
    {
        $this->assertSame('timeout', FailurePolicy::reasonOf('timeout: exceeded 10s'));
        $this->assertSame('self_link_not_viewable', FailurePolicy::reasonOf('self_link_not_viewable'));
        $this->assertSame('http_403', FailurePolicy::reasonOf('http_403: server answered 403'));
    }
}
