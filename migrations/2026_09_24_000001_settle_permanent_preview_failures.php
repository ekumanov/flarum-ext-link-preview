<?php

// One-time normalisation: stamp every already-recorded PERMANENT failure with
// fetch_attempts = FailurePolicy::SETTLED_ATTEMPTS.
//
// link-preview:retry-failed picked its candidates oldest-first and only then
// dropped the permanent ones (404, SSRF refusal, oversized body, media, a
// self-link nobody may read) in PHP. A permanent failure is never retried, so
// its retrieved_at never advances and it stays at the head of that window for
// good — and rows written before fetch_attempts existed sit at 0 or 1, so the
// attempts cap never removed them either. Once they outnumbered the window
// (hundreds on a live install), no retryable row was ever reached again.
// From now on the fetch paths stamp the sentinel as they classify; this
// catches up the rows written before that.
//
// Classification is FailurePolicy::isRetryable() itself, row by row, so the
// migration and the running code cannot disagree about what "permanent"
// means. Only rows that are errored AND were actually fetched are looked at.
// Idempotent: a row already at the sentinel is skipped, and re-running
// classifies the same rows the same way.
//
// `down` returns stamped rows to a single attempt — the original counts are
// not recoverable, and 1 is what a first failure would have recorded.

use Ekumanov\LinkPreview\Fetch\FailurePolicy;

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_link_previews')
            || ! $schema->hasColumn('ekumanov_link_previews', 'fetch_attempts')) {
            return;
        }

        $db = $schema->getConnection();

        $db->table('ekumanov_link_previews')
            ->select('id', 'error', 'http_status')
            ->whereNotNull('error')
            ->whereNotNull('retrieved_at')
            ->where('fetch_attempts', '<', FailurePolicy::SETTLED_ATTEMPTS)
            ->chunkById(500, function ($rows) use ($db) {
                $settled = [];

                foreach ($rows as $row) {
                    $status = $row->http_status === null ? null : (int) $row->http_status;
                    if (! FailurePolicy::isRetryable((string) $row->error, $status)) {
                        $settled[] = (int) $row->id;
                    }
                }

                if ($settled !== []) {
                    $db->table('ekumanov_link_previews')
                        ->whereIn('id', $settled)
                        ->update(['fetch_attempts' => FailurePolicy::SETTLED_ATTEMPTS]);
                }
            });
    },

    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_link_previews')
            || ! $schema->hasColumn('ekumanov_link_previews', 'fetch_attempts')) {
            return;
        }

        $schema->getConnection()->table('ekumanov_link_previews')
            ->where('fetch_attempts', FailurePolicy::SETTLED_ATTEMPTS)
            ->update(['fetch_attempts' => 1]);
    },
];
