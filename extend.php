<?php

use Ekumanov\LinkPreview\Api\Controller\DismissPreviewController;
use Ekumanov\LinkPreview\Api\Controller\PinPreviewController;
use Ekumanov\LinkPreview\Console\BackfillPreviewsCommand;
use Ekumanov\LinkPreview\Console\RefreshSelfLinksCommand;
use Ekumanov\LinkPreview\Console\SweepStuckPreviewsCommand;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\Listener\ScanPostUrls;
use Ekumanov\LinkPreview\PostResourceFields;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Post\Post;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/resources/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Model(Post::class))
        ->relationship('linkPreviews', function (Post $post) {
            return $post
                ->belongsToMany(Preview::class, 'ekumanov_link_preview_post', 'post_id', 'preview_id')
                ->withPivot('dismissed_at', 'pinned_at')
                ->wherePivot('is_link', 1)
                ->where('http_status', 200)
                ->whereNull('error');
        }),

    (new Extend\ApiResource(Resource\PostResource::class))
        ->fields(PostResourceFields::class)
        ->endpoint(
            [Endpoint\Index::class, Endpoint\Show::class, Endpoint\Create::class, Endpoint\Update::class],
            fn ($endpoint) => $endpoint->eagerLoad('linkPreviews')
        ),

    (new Extend\ApiResource(Resource\DiscussionResource::class))
        ->endpoint(
            [Endpoint\Show::class, Endpoint\Index::class],
            fn ($endpoint) => $endpoint->eagerLoadWhenIncluded([
                'posts' => ['posts.linkPreviews'],
                'firstPost' => ['firstPost.linkPreviews'],
                'lastPost' => ['lastPost.linkPreviews'],
            ])
        ),

    // ─── Fetch pipeline ────────────────────────────────────────────────
    //
    // Bind interfaces to default implementations so the container can wire
    // SafeHttpClient end-to-end. Tests instantiate the client directly with
    // fakes; production code resolves via DI.

    (new Extend\ServiceProvider())
        ->register(\Ekumanov\LinkPreview\LinkPreviewServiceProvider::class),

    // Permission: gate URL→preview scanning on the post author's group. Default
    // grant covered by Extend\Policy/Permissions in stock Flarum — we just
    // need to expose the key so admins can toggle it. Members are granted by
    // the standard "members" group permission editor.

    // Event hooks: scan and enqueue on every new/edited post.
    (new Extend\Event())
        ->listen(Posted::class, ScanPostUrls::class)
        ->listen(Revised::class, ScanPostUrls::class),

    // Per-(post, preview) display override: dismiss force-hides the inline
    // card (link becomes hover-only), pin force-shows it (titled link gets
    // a permanent card). Authors can do their own posts (within the edit
    // window); mods/admins can do any. See controllers for the permission
    // gate (single Gate::allows('edit', $post) check covers both).
    (new Extend\Routes('api'))
        ->post('/link-previews/posts/{postId}/previews/{previewId}/dismiss', 'link-preview.dismiss', DismissPreviewController::class)
        ->post('/link-previews/posts/{postId}/previews/{previewId}/pin', 'link-preview.pin', PinPreviewController::class),

    // Default settings — admins override via the admin settings page (v2.0+).
    // Blacklist default is empty: every URL gets a fetch+card unless the
    // admin explicitly excludes a host. Dismiss/restore controls give
    // authors+mods per-card override on top of the host-level setting.
    (new Extend\Settings())
        ->default('ekumanov-link-preview.blacklist', ''),

    // Scheduler safety-net: every 5 min, re-dispatch any placeholder preview
    // rows whose original FetchPreviewJob seems to have been dropped (worker
    // restart, Redis flush, etc.). Also exposed as `php flarum link-preview:sweep`.
    (new Extend\Console())
        ->command(SweepStuckPreviewsCommand::class)
        ->command(BackfillPreviewsCommand::class)
        ->command(RefreshSelfLinksCommand::class)
        ->schedule(SweepStuckPreviewsCommand::class, function (ScheduleEvent $event) {
            $event->everyFiveMinutes()->withoutOverlapping();
        }),
];
