<?php

namespace Tests\Feature\Topics;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\Topic;
use App\Services\Topics\TopicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TopicReviewUxRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_saves_current_editor_content_and_sources_in_one_user_action(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Earlier working draft']);
        $payload = $this->payload();

        $this->actingAs($this->admin(), 'admin')->put(route('admin.topics.update', ['topic' => $topic->id]), $payload + ['site' => 'primary', 'expected_version' => 1, 'action' => 'publish', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        $topic->refresh();
        $this->assertNotNull($topic->public_revision_id);
        $this->assertSame($payload['title'], $topic->publicRevision->payload['title']);
        $this->assertSame($payload['intro'], $topic->publicRevision->payload['intro']);
        $this->assertCount(2, $topic->publicRevision->payload['articles']);
    }

    public function test_save_and_publish_replay_returns_the_original_result_and_protects_newer_state(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Replay draft']);
        $input = $this->payload() + ['site' => 'primary', 'expected_version' => 1, 'action' => 'publish', 'request_key' => (string) Str::uuid()];
        $this->actingAs($this->admin(), 'admin')->put(route('admin.topics.update', ['topic' => $topic->id]), $input)->assertRedirect()->assertSessionHasNoErrors();
        app(TopicService::class)->withdraw($topic->fresh());
        $this->put(route('admin.topics.update', ['topic' => $topic->id]), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $topic->fresh()->draft_version);
        $this->assertNull($topic->fresh()->public_revision_id);
        $this->assertSame(1, $topic->revisions()->count());
        $this->put(route('admin.topics.update', ['topic' => $topic->id]), array_replace($input, ['intro' => 'Different input']))->assertRedirect()->assertSessionHasErrors('request_key');
        $this->assertSame($input['intro'], $topic->fresh()->draft_payload['intro']);
    }

    public function test_new_topic_save_and_publish_replay_creates_one_topic(): void
    {
        $input = $this->payload() + ['site' => 'primary', 'action' => 'publish', 'request_key' => (string) Str::uuid()];
        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.store'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.topics.store'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Topic::query()->count());
        $this->assertSame(1, Topic::query()->firstOrFail()->revisions()->count());
    }

    public function test_publish_failure_preserves_the_current_saved_draft_and_field_guidance(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Draft before correction']);

        $this->actingAs($this->admin(), 'admin')->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'expected_version' => 1, 'title' => 'Current incomplete draft', 'intro' => 'The current introduction is retained.', 'template_key' => 'default', 'action' => 'publish', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasErrors('articles');

        $this->assertSame('Current incomplete draft', $topic->fresh()->title);
        $this->assertNull($topic->fresh()->public_revision_id);
        $this->get(route('admin.topics.edit', ['topic' => $topic->id]))->assertSee('The current introduction is retained.')->assertSee('工作稿已保存');
    }

    public function test_trash_restore_appears_as_a_draft_and_does_not_resume_maintenance(): void
    {
        $topic = app(TopicService::class)->create('primary', $this->payload());
        app(TopicService::class)->publish($topic, 1);
        app(TopicService::class)->withdraw($topic->fresh());
        $topic->refresh()->delete();

        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'restore']), ['site' => 'primary'])->assertRedirect()->assertSessionHasNoErrors();

        $restored = $topic->fresh();
        $this->assertNull($restored->deleted_at);
        $this->assertNull($restored->withdrawn_at);
        $this->assertNull($restored->public_revision_id);
        $this->assertNotNull($restored->maintenance_paused_at);
        $this->get(route('admin.topics.index', ['site' => 'primary', 'status' => 'draft']))->assertOk()->assertSee($topic->title);
    }

    public function test_multiple_homepage_modules_render_the_topic_collection_once(): void
    {
        $topic = app(TopicService::class)->create('primary', $this->payload());
        app(TopicService::class)->publish($topic, 1);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'homepage_modules'], ['setting_value' => json_encode([
            ['type' => 'rich_text', 'enabled' => true, 'title' => 'Introduction module', 'body' => 'Readable introduction'],
            ['type' => 'chart_band', 'enabled' => true, 'title' => 'Chart module', 'body' => 'Example | 12 | Measurement'],
            ['type' => 'topic_collection', 'enabled' => true, 'title' => 'Topics'],
        ])]);

        $html = $this->get('/')->assertOk()->assertSee('Introduction module')->assertSee('Chart module')->getContent();

        $this->assertSame(1, substr_count($html, 'id="home-topic-heading"'));
    }

    public function test_frontend_keeps_a_single_auxiliary_information_fold_and_limits_visible_tags(): void
    {
        $topic = app(TopicService::class)->create('primary', $this->payload() + ['tags' => array_map(fn ($n) => 'Visible-tag-'.$n, range(1, 12))]);
        app(TopicService::class)->publish($topic, 1);

        $html = $this->get('/topics/'.$topic->slug)->assertOk()->assertSee('查看专题信息与来源')->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $tags = (new \DOMXPath($document))->query('//div[contains(concat(" ", normalize-space(@class), " "), " topic-tags ")]//a');
        $this->assertCount(8, $tags);
        $heroTags = (new \DOMXPath($document))->query('//nav[contains(concat(" ", normalize-space(@class), " "), " topic-hero-tags ")]//a');
        $this->assertCount(4, $heroTags);
        $this->assertSame(1, substr_count($html, 'data-topic-auxiliary'));
        $this->assertCount(12, $topic->fresh()->draft_payload['tags']);
    }

    public function test_editor_returns_to_its_filtered_list_and_rejects_external_return_urls(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Filtered topic']);
        $list = route('admin.topics.index', ['site' => 'primary', 'status' => 'draft', 'search' => 'Filtered', 'page' => 2]);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.topics.edit', ['topic' => $topic->id, 'list_return' => $list]))->assertOk()->assertSee('href="'.e($list).'"', false);
        foreach (['admin.topics.articles', 'admin.topics.settings', 'admin.topics.preview', 'admin.topics.history'] as $page) {
            $this->get(route($page, ['topic' => $topic->id, 'site' => 'primary', 'list_return' => $list]))->assertOk()->assertSee(e(route('admin.topics.edit', ['topic' => $topic->id, 'list_return' => $list])), false);
        }
        $this->get(route('admin.topics.edit', ['topic' => $topic->id, 'list_return' => 'https://outside.example.test/topics']))->assertOk()->assertDontSee('https://outside.example.test/topics')->assertSee('href="'.e(route('admin.topics.index', ['site' => 'primary'])).'"', false);
    }

    public function test_same_name_topics_show_their_distinct_scopes_in_the_list(): void
    {
        app(TopicService::class)->create('primary', ['title' => 'Same readable title', 'summary' => ['scope' => 'Beginner reading range']]);
        app(TopicService::class)->create('primary', ['title' => 'Same readable title', 'summary' => ['scope' => 'Administrator practice range']]);
        $this->actingAs($this->admin(), 'admin')->get(route('admin.topics.index'))->assertOk()->assertSee('范围：Beginner reading range')->assertSee('范围：Administrator practice range');
    }

    public function test_empty_ai_configuration_has_a_safe_return_to_editor(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Manual topic']);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.topics.edit', ['topic' => $topic->id]))->assertOk()->assertSee('配置 AI 模型')->assertSee('手工编辑和发布可以继续')->assertSee('data-preserve-topic', false);
    }

    private function admin(): Admin
    {
        return Admin::query()->create(['username' => 'review-'.Str::random(8), 'password' => 'test-only-password', 'email' => Str::random(8).'@example.test', 'role' => 'super_admin', 'status' => 'active']);
    }

    private function payload(): array
    {
        $category = Category::query()->firstOrCreate(['slug' => 'review-sources'], ['name' => 'Review sources']);
        $author = Author::query()->firstOrCreate(['email' => 'review-source@example.test'], ['name' => 'Sample editor']);
        $articles = [];
        foreach (range(1, 2) as $number) {
            $articles[] = ['article_id' => Article::query()->create(['title' => 'Sample source '.$number, 'slug' => 'review-'.Str::random(10), 'content' => 'Public distinct source body '.$number, 'excerpt' => 'Sample source', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()])->id];
        }

        return ['title' => 'Current editor title', 'intro' => 'Current editor introduction.', 'template_key' => 'default', 'articles' => $articles];
    }
}
