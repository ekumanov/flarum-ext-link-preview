<?php

namespace Ekumanov\LinkPreview\Settings;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Typed accessor for ekumanov-link-preview.* settings.
 *
 * Defaults are encoded here, not in a migration — no DB rows are written
 * unless the admin actively changes a value. Anything missing falls back to
 * the safe baseline.
 */
final class SettingsRepository
{
    public const PREFIX = 'ekumanov-link-preview.';

    public function __construct(private readonly SettingsRepositoryInterface $settings) {}

    /** Seconds before a cached preview is eligible for re-fetch. Default 30 days. */
    public function ttlSeconds(): int
    {
        return $this->intSetting('ttl_seconds', 60 * 60 * 24 * 30);
    }

    /** Max URLs a single user (or guest IP) may submit for fetching per hour. */
    public function userRateLimitPerHour(): int
    {
        return $this->intSetting('user_rate_per_hour', 20);
    }

    /** Max URLs we'll extract+enqueue from a single post body. Caps bulk-import abuse. */
    public function maxUrlsPerPost(): int
    {
        return $this->intSetting('max_urls_per_post', 10);
    }

    /**
     * Hostname allowlist. If non-empty, ONLY these hosts are fetched.
     * Hostnames match case-insensitively; subdomain match is exact.
     *
     * @return list<string>
     */
    public function whitelist(): array
    {
        return $this->csvSetting('whitelist');
    }

    /**
     * Hostname blocklist. Hosts matching are never fetched. Applied after
     * whitelist (if both are configured, whitelist wins — blocklist is a
     * second-line filter for noisy domains in an open setup).
     *
     * @return list<string>
     */
    public function blacklist(): array
    {
        return $this->csvSetting('blacklist');
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get(self::PREFIX.$key);
        return $v === null || $v === '' ? $default : (int) $v;
    }

    /**
     * @return list<string>
     */
    private function csvSetting(string $key): array
    {
        $raw = (string) ($this->settings->get(self::PREFIX.$key) ?? '');
        if ($raw === '') {
            return [];
        }
        $items = preg_split('/[\s,;]+/', $raw) ?: [];
        $items = array_map(fn ($s) => strtolower(trim($s)), $items);
        $items = array_filter($items, fn ($s) => $s !== '');
        return array_values(array_unique($items));
    }
}
