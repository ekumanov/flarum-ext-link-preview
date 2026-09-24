<?php

// Two bookkeeping columns on ekumanov_link_previews, neither of which the post
// relationship filters on:
//
//   refresh_error     — why the most recent RE-fetch of a row that already had
//                       a good preview failed. A failed refresh used to
//                       overwrite `error`/`http_status`, which the relationship
//                       does filter on, so one flaky TTL refresh removed the
//                       card from every post linking the URL. The good data now
//                       stays put and the failure is recorded here instead.
//                       Cleared by the next successful fetch.
//
//   sweep_dispatches  — how many times link-preview:sweep has re-dispatched a
//                       fetch for this row without the job ever reaching an
//                       outcome. A job that overruns its timeout kills the
//                       worker and leaves the row pending, and the sweep used to
//                       re-dispatch it every five minutes for six hours. After a
//                       few tries the sweep now gives up and records a failure
//                       instead. Reset by the job whenever it finishes.
//
// hasColumn-guarded, so a safe no-op when re-run. `down` drops both: neither
// carries data worth keeping.

use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_link_previews')) {
            return;
        }

        $schema->table('ekumanov_link_previews', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('ekumanov_link_previews', 'refresh_error')) {
                $table->string('refresh_error', 255)->nullable();
            }
            if (! $schema->hasColumn('ekumanov_link_previews', 'sweep_dispatches')) {
                $table->unsignedTinyInteger('sweep_dispatches')->default(0);
            }
        });
    },

    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_link_previews')) {
            return;
        }

        foreach (['refresh_error', 'sweep_dispatches'] as $column) {
            if ($schema->hasColumn('ekumanov_link_previews', $column)) {
                $schema->table('ekumanov_link_previews', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    },
];
