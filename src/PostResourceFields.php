<?php

namespace Ekumanov\LinkPreview;

use Carbon\Carbon;
use Ekumanov\LinkPreview\Parser\IconPicker;
use Ekumanov\LinkPreview\Settings\SettingsRepository;
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

    public function __construct(
        private readonly IconPicker $icons,
        private readonly SettingsRepository $settings,
    ) {}

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
        $imageUrl = $image['url'] ?? null;
        $favicon = $this->favicon($preview, $clickUrl);

        // A site that declares one file as both its og:image and its favicon
        // has handed us a brand mark, not a picture of anything. Blown up to
        // fill the card's image slot it is just a magnified logo — and on a
        // phone, where that slot is a full-width 1.91:1 banner, a square logo
        // loses its top and bottom to the crop. Treated as a brand mark it
        // becomes the 18px mark beside the site name and the card collapses to
        // the compact form every messenger uses for a link with no real
        // thumbnail.
        //
        // Matched against the page's *declared* icons rather than against the
        // icon we settled on: an icon rejected for weight is still the site's
        // logo, and those are exactly the ones worth catching.
        $isOwnIcon = $imageUrl !== null
            && $this->icons->matchesAnyIcon($preview->icons, $clickUrl, $imageUrl);

        // The forum's own share logo on a self-link. Served unconditionally —
        // it is our asset on our own domain, not something we hot-link.
        $isSelfBrand = (bool) ($image['brand'] ?? false);

        // A brand mark never fills the big slot. It fills the 18px one when it
        // is light enough to be worth serving, and otherwise the card simply
        // has no picture: a 292 KB logo squeezed into an 18-pixel box would be
        // the worst of both, and the site name already says everything that
        // logo would.
        $siteMark = $isSelfBrand ? ($favicon ?? $imageUrl) : $favicon;
        $isBrand = $isSelfBrand || $isOwnIcon;

        return [
            'previewId' => (int) $preview->id,
            'postId' => (int) $post->id,
            // url is what we match against post-body <a href>; finalUrl is the click target.
            'url' => $preview->url,
            'finalUrl' => $clickUrl,
            'title' => (string) $title,
            'description' => $description ? (string) $description : null,
            'image' => $isBrand ? null : $imageUrl,
            // The site mark. Never fills the big image slot (see firstImage's
            // docblock) — it goes in the 18x18 box beside the site name, which
            // the front-end reserves whether or not this is set, so a card that
            // gains one shifts nothing. A brand image is already occupying
            // that box, so the two are mutually exclusive.
            'favicon' => $siteMark,
            'siteName' => (string) $siteName,
            'domain' => $domain,
            'dismissed' => $dismissed,
            'pinned' => $pinned,
            'canToggle' => $canToggle,
        ];
    }

    /**
     * One absolute https icon URL for this row, or null. The `icons` column
     * has been populated since the extension shipped — this is the first time
     * it reaches a reader, so ~2390 existing production rows light up with no
     * re-fetching at all.
     */
    private function favicon(Preview $preview, string $baseUrl): ?string
    {
        if (! $this->settings->showFavicons()) {
            return null;
        }

        return $this->icons->pick($preview->icons, $baseUrl, $this->settings->faviconMaxBytes());
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
