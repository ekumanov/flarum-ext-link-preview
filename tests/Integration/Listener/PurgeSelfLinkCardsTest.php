<?php

namespace Ekumanov\LinkPreview\Tests\Integration\Listener;

use Ekumanov\LinkPreview\Event\PreviewsChanged;
use Ekumanov\LinkPreview\Listener\PurgeSelfLinkCards;
use Ekumanov\LinkPreview\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\LinkPreview\PreviewChangeNotifier;
use Flarum\Settings\UninstalledSettingsRepository;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Which linking posts get re-rendered when the discussion or reply a
 * self-link points at changes. The contract: the posts whose cards would now
 * read differently, and no others.
 */
final class PurgeSelfLinkCardsTest extends TestCase
{
    private const BASE = 'https://myforum.com/forum';

    private ConnectionInterface $db;

    /** @var list<PreviewsChanged> */
    private array $events = [];

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->db = $capsule->getConnection();
        $this->createSchema();

        // Post only loads registered types; core registers these at boot.
        Post::setModel('comment', CommentPost::class);

        // Linking posts 101..105, each in its own discussion 901..905.
        $this->link(101, self::BASE.'/d/12-topic');        // the discussion
        $this->link(102, self::BASE.'/d/12-topic/7');      // reply 7
        $this->link(103, self::BASE.'/d/12/1');            // the first post, by number
        $this->link(104, self::BASE.'/d/123-other/7');     // a different discussion
        $this->link(105, 'https://elsewhere.org/d/12/7');  // another site's /d/12
    }

    public function test_a_changed_reply_rerenders_only_links_to_that_reply(): void
    {
        $this->listener()->handle($this->postEvent(12, 7));

        $this->assertSame([102], $this->purgedPostIds());
    }

    public function test_a_changed_first_post_rerenders_discussion_links_too(): void
    {
        // The first post's excerpt is the discussion card's description.
        $this->listener()->handle($this->postEvent(12, 1));

        $this->assertSame([101, 103], $this->purgedPostIds());
    }

    public function test_a_changed_discussion_rerenders_every_link_to_it(): void
    {
        $discussion = new Discussion();
        $discussion->id = 12;

        $this->listener()->handle(new class($discussion) {
            public function __construct(public Discussion $discussion) {}
        });

        $this->assertSame([101, 102, 103], $this->purgedPostIds());
    }

    public function test_nothing_is_announced_when_nothing_links_there(): void
    {
        $this->listener()->handle($this->postEvent(55, 3));

        $this->assertSame([], $this->events);
    }

    private function listener(): PurgeSelfLinkCards
    {
        $events = new Dispatcher();
        $events->listen(PreviewsChanged::class, function (PreviewsChanged $e) {
            $this->events[] = $e;
        });

        return new PurgeSelfLinkCards(
            new LocalDiscussionResolver(self::BASE, new UninstalledSettingsRepository()),
            new PreviewChangeNotifier($this->db, $events, new NullLogger()),
        );
    }

    private function postEvent(int $discussionId, int $number): object
    {
        $post = new CommentPost();
        $post->discussion_id = $discussionId;
        $post->number = $number;

        return new class($post) {
            public function __construct(public CommentPost $post) {}
        };
    }

    /** @return list<int> */
    private function purgedPostIds(): array
    {
        $ids = [];
        foreach ($this->events as $event) {
            foreach ($event->posts as $post) {
                $ids[] = (int) $post->id;
            }
        }
        sort($ids);

        return $ids;
    }

    private function link(int $postId, string $url): void
    {
        $previewId = $this->db->table('ekumanov_link_previews')->insertGetId([
            'url' => $url,
            'http_status' => 200,
        ]);
        $this->db->table('ekumanov_link_preview_post')->insert([
            'preview_id' => $previewId, 'post_id' => $postId, 'is_link' => 1,
        ]);
        $this->db->table('discussions')->insert(['id' => $postId + 800, 'title' => 'Linking '.$postId]);
        $this->db->table('posts')->insert([
            'id' => $postId, 'discussion_id' => $postId + 800, 'number' => 2, 'type' => 'comment',
        ]);
    }

    private function createSchema(): void
    {
        $schema = $this->db->getSchemaBuilder();

        $schema->create('ekumanov_link_previews', function (Blueprint $table) {
            $table->increments('id');
            $table->string('url', 2048);
            $table->unsignedSmallInteger('http_status')->nullable();
        });

        $schema->create('ekumanov_link_preview_post', function (Blueprint $table) {
            $table->unsignedInteger('preview_id');
            $table->unsignedInteger('post_id');
            $table->boolean('is_link')->default(true);
            $table->primary(['preview_id', 'post_id']);
        });

        $schema->create('discussions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
        });

        $schema->create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('discussion_id');
            $table->unsignedInteger('number');
            $table->string('type');
        });
    }
}
