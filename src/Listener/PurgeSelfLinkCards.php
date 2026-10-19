<?php

namespace Ekumanov\LinkPreview\Listener;

use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\PreviewChangeNotifier;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;

/**
 * Tells page caches to re-render the posts that link to a discussion or a
 * reply which has just changed.
 *
 * Self-link cards are resolved when they are read, so the API is never stale.
 * A page cache is: guest HTML snapshotted with a card for a reply keeps that
 * card after the reply is hidden, deleted or edited, because nothing about the
 * page that *links* to it changed. Announcing PreviewsChanged for the linking
 * posts is what lets an edge cache purge them — the same event a settled fetch
 * already sends.
 *
 * Listens to (optional extensions' events are listened to by name, so they
 * simply never fire when the extension is absent):
 *   - a discussion hidden, restored, deleted, renamed or re-tagged: every
 *     link to it, since its title and its visibility are on every card;
 *   - a post hidden, restored, deleted, edited or approved: the links that
 *     name that post — and, for the first post, the links that name none,
 *     since its excerpt is the discussion card's description.
 */
final class PurgeSelfLinkCards
{
    public function __construct(
        private readonly LocalDiscussionResolver $local,
        private readonly PreviewChangeNotifier $notifier,
    ) {}

    public function handle(object $event): void
    {
        $post = property_exists($event, 'post') && $event->post instanceof Post ? $event->post : null;
        $discussion = property_exists($event, 'discussion') && $event->discussion instanceof Discussion ? $event->discussion : null;

        if ($post !== null) {
            $this->purge((int) $post->discussion_id, (int) $post->number);
        } elseif ($discussion !== null) {
            $this->purge((int) $discussion->id, null);
        }
    }

    /**
     * @param int|null $number the post that changed, or null for the whole discussion
     */
    private function purge(int $discussionId, ?int $number): void
    {
        if ($discussionId <= 0) {
            return;
        }

        // Narrowed in SQL to the three shapes a link to this discussion takes
        // (`/d/12`, `/d/12-slug…`, `/d/12/…`), then confirmed by the parser:
        // the LIKE alone would also match another forum's `/d/12`.
        $candidates = Preview::query()
            ->where(function ($q) use ($discussionId) {
                $q->where('url', 'like', "%/d/{$discussionId}")
                    ->orWhere('url', 'like', "%/d/{$discussionId}-%")
                    ->orWhere('url', 'like', "%/d/{$discussionId}/%");
            })
            ->get(['id', 'url']);

        $ids = [];

        foreach ($candidates as $preview) {
            $target = $this->local->parseSelfTarget($preview->url);

            if ($target === null || $target['discussion'] !== $discussionId) {
                continue;
            }

            if ($number === null
                || $target['post'] === $number
                || ($number === 1 && $target['post'] === null)) {
                $ids[] = (int) $preview->id;
            }
        }

        $this->notifier->previewsChanged($ids);
    }
}
