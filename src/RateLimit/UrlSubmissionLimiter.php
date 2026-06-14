<?php

namespace Ekumanov\LinkPreview\RateLimit;

use Ekumanov\LinkPreview\Settings\SettingsRepository;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Cache-backed sliding-window counter for "how many new URLs has this user
 * submitted in the last hour."
 *
 * The intent isn't to throttle honest posting — it's to bound the worst case
 * a compromised or scripted account can inflict on the fetch worker. Once an
 * actor exceeds their hourly budget, further URLs from their posts are dropped
 * (no placeholder rows, no jobs enqueued) for the rest of the window. Existing
 * cards keep rendering — only NEW fetches are gated.
 *
 * Per-IP limiting for guests is intentionally out of scope for v1 — on forums
 * that don't allow guest posting there's no surface, and where they do, the
 * per-user check still covers the bulk of the abuse case.
 */
final class UrlSubmissionLimiter
{
    private const WINDOW_SEC = 3600;

    public function __construct(
        private readonly Cache $cache,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Attempt to reserve $count URLs for $actor. Returns the number actually
     * allowed (0..$count). The caller should only enqueue that many.
     */
    public function consume(User $actor, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        $budget = $this->settings->userRateLimitPerHour();
        if ($budget <= 0) {
            return 0;
        }

        $key = 'linkpreview.rl.'.$actor->id;
        $used = (int) $this->cache->get($key, 0);
        $remaining = max(0, $budget - $used);
        $allowed = min($remaining, $count);

        if ($allowed > 0) {
            // put() resets the TTL each write. That gives a sliding-window
            // approximation — within one busy hour the window doesn't reset
            // mid-stream, which is what we want.
            $this->cache->put($key, $used + $allowed, self::WINDOW_SEC);
        }

        return $allowed;
    }
}
