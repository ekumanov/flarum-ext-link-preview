<?php

namespace Ekumanov\LinkPreview\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Flarum\Post\PostRepository;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/link-previews/posts/{postId}/previews/{previewId}/dismiss
 *
 * Force-hides the inline card for a (post, preview) pair: the link stays in
 * the body and readers can still hover it for a preview overlay, but no
 * card renders in the post. Clears pinned_at — the two overrides are
 * mutually exclusive. Idempotent (re-dismissing returns 204 either way).
 *
 * Permission: any actor who can edit the post — the author (within the
 * forum's edit-time window, enforced by core's editOwnPost policy) or
 * anyone with discussion.editPost (mods, admins).
 */
class DismissPreviewController implements RequestHandlerInterface
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly ConnectionInterface $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getAttribute('routeParameters', []);
        $postId = (int) ($params['postId'] ?? 0);
        $previewId = (int) ($params['previewId'] ?? 0);

        $post = $this->posts->findOrFail($postId, $actor);

        if (! $actor->can('edit', $post)) {
            throw new PermissionDeniedException();
        }

        $this->db->table('ekumanov_link_preview_post')
            ->where('post_id', $postId)
            ->where('preview_id', $previewId)
            ->update(['dismissed_at' => Carbon::now(), 'pinned_at' => null]);

        return new EmptyResponse(204);
    }
}
