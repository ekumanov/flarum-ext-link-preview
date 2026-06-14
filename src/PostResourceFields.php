<?php

namespace Ekumanov\LinkPreview;

use Carbon\Carbon;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Post\Post;
use Illuminate\Support\Arr;

class PostResourceFields
{
    private const YOUTUBE_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtu.be',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
    ];

    public function __invoke(): array
    {
        return [
            Schema\Arr::make('linkPreviews')
                ->get(fn (Post $post, Context $context) => $this->buildPreviews($post, $context)),
        ];
    }

    private function buildPreviews(Post $post, Context $context): array
    {
        if (! $post->relationLoaded('linkPreviews')) {
            return [];
        }

        $canToggle = $context->getActor()->can('edit', $post);

        $previews = [];

        foreach ($post->getRelation('linkPreviews') as $preview) {
            if ($data = $this->buildPreview($preview, $post, $canToggle)) {
                $previews[] = $data;
            }
        }

        return $previews;
    }

    private function buildPreview(Preview $preview, Post $post, bool $canToggle): ?array
    {
        // Image-typed URLs already render inline as <img> via Flarum's formatter.
        if ($preview->mime && str_starts_with($preview->mime, 'image/')) {
            return null;
        }

        $clickUrl = $preview->final_url ?: $preview->url;
        $host = strtolower((string) parse_url($clickUrl, PHP_URL_HOST));

        // Flarum 2.0 already renders an inline player for YouTube, so a card
        // here would be redundant.
        if (in_array($host, self::YOUTUBE_HOSTS, true)) {
            return null;
        }

        // Both overrides are serialized to EVERYONE. Hidden previews (titled
        // links by default, or dismissed raw links) still power the hover
        // overlay for every reader — the front-end decides card-vs-hover from
        // these flags plus how the link is written in the body.
        $dismissed = $preview->pivot && $preview->pivot->dismissed_at !== null;
        $pinned = $preview->pivot && $preview->pivot->pinned_at !== null;

        // Pending: the row exists but the fetch hasn't completed (no error yet,
        // retrieved_at still NULL). Emit a lightweight marker so the front-end
        // reserves the card's footprint with a fixed-size skeleton — this closes
        // the layout shift when a card lands after the post is already on screen
        // (a realtime update, or the author's own first paint before the worker
        // finishes). A stale-pending row — a fetch that never ran because the
        // forum has neither a worker nor cron — stops skeletoning after an hour,
        // so a misconfigured forum degrades to "no card" rather than a permanent
        // skeleton.
        if ($preview->retrieved_at === null && $preview->error === null) {
            if (Carbon::parse($preview->created_at)->lt(Carbon::now()->subHour())) {
                return null;
            }

            return [
                'previewId' => (int) $preview->id,
                'postId' => (int) $post->id,
                'url' => $preview->url,
                'pending' => true,
                'dismissed' => $dismissed,
                'pinned' => $pinned,
                'canToggle' => $canToggle,
            ];
        }

        $og = $preview->opengraph ?: [];
        $fallback = $preview->fallback ?: [];

        $title = Arr::get($og, 'title') ?: Arr::get($fallback, 'title');
        if (! $title) {
            return null;
        }

        $domain = preg_replace('~^www\.~', '', $host);
        $siteName = Arr::get($og, 'site_name') ?: $domain;
        $description = Arr::get($og, 'description') ?: Arr::get($fallback, 'description');

        $image = $this->firstImage($og);

        return [
            'previewId' => (int) $preview->id,
            'postId' => (int) $post->id,
            // url is what we match against post-body <a href>; finalUrl is the click target.
            'url' => $preview->url,
            'finalUrl' => $clickUrl,
            'title' => (string) $title,
            'description' => $description ? (string) $description : null,
            'image' => $image['url'] ?? null,
            // A `brand` image (the forum's social share logo on self-links) is
            // shown as a small favicon next to the site name; a real content
            // thumbnail fills the image slot (cover crop).
            'imageFit' => ($image['brand'] ?? false) ? 'contain' : null,
            'siteName' => (string) $siteName,
            'domain' => $domain,
            'dismissed' => $dismissed,
            'pinned' => $pinned,
            'canToggle' => $canToggle,
        ];
    }

    /**
     * Only the real og:image (or our self-link brand image) is trusted as a
     * card thumbnail. The legacy `fallback.images` column — which historically
     * held every <img> scraped off the page — is NOT read: its first entry is
     * almost always a logo, favicon, or third-party tracking pixel, never the
     * hero image (blurry cards + a guest privacy leak). The current
     * HtmlFallbackParser no longer writes images there.
     *
     * @return array{url:string,brand:bool}|null
     */
    private function firstImage(array $og): ?array
    {
        foreach (Arr::get($og, 'images') ?: [] as $image) {
            $src = Arr::get($image, 'secure_url') ?: Arr::get($image, 'url');
            if ($src) {
                return ['url' => (string) $src, 'brand' => (bool) Arr::get($image, 'brand')];
            }
        }

        return null;
    }
}
