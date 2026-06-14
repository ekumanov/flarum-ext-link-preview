<?php

// Creates the two tables this extension owns:
//
//   ekumanov_link_previews      — one row per fetched URL (cached OpenGraph /
//                                 fallback metadata, HTTP status, timestamps).
//   ekumanov_link_preview_post  — pivot linking a preview to the post(s) that
//                                 reference it, plus the per-(post, preview)
//                                 display overrides (dismissed_at / pinned_at).
//
// Idempotent: every create is guarded with hasTable(), so this is a safe no-op
// on an install where the tables are already present — e.g. a data-preserving
// cutover that renamed pre-existing tables into place before enabling the
// extension. `down` is intentionally a no-op: dropping these would destroy
// every cached preview and every post→preview link. Drop manually if desired.

use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_link_previews')) {
            $schema->create('ekumanov_link_previews', function (Blueprint $table) {
                $table->increments('id');
                $table->string('url', 2048);
                $table->binary('url_hash', 20)->nullable()->unique();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('error', 255)->nullable();
                $table->json('opengraph')->nullable();
                $table->json('icons')->nullable();
                $table->json('fallback')->nullable();
                $table->timestamp('created_at');
                $table->timestamp('retrieved_at')->nullable();
                $table->string('final_url', 2048)->nullable();
                $table->string('mime', 64)->nullable();
                $table->json('exif')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->json('api_resource')->nullable();
            });
        }

        if (! $schema->hasTable('ekumanov_link_preview_post')) {
            $schema->create('ekumanov_link_preview_post', function (Blueprint $table) {
                $table->unsignedInteger('preview_id');
                $table->unsignedInteger('post_id');
                $table->boolean('is_link')->default(true);
                // Per-(post, preview) display overrides — mutually exclusive:
                //   dismissed_at — author/mod hid the inline card (the link
                //                  stays; readers still get a hover preview).
                //   pinned_at    — author/mod forced a permanent inline card on
                //                  a link that would otherwise be hover-only.
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamp('pinned_at')->nullable();
                $table->primary(['preview_id', 'post_id']);
                $table->index('post_id');
                $table->foreign('preview_id')->references('id')->on('ekumanov_link_previews')->onDelete('cascade');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            });
        } else {
            // Data-preserving upgrade: the pivot already exists (renamed in place
            // from an older install). Top up any column the running code needs
            // but an older schema may lack — `is_link` is filtered on, and
            // `dismissed_at` / `pinned_at` are eager-loaded via withPivot(), so
            // a missing one is a hard 500. hasColumn-guarded → no-op when present.
            $schema->table('ekumanov_link_preview_post', function (Blueprint $table) use ($schema) {
                if (! $schema->hasColumn('ekumanov_link_preview_post', 'is_link')) {
                    $table->boolean('is_link')->default(true);
                }
                if (! $schema->hasColumn('ekumanov_link_preview_post', 'dismissed_at')) {
                    $table->timestamp('dismissed_at')->nullable();
                }
                if (! $schema->hasColumn('ekumanov_link_preview_post', 'pinned_at')) {
                    $table->timestamp('pinned_at')->nullable();
                }
            });
        }
    },
    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        // No-op on purpose: dropping these would destroy accumulated user data.
    },
];
