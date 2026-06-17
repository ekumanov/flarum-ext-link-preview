<?php

namespace Ekumanov\LinkPreview\Tests\Integration\Listener;

use Ekumanov\LinkPreview\Http\UrlValidator;
use Ekumanov\LinkPreview\Job\FetchPreviewJob;
use Ekumanov\LinkPreview\Listener\ScanPostUrls;
use Ekumanov\LinkPreview\Listener\UrlExtractor;
use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\LinkPreview\Preview;
use Ekumanov\LinkPreview\RateLimit\UrlSubmissionLimiter;
use Ekumanov\LinkPreview\Settings\SettingsRepository;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\SyncQueue;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * Covers the queue-driver branch in {@see ScanPostUrls} — the behaviour that
 * decides what happens to an external link the instant a post is saved.
 *
 * The contract under test:
 *
 *   - On Flarum's default `sync` queue, the listener must NOT dispatch the
 *     fetch job inline. A SyncQueue runs jobs synchronously, so dispatching
 *     here would block the post-save HTTP request on a ≤10 s remote fetch.
 *     Instead the listener leaves a placeholder row (retrieved_at = NULL) for
 *     the 5-minute scheduled sweep to pick up, where blocking is harmless.
 *
 *   - On any other (async) queue, the job IS pushed so a worker fetches it.
 *
 * In both cases the post-save path materialises the preview row + post pivot
 * synchronously, so the serializer can emit a pending placeholder right away.
 *
 * This is a real integration test — the actual listener, the real UrlExtractor
 * / UrlSubmissionLimiter / LocalDiscussionResolver, real Eloquent models, and a
 * real SQLite database. Only the queue (the thing we're asserting on) and the
 * settings backend (so defaults apply) are doubles.
 */
final class ScanPostUrlsSyncQueueTest extends TestCase
{
    private Capsule $capsule;
    private ConnectionInterface $db;

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        // Make this the connection Eloquent (and therefore Preview) resolves.
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->db = $this->capsule->getConnection();
        $this->createSchema();
    }

    public function test_sync_queue_defers_fetch_and_leaves_a_placeholder(): void
    {
        $url = 'https://other.example.org/article';

        // A SyncQueue subclass — instanceof SyncQueue is true, so the listener
        // takes the "no async worker" branch. push() must never be reached;
        // if it were, the fetch would run inline and block the request.
        $queue = $this->createMock(SyncQueue::class);
        $queue->expects($this->never())->method('push');

        $this->makeListener($queue)->handle($this->postedEventWithLink($url));

        $preview = Preview::query()->where('url', $url)->first();
        $this->assertNotNull($preview, 'a preview placeholder row should be created at post time');
        $this->assertNull(
            $preview->retrieved_at,
            'on sync the fetch is deferred to the sweep, so retrieved_at must stay NULL'
        );

        $pivot = $this->db->table('ekumanov_link_preview_post')
            ->where('post_id', 4242)
            ->where('preview_id', $preview->id)
            ->first();
        $this->assertNotNull($pivot, 'the post↔preview pivot row should be written synchronously');
    }

    public function test_async_queue_dispatches_the_fetch_job(): void
    {
        $url = 'https://other.example.org/article';

        // Any queue that is NOT a SyncQueue: the listener pushes the job for a
        // worker to pop. (createMock on the interface is not a SyncQueue.)
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())
            ->method('push')
            ->with($this->isInstanceOf(FetchPreviewJob::class));

        $this->makeListener($queue)->handle($this->postedEventWithLink($url));

        $preview = Preview::query()->where('url', $url)->first();
        $this->assertNotNull($preview);
        $this->assertNull(
            $preview->retrieved_at,
            'the worker, not the request thread, sets retrieved_at — it is NULL until then'
        );
    }

    private function makeListener(Queue $queue): ScanPostUrls
    {
        // get() returns null for every key → SettingsRepository falls back to
        // its compiled-in defaults (30-day TTL, 20 URLs/hour, empty lists).
        $flarumSettings = $this->createMock(SettingsRepositoryInterface::class);
        $settings = new SettingsRepository($flarumSettings);

        // Forum base differs from the link's host, so the URL is NOT a self-link
        // and the listener reaches the queue branch we're testing.
        $resolver = new LocalDiscussionResolver('https://forum.example.com', $flarumSettings);
        $extractor = new UrlExtractor(new UrlValidator(), $settings, $resolver);
        $limiter = new UrlSubmissionLimiter(new CacheRepository(new ArrayStore()), $settings);

        return new ScanPostUrls(
            $extractor,
            $limiter,
            $settings,
            $queue,
            $this->db,
            new NullLogger(),
            $resolver,
        );
    }

    private function postedEventWithLink(string $url): Posted
    {
        $actor = new User();
        $actor->id = 1;

        // A real CommentPost (so id / attributes behave), with formatContent()
        // overridden to return a fixed rendered body — avoids booting the
        // formatter while exercising the real extraction path. The constructor
        // is left as the parent's (array $attributes = []) so Eloquent's
        // internal `new static()` during model boot still works; the rendered
        // HTML is injected via a declared public property, which bypasses
        // Eloquent's attribute magic.
        $post = new class extends CommentPost {
            public string $renderedHtml = '';

            public function formatContent(?ServerRequestInterface $request = null): string
            {
                return $this->renderedHtml;
            }
        };
        $post->renderedHtml = '<p><a href="'.$url.'">'.$url.'</a></p>';
        $post->id = 4242;

        return new Posted($post, $actor);
    }

    private function createSchema(): void
    {
        $schema = $this->db->getSchemaBuilder();

        $schema->create('ekumanov_link_previews', function (Blueprint $table) {
            $table->increments('id');
            $table->string('url', 2048);
            $table->binary('url_hash')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 255)->nullable();
            $table->text('opengraph')->nullable();
            $table->text('icons')->nullable();
            $table->text('fallback')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->string('final_url', 2048)->nullable();
        });

        $schema->create('ekumanov_link_preview_post', function (Blueprint $table) {
            $table->unsignedInteger('preview_id');
            $table->unsignedInteger('post_id');
            $table->boolean('is_link')->default(true);
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->primary(['preview_id', 'post_id']);
        });
    }
}
