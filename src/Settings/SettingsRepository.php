<?php

namespace Ekumanov\LinkPreview\Settings;

use Ekumanov\LinkPreview\Http\CurlRequestExecutor;
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

    /**
     * User-Agent identities the fetcher may present, most-preferred first.
     * The first is used for every request; the rest are only reached when a
     * fetch comes back bot-blocked (401/403/406/429).
     *
     * The two fallbacks are the social-scraper identities. Sites that block
     * unrecognised clients very often allowlist these *specifically so* link
     * previews work — which is exactly what we are doing — but presenting as
     * them is still borrowed identity. An admin who would rather not can put a
     * single UA in this setting and the chain collapses to one request.
     *
     * @return list<string>
     */
    public function userAgents(): array
    {
        $configured = $this->linesSetting('user_agents');

        return $configured !== [] ? $configured : [
            CurlRequestExecutor::DEFAULT_USER_AGENT,
            'Twitterbot/1.0',
            'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        ];
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

    /**
     * One value per line. Unlike csvSetting() this does NOT split on spaces or
     * commas — User-Agent strings are full of both.
     *
     * @return list<string>
     */
    private function linesSetting(string $key): array
    {
        $raw = (string) ($this->settings->get(self::PREFIX.$key) ?? '');
        $items = preg_split('/\R/', $raw) ?: [];
        $items = array_map('trim', $items);

        return array_values(array_filter($items, fn ($s) => $s !== ''));
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
