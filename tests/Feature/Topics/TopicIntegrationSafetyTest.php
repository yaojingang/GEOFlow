<?php

namespace Tests\Feature\Topics;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\Task;
use App\Models\ThemeWorkspace;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\Topic;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Api\ThemeWorkspaceService;
use App\Services\Topics\TopicFreshnessService;
use App\Services\Topics\TopicNamespaceGuard;
use App\Services\Topics\TopicPathService;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicTaskService;
use App\Services\Topics\TopicThemeCompatibility;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TopicIntegrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_api_tasks_write_without_publish_scope_cannot_activate_auto_publish(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $model = $this->model();
        $library = TitleLibrary::query()->create(['name' => 'Scoped API topic titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        $token = $actor->createToken('topic-no-publish', ['tasks:write', 'tasks:read'])->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/tasks', [
            'content_type' => 'topic', 'name' => 'Topic API scope check', 'target_site_key' => 'primary',
            'title_library_id' => $library->id, 'ai_model_id' => $model->id, 'topic_limit' => 3,
            'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'auto_publish'],
        ]);
        $response->assertForbidden();
        $this->assertFalse(Task::query()->where('name', 'Topic API scope check')->exists());

        return;
        $task = Task::query()->where('name', 'Topic API scope check')->firstOrFail();
        $this->assertFalse($task->status === 'active' && $task->topic_settings['after'] === 'auto_publish' && ! $task->need_review, 'tasks:write token activated autonomous publication without articles:publish');
    }

    public function test_token_without_publication_scope_saves_review_topic_and_rejects_explicit_auto_update(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $token = $actor->createToken('topic-scope', ['tasks:read', 'tasks:write'])->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/tasks', ['content_type' => 'topic', 'name' => 'Safe default topic', 'target_site_key' => 'primary', 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'paused']);
        $response->assertCreated();
        $task = Task::query()->where('name', 'Safe default topic')->firstOrFail();
        $this->assertSame('review_then_publish', $task->topic_settings['after']);
        $this->patchJson('/api/v1/tasks/'.$task->id, ['topic_config_version' => $task->topic_config_version, 'topic_settings' => ['after' => 'auto_publish']])->assertForbidden();
        $this->assertSame('review_then_publish', $task->fresh()->topic_settings['after']);
    }

    public function test_topic_job_receipt_has_the_real_topic_type_and_permissions(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $lib = TitleLibrary::query()->create(['name' => 'Receipt titles']);
        Title::query()->create(['library_id' => $lib->id, 'title' => 'GEO receipt']);
        $task = app(TopicTaskService::class)->save(['name' => 'Receipt task', 'target_site_key' => 'primary', 'title_library_id' => $lib->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active'], $actor);
        $token = $actor->createToken('receipt', ['tasks:write', 'articles:publish'])->plainTextToken;
        $key = (string) Str::uuid();
        $this->withHeader('Authorization', 'Bearer '.$token)->withHeader('X-Client-Request-Id', $key)->postJson('/api/v1/tasks/'.$task->id.'/enqueue', ['job_type' => 'generate_topic'])->assertCreated();
        $this->postJson('/api/v1/tasks/'.$task->id.'/enqueue', ['job_type' => 'generate_topic'])->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame('generate_topic', $task->taskRuns()->firstOrFail()->meta['job_type']);
    }

    public function test_legacy_article_multi_segment_topics_url_and_retired_link_are_preserved(): void
    {
        $source = $this->article(1);
        $pattern = '/topics/{year}/{slug}.html';
        $raw = ['schema_version' => 1, 'revision' => 1, 'current_pattern' => $pattern, 'history' => []];
        SiteSetting::query()->updateOrCreate(['setting_key' => 'article_permalink_policy'], ['setting_value' => json_encode($raw)]);
        SiteSettingsBag::forget();
        $path = '/topics/'.$source->created_at->format('Y').'/'.$source->slug.'.html';
        $this->get($path)->assertOk()->assertSee($source->title);
        $raw['current_pattern'] = '/article/{slug}';
        $raw['history'] = [['pattern' => $pattern, 'retired_at' => now()->toIso8601String()]];
        SiteSetting::query()->where('setting_key', 'article_permalink_policy')->update(['setting_value' => json_encode($raw)]);
        SiteSettingsBag::forget();
        $this->get($path.'?source=old')->assertStatus(301)->assertRedirect('http://localhost/article/'.$source->slug.'?source=old');
        $this->expectException(ValidationException::class);
        app(TopicNamespaceGuard::class)->assertAvailable('primary');
    }

    public function test_changed_paths_redirect_directly_and_remain_reserved_after_trash(): void
    {
        $topic = $this->topic('Paths topic');
        $paths = app(TopicPathService::class);
        $old = $topic->slug;
        $preview = $paths->preview($topic, 'first-new-path', $this->actor()->id);
        $topic = $paths->confirm($topic, $preview['token'], $this->actor()->id);
        $preview = $paths->preview($topic, 'second-new-path', $this->actor()->id);
        $topic = $paths->confirm($topic, $preview['token'], $this->actor()->id);
        $this->get('/topics/'.$old)->assertStatus(301)->assertRedirect('http://localhost/topics/second-new-path');
        $this->get('/topics/first-new-path')->assertRedirect('http://localhost/topics/second-new-path');
        $this->get('/topics/second-new-path')->assertOk();
        $topic->delete();
        $this->get('/topics/'.$old)->assertNotFound();
        $this->expectException(ValidationException::class);
        app(TopicService::class)->create('primary', ['title' => 'New topic', 'slug' => $old]);
    }

    public function test_path_preview_cannot_be_replayed_after_content_changes(): void
    {
        $topic = $this->topic('Path stale preview');
        $paths = app(TopicPathService::class);
        $proof = $paths->preview($topic, 'planned-path', $this->actor()->id);
        app(TopicService::class)->save($topic, ['intro' => 'Changed intro'], 1);
        $this->expectException(ValidationException::class);
        $paths->confirm($topic, $proof['token'], $this->actor()->id);
    }

    public function test_private_historical_paths_never_redirect_to_a_draft(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Private paths']);
        $old = $topic->slug;
        $paths = app(TopicPathService::class);
        $proof = $paths->preview($topic, 'private-new-path', $this->actor()->id);
        $paths->confirm($topic, $proof['token'], $this->actor()->id);
        $this->get('/topics/'.$old)->assertNotFound();
        $this->get('/topics/private-new-path')->assertNotFound();
    }

    public function test_real_fragment_and_seo_are_frozen_in_the_public_revision(): void
    {
        $a = $this->article(1);
        $b = $this->article(2);
        $text = (string) $a->content;
        $evidence = ['article_id' => $a->id, 'field' => 'content', 'start' => 0, 'end' => mb_strlen($text), 'text' => $text, 'sha256' => hash('sha256', $text)];
        $service = app(TopicService::class);
        $topic = $service->create('primary', ['title' => 'Visible heading', 'intro' => 'Sourced intro', 'seo' => ['title' => 'Search page title', 'description' => 'Search description'], 'summary' => ['facts' => [['text' => 'Source fact', 'article_ids' => [$a->id], 'evidence' => [$evidence]]]], 'articles' => [['article_id' => $a->id], ['article_id' => $b->id]]]);
        $service->publish($topic, 1);
        $this->get('/topics/'.$topic->slug)->assertOk()->assertSee('<h1>Visible heading</h1>', false)->assertSee('Search page title')->assertSee('Search description')->assertSee('查看原文片段')->assertSee($text);
        $service->save($topic, ['seo' => ['title' => 'Private SEO title', 'description' => 'Private SEO description']], 1);
        $this->get('/topics/'.$topic->slug)->assertDontSee('Private SEO title')->assertSee('Search page title');
    }

    public function test_fabricated_fragment_cannot_be_published_even_with_valid_article_ids(): void
    {
        $a = $this->article(1);
        $b = $this->article(2);
        $fake = 'Invented evidence';
        $topic = app(TopicService::class)->create('primary', ['title' => 'Bad evidence', 'intro' => 'Sourced intro', 'summary' => ['facts' => [['text' => 'Fact', 'article_ids' => [$a->id], 'evidence' => [['article_id' => $a->id, 'field' => 'content', 'start' => 0, 'end' => mb_strlen($fake), 'text' => $fake, 'sha256' => hash('sha256', $fake)]]]]], 'articles' => [['article_id' => $a->id], ['article_id' => $b->id]]]);
        $this->expectException(ValidationException::class);
        app(TopicService::class)->publish($topic, 1);
    }

    public function test_successful_template_upgrade_has_a_compatible_explicit_rollback(): void
    {
        Storage::fake('local');
        $topic = $this->topic('Compatible rollback');
        $theme = 'geoflow-template-01-ink-editorial';
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $theme]);
        $contents = app(ThemeWorkspaceService::class)->sourceContents($theme, 'builtin');
        unset($contents['resources/views/site/topics/show.blade.php']);
        $workspace = ThemeWorkspace::query()->create(['id' => (string) Str::uuid(), 'instance_id' => (string) Str::uuid(), 'admin_id' => $this->actor()->id, 'site_key' => 'primary', 'theme_id' => $theme, 'source' => 'builtin', 'state' => 'draft']);
        $base = app(ThemeRevisionStorage::class)->create($workspace->id, $theme, $contents, []);
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => $theme, 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 1]);
        $compat = app(TopicThemeCompatibility::class);
        $next = $compat->ensure('primary', $this->actor());
        $state = $compat->rollbackState('primary');
        $this->assertNotNull($state);
        $rolled = $compat->rollback('primary', $this->actor(), $state['binding_version']);
        $this->assertNotSame($next->id, $rolled->id);
        $this->assertSame($base->id, $rolled->parent_id);
        $this->assertEquals($contents, app(ThemeRevisionStorage::class)->contents($base));
        $this->get('/topics/'.$topic->slug)->assertOk()->assertSee('Compatible rollback');
        $this->assertNull($compat->rollbackState('primary'));
    }

    private function topic(string $title): Topic
    {
        $sources = Article::query()->get();
        if ($sources->count() < 2) {
            for ($i = 0; $i < 2; $i++) {
                $this->article($i);
            }$sources = Article::query()->get();
        }
        $topic = app(TopicService::class)->create('primary', ['title' => $title, 'intro' => '有依据的导读', 'summary' => ['one_sentence' => '由本站文章整理的结论', 'facts' => [['text' => '可以逐篇查看来源', 'article_ids' => [$sources[0]->id], 'evidence' => [$this->sourceEvidence($sources[0])]]]], 'tags' => ['GEO'], 'articles' => $sources->map(fn ($a) => ['article_id' => $a->id])->all()], $this->actor()->id);
        app(TopicService::class)->publish($topic, 1, $this->actor()->id);

        return $topic->fresh();
    }

    private function article(int $i): Article
    {
        $author = Author::query()->firstOrCreate(['name' => '测试作者']);
        $category = Category::query()->firstOrCreate(['slug' => 'topic-public'], ['name' => '专题内容']);

        return Article::query()->create(['title' => 'GEO 来源 '.$i, 'slug' => 'geo-source-'.$i, 'content' => 'GEO 内容，独立来源 '.$i, 'excerpt' => '来源摘要 '.$i, 'author_id' => $author->id, 'category_id' => $category->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()]);
    }

    private function actor(): Admin
    {
        return Admin::query()->firstOrCreate(['username' => 'topic_public_admin'], ['email' => 'topic-public@example.test', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
    }

    private function model(): AiModel
    {
        $model = AiModel::query()->firstOrNew(['name' => 'Topic test model']);
        $model->fill(['version' => 'test', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'), 'model_id' => 'test-chat-model', 'model_type' => 'chat', 'api_url' => 'https://ai.test', 'daily_limit' => 100, 'status' => 'active']);
        $model->forceFill(['owner_admin_id' => $this->actor()->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();

        return $model;
    }

    private function sourceEvidence(Article $article): array
    {
        $text = mb_substr((string) $article->content, 0, 1800, 'UTF-8');

        return ['article_id' => (int) $article->id, 'field' => 'content', 'start' => 0, 'end' => mb_strlen($text, 'UTF-8'), 'text' => $text, 'sha256' => hash('sha256', $text)];
    }

    public function test_manual_fact_requires_fragments_and_readable_editor_quotes_resolve_them(): void
    {
        $a = $this->article(901);
        $b = $this->article(902);
        $service = app(TopicService::class);
        $topic = $service->create('primary', ['title' => 'Manual quote', 'intro' => 'Sourced introduction', 'articles' => [['article_id' => $a->id], ['article_id' => $b->id]], 'summary' => ['facts' => [['text' => 'Sourced statement', 'article_ids' => [$a->id]]]]]);
        try {
            $service->publish($topic, 1);
            $this->fail('Missing fragment was published');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('summary.facts.0.evidence', $error->errors());
        }
        $topic = $service->save($topic, ['summary' => ['facts' => [['text' => 'Sourced statement', 'article_ids' => [(string) $a->id], 'evidence_quotes_text' => $a->id.'：'.$a->content]]]], 1);
        $service->publish($topic, 2);
        $fact = $service->publicView($topic->fresh())['summary']['facts'][0];
        $this->assertSame([$a->id], $fact['article_ids']);
        $this->assertSame((string) $a->content, $fact['evidence'][0]['text']);
        $this->assertSame(hash('sha256', $a->content), $fact['evidence'][0]['sha256']);
    }

    public function test_search_title_cannot_add_unverified_future_comparison_claims(): void
    {
        $topic = $this->topic('Search claims');
        $topic = app(TopicService::class)->save($topic, ['seo' => ['title' => '2099 年最新最全 GEO 认证指南']], 1);
        $this->expectException(ValidationException::class);
        app(TopicService::class)->publish($topic, 2);
    }

    public function test_search_verification_dates_cannot_exceed_the_actual_review_month_or_day(): void
    {
        foreach (['截至 2026 年 12 月核验', '截至 2026-09-02 核验', '截至 2026/10/01 核验'] as $text) {
            try {
                app(TopicFreshnessService::class)->assertSearchClaims(['seo' => ['title' => $text], 'freshness' => ['mode' => 'as_of', 'last_verified_at' => '2026-09-01T10:00:00+08:00', 'timezone' => 'Asia/Shanghai']]);
                $this->fail('Future search review date was allowed');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('seo.title', $error->errors());
            }
        }
    }
}
