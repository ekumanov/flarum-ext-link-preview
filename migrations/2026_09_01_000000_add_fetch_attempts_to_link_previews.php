<?php

// Adds `fetch_attempts` — the number of consecutive failed fetches for a URL.
//
// Retry backoff is derived from it (see Fetch\FailurePolicy): a row that keeps
// failing is re-tried after 1h, then 6h, 1d, 3d, 7d, 30d, then left alone.
// Reset to 0 on every successful fetch.
//
// hasColumn-guarded, so this is a safe no-op on an install that already has
// it. `down` drops the column: it carries no data worth preserving, and its
// absence just means every failed row looks like a first attempt again.

use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        if ($schema->hasTable('ekumanov_link_previews')
            && ! $schema->hasColumn('ekumanov_link_previews', 'fetch_attempts')) {
            $schema->table('ekumanov_link_previews', function (Blueprint $table) {
                $table->unsignedSmallInteger('fetch_attempts')->default(0);
            });
        }
    },

    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        if ($schema->hasTable('ekumanov_link_previews')
            && $schema->hasColumn('ekumanov_link_previews', 'fetch_attempts')) {
            $schema->table('ekumanov_link_previews', function (Blueprint $table) {
                $table->dropColumn('fetch_attempts');
            });
        }
    },
];
