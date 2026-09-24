<?php

namespace Ekumanov\LinkPreview\Fetch;

/**
 * Decides whether a failed fetch is worth trying again, and how long to wait.
 *
 * The distinction this class exists to draw: "the site said no to *us*" is a
 * temporary condition worth revisiting, while "there is nothing here to fetch"
 * is settled. Before this existed, every non-2xx response was recorded as a
 * success with an empty card — a WAF block and a site with genuinely no
 * OpenGraph tags looked identical in the database, and neither was ever
 * retried, so a blocked URL stayed blank forever.
 *
 * Backoff is per-row and stored as `fetch_attempts`, so a URL that keeps
 * failing backs off to monthly rather than being re-tried on every sweep.
 */
final class FailurePolicy
{
    /** Give up after this many consecutive failures (~40 days of backoff). */
    public const MAX_ATTEMPTS = 6;

    /**
     * `fetch_attempts` value written on a failure that will never be retried
     * (404, SSRF refusal, oversized body, a self-link nobody may read...).
     *
     * Why a sentinel rather than leaving the counter alone: the retry pass has
     * to pick its candidates in SQL, and the retryable/permanent split lives
     * here, in PHP. Before this, permanent failures were fetched and discarded
     * on every run — and since they are never retried their `retrieved_at`
     * never advances, so they sat at the head of the oldest-first window
     * forever. Once there were more of them than the window held, no retryable
     * row was ever reached again. Stamping them at classification time lets
     * `fetch_attempts < MAX_ATTEMPTS` exclude them where the rows are.
     *
     * Far above any sane --max-attempts, well inside the smallint column. A
     * later successful fetch resets the counter to 0 like any other.
     */
    public const SETTLED_ATTEMPTS = 1000;

    /**
     * Failure reasons that can plausibly resolve themselves. Recorded in the
     * `error` column as "<reason>: <detail>", so we match on the prefix.
     */
    private const RETRYABLE_REASONS = [
        'timeout',
        'connect_failed',
        'dns_failed',
        'protocol_error',
        // Rows written before http_status was widened from tinyint: every
        // status of 256 or more was clamped to 255, so the real answer is
        // unknowable from the row. One re-fetch recovers the truth, and the
        // normal backoff stops it becoming a habit.
        'legacy_status',
        // A fetch job that was dispatched repeatedly and never finished — the
        // worker died under it, or the queue was down. Written by the sweep
        // when it gives up re-dispatching. Retryable because the second cause
        // is an outage, not a verdict on the URL; the normal backoff keeps the
        // first from becoming a loop.
        'stuck',
        // Residue from the predecessor extension, which stored the exception
        // class as the error. Only the network-level ones are here, matching
        // how we classify our own connect_failed / protocol_error; its
        // BodyTooLarge and image-decode failures stay permanent.
        'GuzzleHttp\\Exception\\ConnectException',
        'GuzzleHttp\\Exception\\RequestException',
    ];

    /**
     * Statuses worth another look later: the bot-blocks (the site may
     * allowlist us, or the reputation of our IP may improve), plus the
     * transient server-side and rate-limit families. A 404/410 is not here —
     * that answer will not change.
     */
    private const RETRYABLE_STATUSES = [401, 403, 406, 408, 425, 429, 500, 502, 503, 504];

    /**
     * Wait before attempt N+1, indexed by how many attempts have already
     * failed. Deliberately steep: a site that has blocked us three times is
     * not going to change its mind in an hour, and we would rather spend the
     * requests on URLs that might work.
     *
     * @var list<int>
     */
    private const BACKOFF_SECONDS = [
        3600,        // 1 hour
        21600,       // 6 hours
        86400,       // 1 day
        259200,      // 3 days
        604800,      // 7 days
        2592000,     // 30 days
    ];

    /**
     * A row is retryable when its recorded failure is one of the transient
     * kinds. $error is the `error` column; $httpStatus the `http_status`.
     */
    public static function isRetryable(?string $error, ?int $httpStatus): bool
    {
        if ($error === null || $error === '') {
            return false; // a clean fetch — nothing to retry
        }

        $reason = self::reasonOf($error);

        if (in_array($reason, self::RETRYABLE_REASONS, true)) {
            return true;
        }

        // "http_<status>" — recorded when the fetch succeeded at the transport
        // level but the server answered with something we can't build a card
        // from. Prefer the parsed reason over the column so a row whose status
        // was mangled by an older schema still classifies correctly.
        if (str_starts_with($reason, 'http_')) {
            $httpStatus = (int) substr($reason, 5);
        }

        return $httpStatus !== null && in_array($httpStatus, self::RETRYABLE_STATUSES, true);
    }

    /**
     * The `fetch_attempts` value to record after a failure: one more for a
     * failure worth revisiting, the settled sentinel for one that is not.
     */
    public static function attemptsAfterFailure(string $error, ?int $httpStatus, int $previousAttempts): int
    {
        return self::isRetryable($error, $httpStatus)
            ? min($previousAttempts + 1, self::SETTLED_ATTEMPTS - 1)
            : self::SETTLED_ATTEMPTS;
    }

    /** Seconds to wait after $attempts consecutive failures. */
    public static function backoffSeconds(int $attempts): int
    {
        $index = max(0, min($attempts, count(self::BACKOFF_SECONDS)) - 1);

        return self::BACKOFF_SECONDS[$index];
    }

    /** The machine-readable prefix of an "<reason>: <detail>" error string. */
    public static function reasonOf(string $error): string
    {
        $colon = strpos($error, ':');

        return $colon === false ? $error : substr($error, 0, $colon);
    }

    /** Canonical error string for a non-2xx response. */
    public static function httpError(int $status): string
    {
        return 'http_'.$status.': server answered '.$status;
    }
}
