<?php

// One-time normalisation: give already-fetched FAILURES an `error` value.
//
// Until now a non-2xx response was recorded as a success — http_status was set,
// error stayed NULL, and no metadata was parsed. In the database a WAF block
// was therefore indistinguishable from a site that genuinely has no OpenGraph
// tags, and link-preview:retry-failed (which keys on `error`) would skip every
// one of them. This backfills the marker so those rows become visible to the
// retry path exactly once.
//
// Three groups, all restricted to rows that were actually fetched
// (retrieved_at IS NOT NULL — rows that never ran belong to the sweep):
//
//   * status outside 2xx        → 'http_<status>: server answered <status>'
//   * status exactly 255        → 'legacy_status: ...'. There is no HTTP 255;
//                                 these are rows written while http_status was
//                                 a tinyint, which clamped everything from 256
//                                 up. The real answer is unrecoverable from the
//                                 row, so mark it retryable and let one fetch
//                                 find out.
//   * status NULL               → same treatment, same reason.
//
// Rows with a 2xx and no metadata are left alone: that is a real answer.
// `down` clears only the markers this migration could have written.

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        $db = $schema->getConnection();

        if (! $schema->hasTable('ekumanov_link_previews')) {
            return;
        }

        $db->table('ekumanov_link_previews')
            ->whereNull('error')
            ->whereNotNull('retrieved_at')
            ->where(function ($q) {
                $q->whereNull('http_status')->orWhere('http_status', 255);
            })
            ->update(['error' => 'legacy_status: status unrecoverable, re-fetch to find out']);

        $db->table('ekumanov_link_previews')
            ->whereNull('error')
            ->whereNotNull('retrieved_at')
            ->where(function ($q) {
                $q->where('http_status', '<', 200)->orWhere('http_status', '>=', 300);
            })
            ->update([
                'error' => $db->raw("CONCAT('http_', http_status, ': server answered ', http_status)"),
            ]);
    },

    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        $db = $schema->getConnection();

        if (! $schema->hasTable('ekumanov_link_previews')) {
            return;
        }

        $db->table('ekumanov_link_previews')
            ->where(function ($q) {
                $q->where('error', 'like', 'http\_%: server answered %')
                    ->orWhere('error', 'like', 'legacy\_status: %');
            })
            ->update(['error' => null]);
    },
];
