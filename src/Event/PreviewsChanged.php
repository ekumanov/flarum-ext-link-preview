<?php

namespace Ekumanov\LinkPreview\Event;

use Flarum\Post\Post;

/**
 * Something about how one or more posts render their link previews changed
 * AFTER the post itself was saved.
 *
 * Why this exists: a post's card is not final when Posted/Revised fire. The
 * fetch runs later on the queue, so anything that snapshots rendered pages on
 * a post event — an edge cache purging guest HTML, typically — captures the
 * card in its `pending` skeleton and serves that for as long as the snapshot
 * lives. The same goes for a pin/dismiss, and for a failed fetch that the
 * retry pass later recovers: none of them is a post event, so nothing else
 * says "re-render this page".
 *
 * Dispatched when:
 *   - a fetch settles a preview that was rendering as a skeleton (pending →
 *     card, or pending → nothing), or flips a preview between renderable and
 *     not (a recovered failure, a self-link whose discussion went private);
 *   - the sweep gives up on a preview whose fetch never finished;
 *   - an author or moderator pins or dismisses a card.
 *
 * One event per job / command run / request, never one per post, so a
 * listener can batch its work. `posts` is bounded (see PreviewChangeNotifier)
 * — a preview linked from hundreds of posts yields the most recent ones only.
 *
 * Deliberately a plain Flarum event with no listener in this extension: other
 * extensions subscribe if they care, and nothing here depends on them.
 */
final class PreviewsChanged
{
    /**
     * @param list<Post> $posts      posts whose rendered previews changed,
     *                               with their `discussion` relation loaded
     * @param list<int>  $previewIds the preview rows involved (for a
     *                               pin/dismiss, the one that was toggled)
     */
    public function __construct(
        public readonly array $posts,
        public readonly array $previewIds = [],
    ) {}

    /**
     * Convenience for listeners that work per discussion (a page cache does).
     *
     * @return list<int>
     */
    public function discussionIds(): array
    {
        $ids = [];

        foreach ($this->posts as $post) {
            if ($post->discussion_id !== null) {
                $ids[(int) $post->discussion_id] = true;
            }
        }

        return array_keys($ids);
    }
}
