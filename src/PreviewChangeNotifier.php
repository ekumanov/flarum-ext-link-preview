<?php

namespace Ekumanov\LinkPreview;

use Ekumanov\LinkPreview\Event\PreviewsChanged;
use Flarum\Post\Post;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns "these preview rows changed" into one PreviewsChanged event carrying
 * the posts that render them. See the event for why anyone would care.
 *
 * Best-effort by construction: telling other extensions about a change must
 * never fail the fetch, the sweep or the pin/dismiss request that caused it.
 */
final class PreviewChangeNotifier
{
    /**
     * Upper bound on posts per event. A preview settles right after it was
     * first posted, when it is linked from one post or a handful; the bound is
     * for the rare URL that half the forum has pasted, where re-rendering the
     * most recent pages is plenty and re-rendering all of them is a stampede.
     */
    public const MAX_POSTS = 50;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * @param list<int> $previewIds
     */
    public function previewsChanged(array $previewIds): void
    {
        $previewIds = array_values(array_unique(array_map('intval', $previewIds)));
        if ($previewIds === []) {
            return;
        }

        try {
            // Newest posts first: those are the pages still being read, and
            // the ones a pending skeleton was most likely captured on.
            $postIds = $this->db->table('ekumanov_link_preview_post')
                ->whereIn('preview_id', $previewIds)
                ->where('is_link', 1)
                ->orderBy('post_id', 'desc')
                ->limit(self::MAX_POSTS)
                ->pluck('post_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($postIds === []) {
                return;
            }

            $posts = Post::query()->whereIn('id', $postIds)->with('discussion')->get()->all();

            $this->dispatch($posts, $previewIds);
        } catch (Throwable $e) {
            $this->log->warning('link-preview: could not announce a preview change', [
                'preview_ids' => $previewIds, 'err' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param list<Post> $posts
     * @param list<int>  $previewIds
     */
    public function postsChanged(array $posts, array $previewIds = []): void
    {
        try {
            $this->dispatch($posts, $previewIds);
        } catch (Throwable $e) {
            $this->log->warning('link-preview: could not announce a preview change', [
                'err' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param list<Post> $posts
     * @param list<int>  $previewIds
     */
    private function dispatch(array $posts, array $previewIds): void
    {
        if ($posts === []) {
            return;
        }

        foreach ($posts as $post) {
            $post->loadMissing('discussion');
        }

        $this->events->dispatch(new PreviewsChanged(array_values($posts), array_values($previewIds)));
    }
}
