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
 * POST /api/link-previews/posts/{postId}/previews/{previewId}/pin
 *
 * Force-shows the inline card for a (post, preview) pair — the "Show preview
 * in post" action in the hover overlay. This is how titled links (hover-only
 * by default) get a permanent card, and how a previously dismissed raw link
 * gets its card back. Clears dismissed_at — the two overrides are mutually
 * exclusive. Idempotent.
 *
 * Same permission gate as dismissing: $actor->can('edit', $post).
 */
class PinPreviewController implements RequestHandlerInterface
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
            ->update(['pinned_at' => Carbon::now(), 'dismissed_at' => null]);

        return new EmptyResponse(204);
    }
}
