<?php

namespace Tests\Feature\Topics;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Models\TopicImportBatch;
use App\Services\Topics\TopicBatchService;
use App\Services\Topics\TopicGenerationService;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicTemplateCatalog;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TopicAdminContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_header_mapping_preserves_metadata_and_binds_the_request_key(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $category = Category::query()->create(['name' => '研究', 'slug' => 'research']);
        $csv = "\xEF\xBB\xBFaudience,tags,标题,category,template,freshness,sourcecoverage\n新手,\"基础,实操\",\"GEO, Guide\",研究,guide,none,本站研究\n";
        $settings = $this->settings();
        $response = $this->actingAs($actor, 'admin')->post(route('admin.topics.batches.preview'), $settings + ['csv' => UploadedFile::fake()->createWithContent('topics.csv', $csv)]);
        $response->assertOk()->assertSee('GEO, Guide')->assertSee('目标读者：新手')->assertViewHas('hasErrors', false);
        $row = $response->viewData('rows')[0];
        $input = ['title' => $row['title'], 'metadata' => $row['metadata']];
        $uuid = (string) Str::uuid();
        $this->post(route('admin.topics.batches.store'), $settings + ['rows' => [$input], 'request_key' => $uuid])->assertRedirect();
        $batch = TopicImportBatch::query()->sole();
        $this->assertSame([$category->id], $batch->rows[0]['filters']['category_ids']);
        app(TopicBatchService::class)->processNext($batch->id, 1);
        $topic = Topic::query()->sole();
        $this->assertSame(['基础', '实操'], $topic->draft_payload['tags']);
        $this->assertSame('guide', $topic->draft_payload['template_key']);
        $this->assertSame('none', $topic->draft_payload['freshness']['mode']);
        $this->assertSame('新手', $topic->draft_payload['basic_info'][0]['value']);
        $this->assertSame('本站研究', $topic->draft_payload['basic_info'][2]['value']);
        $this->post(route('admin.topics.batches.store'), $settings + ['rows' => [$input], 'request_key' => $uuid])->assertRedirect();
        $changed = $input;
        $changed['metadata']['audience'] = '专家';
        $this->post(route('admin.topics.batches.store'), $settings + ['rows' => [$changed], 'request_key' => $uuid])->assertSessionHasErrors('request_key');
        $this->assertSame(1, TopicImportBatch::query()->count());
    }

    public function test_csv_preview_reports_invalid_metadata_without_marking_a_valid_row_as_duplicate(): void
    {
        $csv = "template,title,category\ninvalid,Same guide,\ndefault,Same guide,\n";
        $response = $this->actingAs($this->actor(), 'admin')->post(route('admin.topics.batches.preview'), $this->settings() + ['csv' => UploadedFile::fake()->createWithContent('bad.csv', $csv)]);
        $response->assertOk()->assertViewHas('hasErrors', true)->assertSee('当前网站模板未提供')->assertDontSee('href="#preview-row-1"', false);
        $this->assertNull($response->viewData('rows')[1]['duplicate_of']);
        $this->assertSame(1, $response->viewData('executableCount'));
        $this->post(route('admin.topics.batches.preview'), $this->settings() + ['csv' => UploadedFile::fake()->createWithContent('broken.csv', "title,tags\n\"Open title,tag")])->assertSessionHasErrors('csv');
        $this->post(route('admin.topics.batches.preview'), $this->settings() + ['rows' => [], 'csv' => UploadedFile::fake()->createWithContent('no-title.csv', "audience,tags\nnew,tag")])->assertSessionHasErrors('csv');
    }

    public function test_metadata_survives_csv_form_return_and_retry_uses_original_settings(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $csv = "audience,title,tags\nReaders,\"Guide\nfor GEO\",one;two\n";
        $this->actingAs($actor, 'admin')->post(route('admin.topics.batches.preview'), $this->settings() + ['csv' => UploadedFile::fake()->createWithContent('topics.csv', $csv)])->assertOk();
        $response = $this->post(route('admin.topics.batches.preview'), $this->settings() + ['titles_text' => 'Guide for GEO']);
        $response->assertOk();
        $this->assertSame('Readers', $response->viewData('rows')[0]['metadata']['audience']);
        $batch = app(TopicBatchService::class)->create($actor, 'primary', [['title' => 'GEO', 'metadata' => ['audience' => 'Readers']]], ['mode' => 'ai', 'model_id' => $this->model($actor)->id, 'template_key' => 'guide', 'after' => 'draft_only'], (string) Str::uuid());
        app(TopicBatchService::class)->processNext($batch->id, 1);
        $this->assertSame('waiting_content', $batch->fresh()->rows[0]['status']);
        app(TopicBatchService::class)->processNext($batch->id, 1);
        $original = $batch->settings;
        app(TopicBatchService::class)->retry($batch->fresh());
        $this->assertSame($original, $batch->fresh()->settings);
        $this->assertSame('Readers', $batch->fresh()->rows[0]['metadata']['audience']);
        $this->assertSame('Readers', Topic::query()->sole()->draft_payload['basic_info'][0]['value']);
    }

    public function test_bulk_ai_is_idempotent_and_preserves_manual_content_until_adoption(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $model = $this->model($actor);
        $sources = [$this->article('GEO first'), $this->article('GEO second')];
        $topic = $this->topic($actor, $sources);
        $input = ['site' => 'primary', 'topic_ids' => [$topic->id], 'versions' => [$topic->id => ['draft' => 1]], 'request_id' => (string) Str::uuid(), 'action' => 'generate', 'model_id' => $model->id, 'rules' => '原文导读', 'target_count' => 2];
        $this->actingAs($actor, 'admin')->post(route('admin.topics.bulk'), $input)->assertRedirect();
        $this->post(route('admin.topics.bulk'), $input)->assertRedirect();
        $batch = TopicImportBatch::query()->sole();
        $this->assertSame('draft_only', $batch->settings['after']);
        $this->assertTrue($batch->settings['suggestion_only']);
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), json_encode(['supported' => true, 'unsupported_fields' => []])])->preventStrayPrompts();
        app(TopicBatchService::class)->processNext($batch->id, 1);
        $this->assertSame('needs_adoption', $batch->fresh()->rows[0]['status']);
        $this->assertSame('Manual introduction', $topic->fresh()->draft_payload['intro']);
        $this->assertNull($topic->fresh()->public_revision_id);
        $this->assertSame(1, Topic::query()->count());
        $this->post(route('admin.topics.bulk'), array_replace($input, ['rules' => 'Changed rules']))->assertSessionHasErrors('request_key');
        $this->get(route('admin.topics.index'))->assertOk()->assertSee('批次 #'.$batch->id);
    }

    public function test_permanent_exclusions_survive_saves_filter_candidates_and_manual_selection_clears_them(): void
    {
        $actor = $this->actor();
        $sources = [$this->article('GEO included'), $this->article('GEO second')];
        $excluded = $this->article('GEO excluded');
        $topic = $this->topic($actor, $sources);
        $this->actingAs($actor, 'admin')->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'title' => $topic->title, 'template_key' => 'default', 'expected_version' => 1, 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources), 'source_overrides_present' => 1, 'source_overrides' => ['excluded_article_ids' => [$excluded->id]]])->assertRedirect();
        $this->assertSame([$excluded->id], $topic->fresh()->draft_payload['source_overrides']['excluded_article_ids']);
        $ids = array_column(app(TopicGenerationService::class)->candidates('primary', 'GEO', ['excluded_article_ids' => $topic->fresh()->draft_payload['source_overrides']['excluded_article_ids']]), 'article_id');
        $this->assertNotContains($excluded->id, $ids);
        $this->get(route('admin.topics.edit', ['topic' => $topic->id]))->assertOk()->assertSee('永久排除')->assertSee('GEO excluded');
        $this->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'title' => $topic->title, 'template_key' => 'default', 'expected_version' => 2, 'articles' => [['article_id' => $excluded->id]], 'source_overrides_present' => 1, 'source_overrides' => ['excluded_article_ids' => [$excluded->id]]])->assertRedirect();
        $this->assertSame([], $topic->fresh()->draft_payload['source_overrides']['excluded_article_ids']);
        $this->assertContains($excluded->id, array_column(app(TopicGenerationService::class)->candidates('primary', 'GEO'), 'article_id'));
    }

    public function test_full_ai_result_can_adopt_only_selected_blocks_and_rejects_unknown_fields(): void
    {
        $actor = $this->actor();
        $sources = [$this->article('GEO one'), $this->article('GEO two')];
        $topic = $this->topic($actor, $sources);
        $run = $this->suggestion($actor, $topic, $sources);
        $url = route('admin.topics.runs.action', ['run' => $run->id, 'action' => 'adopt']);
        $this->actingAs($actor, 'admin')->post($url, ['expected_version' => 1])->assertSessionHasErrors('fields');
        $this->post($url, ['expected_version' => 1, 'fields' => ['title']])->assertSessionHasErrors('fields.0');
        $this->post($url, ['expected_version' => 1, 'fields' => ['intro']])->assertRedirect();
        $draft = $topic->fresh()->draft_payload;
        $this->assertSame('Suggested intro', $draft['intro']);
        $this->assertSame(['manual'], $draft['tags']);
        $this->assertSame('Manual summary', $draft['summary']['one_sentence']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNull($topic->fresh()->public_revision_id);
    }

    public function test_adoption_enforces_citation_dependencies_stale_version_and_current_source_hashes(): void
    {
        $actor = $this->actor();
        $sources = [$this->article('GEO one'), $this->article('GEO two')];
        $extra = $this->article('GEO extra');
        $topic = $this->topic($actor, $sources);
        $run = $this->suggestion($actor, $topic, [$sources[0], $extra]);
        $url = route('admin.topics.runs.action', ['run' => $run->id, 'action' => 'adopt']);
        $this->actingAs($actor, 'admin')->post($url, ['expected_version' => 1, 'fields' => ['summary']])->assertSessionHasErrors('fields');
        $this->post($url, ['expected_version' => 2, 'fields' => ['summary', 'articles']])->assertSessionHasErrors('draft_version');
        $extra->update(['content' => 'Changed source']);
        $this->post($url, ['expected_version' => 1, 'fields' => ['intro']])->assertSessionHasErrors('articles');
        $this->assertSame('Manual introduction', $topic->fresh()->draft_payload['intro']);
        $this->assertSame(1, $topic->fresh()->draft_version);
        $this->assertSame('needs_adoption', $run->fresh()->status);
    }

    public function test_single_field_run_cannot_adopt_another_block_or_restore_an_excluded_source(): void
    {
        $actor = $this->actor();
        $sources = [$this->article('GEO one'), $this->article('GEO two')];
        $topic = $this->topic($actor, $sources);
        $run = $this->suggestion($actor, $topic, $sources);
        $run->update(['input' => ['field' => 'intro']]);
        $url = route('admin.topics.runs.action', ['run' => $run->id, 'action' => 'adopt']);
        $this->actingAs($actor, 'admin')->post($url, ['expected_version' => 1, 'fields' => ['tags']])->assertSessionHasErrors('fields');
        $run->update(['input' => []]);
        app(TopicService::class)->save($topic, ['source_overrides' => ['excluded_article_ids' => [$sources[1]->id]], 'articles' => [['article_id' => $sources[0]->id]]], 1);
        $this->post($url, ['expected_version' => 2, 'fields' => ['articles']])->assertSessionHasErrors('fields');
        $this->assertSame([$sources[1]->id], $topic->fresh()->draft_payload['source_overrides']['excluded_article_ids']);
    }

    public function test_batch_dto_reports_actual_topic_states_and_trashed_links_open_history(): void
    {
        $actor = $this->actor();
        $sources = [$this->article('GEO first'), $this->article('GEO second')];
        $topic = $this->topic($actor, $sources);
        $run = $this->suggestion($actor, $topic, $sources);
        $batch = TopicImportBatch::query()->create(['owner_admin_id' => $actor->id, 'site_key' => 'primary', 'request_key' => (string) Str::uuid(), 'settings' => [], 'rows' => [['number' => 1, 'title' => $topic->title, 'topic_id' => $topic->id, 'run_id' => $run->id, 'status' => 'completed', 'error' => null, 'duplicate_of' => null]], 'status' => 'completed', 'generation' => 1]);
        $this->actingAs($actor, 'admin')->getJson(route('admin.topics.batches.status', ['batch' => $batch->id]))->assertJsonPath('rows.0.current_state', 'draft')->assertJsonPath('current_counts.draft', 1);
        app(TopicService::class)->publish($topic, 1, $actor->id);
        $this->getJson(route('admin.topics.batches.status', ['batch' => $batch->id]))->assertJsonPath('rows.0.current_state', 'published')->assertJsonPath('current_counts.published', 1);
        $topic->refresh()->delete();
        $history = route('admin.topics.history', ['topic' => $topic->id]);
        $this->getJson(route('admin.topics.batches.status', ['batch' => $batch->id]))->assertJsonPath('rows.0.current_state', 'trash')->assertJsonPath('rows.0.topic_url', $history);
        $this->getJson(route('admin.topics.runs.status', ['run' => $run->id]))->assertJsonPath('topic_url', $history);
        $this->get(route('admin.topics.runs.show', ['run' => $run->id]))->assertOk()->assertSee('打开版本历史')->assertDontSee('采用到工作稿');
    }

    public function test_generation_reads_saved_exclusions_before_composing_suggestions(): void
    {
        $actor = $this->actor();
        $sources = [$this->article('GEO first'), $this->article('GEO second')];
        $excluded = $this->article('GEO excluded');
        $topic = $this->topic($actor, $sources);
        $topic = app(TopicService::class)->save($topic, ['source_overrides' => ['excluded_article_ids' => [$excluded->id]]], 1);
        $run = app(TopicGenerationService::class)->prepare($actor, 'primary', ['title' => $topic->title, 'model_id' => $this->model($actor)->id, 'suggestion_only' => true, 'after' => 'draft_only'], (string) Str::uuid(), $topic);
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), json_encode(['supported' => true, 'unsupported_fields' => []])])->preventStrayPrompts();
        app(TopicGenerationService::class)->process($run->id);
        $this->assertSame('needs_adoption', $run->fresh()->status);
        $this->assertArrayNotHasKey($excluded->id, $run->fresh()->result['source_hashes']);
        $this->assertSame([$excluded->id], $topic->fresh()->draft_payload['source_overrides']['excluded_article_ids']);
    }

    public function test_list_failure_retry_retries_only_the_selected_row_from_its_original_batch(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $first = app(TopicService::class)->create('primary', ['title' => 'GEO first'], $actor->id);
        $second = app(TopicService::class)->create('primary', ['title' => 'GEO second'], $actor->id);
        $model = $this->model($actor);
        $batch = app(TopicBatchService::class)->create($actor, 'primary', [['topic_id' => $first->id, 'expected_version' => 1], ['topic_id' => $second->id, 'expected_version' => 1]], ['mode' => 'existing', 'model_id' => $model->id, 'after' => 'draft_only', 'suggestion_only' => true], (string) Str::uuid());
        app(TopicBatchService::class)->processNext($batch->id, 1);
        app(TopicBatchService::class)->processNext($batch->id, 1);
        app(TopicBatchService::class)->processNext($batch->id, 1);
        $this->actingAs($actor, 'admin')->post(route('admin.topics.bulk'), ['site' => 'primary', 'topic_ids' => [$first->id], 'versions' => [$first->id => ['draft' => 1]], 'request_id' => (string) Str::uuid(), 'action' => 'retry_generation'])->assertRedirect()->assertSessionHas('bulk_results', fn ($rows) => $rows[0]['ok']);
        $this->assertSame('pending', $batch->fresh()->rows[0]['status']);
        $this->assertSame('waiting_content', $batch->fresh()->rows[1]['status']);
        $this->assertSame($model->id, $batch->fresh()->settings['model_id']);
    }

    public function test_bulk_ai_records_unavailable_topics_without_losing_valid_selected_rows(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $topic = app(TopicService::class)->create('primary', ['title' => 'GEO valid'], $actor->id);
        $this->actingAs($actor, 'admin')->post(route('admin.topics.bulk'), ['site' => 'primary', 'topic_ids' => [$topic->id, 99999], 'versions' => [$topic->id => ['draft' => 1], 99999 => ['draft' => 1]], 'request_id' => (string) Str::uuid(), 'action' => 'generate', 'model_id' => $this->model($actor)->id])->assertRedirect();
        $batch = TopicImportBatch::query()->sole();
        $this->assertSame('pending', $batch->rows[0]['status']);
        $this->assertSame('failed', $batch->rows[1]['status']);
        $this->assertNull($batch->rows[1]['topic_id']);
        $this->get(route('admin.topics.batches.show', ['batch' => $batch->id]))->assertOk()->assertSee('专题不存在或不属于当前站点');
    }

    public function test_malformed_exclusion_list_is_a_validation_error(): void
    {
        $actor = $this->actor();
        $topic = app(TopicService::class)->create('primary', ['title' => 'GEO source overrides'], $actor->id);
        $this->actingAs($actor, 'admin')->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'title' => $topic->title, 'template_key' => 'default', 'expected_version' => 1, 'source_overrides_present' => 1, 'source_overrides' => ['excluded_article_ids' => 'malformed']])->assertSessionHasErrors('source_overrides.excluded_article_ids');
        $this->assertSame(1, $topic->fresh()->draft_version);
    }

    public function test_admin_forms_use_site_catalog_custom_layouts_and_save_channel_name_and_order(): void
    {
        $theme = 'topic-contracts-'.Str::lower(Str::random(12));
        $directory = resource_path('views/theme/'.$theme);
        File::makeDirectory($directory.'/topics/templates', recursive: true);
        $declaration = TopicTemplateCatalog::declaration();
        $declaration['layouts'][] = ['id' => 'research-brief', 'name' => '研究简报', 'view' => 'topics/templates/research-brief.blade.php'];
        File::put($directory.'/manifest.json', json_encode(['topic' => $declaration]));
        File::put($directory.'/topics/templates/research-brief.blade.php', '<div>Research brief</div>');
        try {
            SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $theme]);
            $actor = $this->actor();
            $this->actingAs($actor, 'admin')->getJson(route('admin.topics.settings'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('template_options.research-brief', '研究简报');
            $this->get(route('admin.topics.create'))->assertOk()->assertSee('研究简报');
            $this->get(route('admin.topics.batches.create'))->assertOk()->assertSee('研究简报');
            $this->get(route('admin.tasks.create', ['content_type' => 'topic']))->assertOk()->assertSee('研究简报');
            $this->post(route('admin.topics.settings.save'), ['site' => 'primary', 'channel_name' => '研究专题', 'default_template' => 'research-brief', 'home_limit' => 3])->assertRedirect();
            $this->get(route('admin.topics.settings'))->assertOk()->assertSee('研究专题');
            $this->post(route('admin.topics.store'), ['site' => 'primary', 'title' => 'Catalog guide', 'template_key' => 'research-brief', 'display_order' => -5])->assertRedirect();
            $topic = Topic::query()->sole();
            $this->assertSame('research-brief', $topic->draft_payload['template_key']);
            $this->get(route('admin.topics.preview', ['topic' => $topic->id]))->assertOk()->assertSee('研究简报');
            $this->assertSame(-5, $topic->display_order);
            $this->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'title' => $topic->title, 'template_key' => 'research-brief', 'display_order' => '', 'expected_version' => 1])->assertRedirect();
            $this->assertSame(0, $topic->fresh()->display_order);
            $this->post(route('admin.topics.batches.preview'), $this->settings() + ['rows' => [], 'csv' => UploadedFile::fake()->createWithContent('custom.csv', "title,template\nResearch guide,research-brief")])->assertOk()->assertViewHas('hasErrors', false);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function settings(): array
    {
        return ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'default', 'after' => 'draft_only', 'target_count' => 8];
    }

    private function actor(): Admin
    {
        return Admin::query()->create(['username' => 'contracts-'.Str::random(8), 'password' => 'password', 'email' => Str::random(8).'@test.local', 'role' => 'super_admin', 'status' => 'active']);
    }

    private function model(Admin $actor): AiModel
    {
        $model = AiModel::query()->create(['name' => 'Contracts model', 'version' => 'test', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'), 'model_id' => 'test-model', 'model_type' => 'chat', 'api_url' => 'https://ai.test', 'daily_limit' => 100, 'status' => 'active']);
        $model->forceFill(['owner_admin_id' => $actor->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();

        return $model;
    }

    private function article(string $title): Article
    {
        $category = Category::query()->firstOrCreate(['slug' => 'contracts'], ['name' => 'Contracts']);
        $author = Author::query()->firstOrCreate(['email' => 'contracts@test.local'], ['name' => 'Editor']);

        return Article::query()->create(['title' => $title, 'slug' => Str::random(15), 'content' => 'GEO '.$title.' '.Str::random(30), 'excerpt' => 'GEO source', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()]);
    }

    private function topic(Admin $actor, array $sources): Topic
    {
        return app(TopicService::class)->create('primary', ['title' => 'GEO Guide', 'intro' => 'Manual introduction', 'summary' => ['one_sentence' => 'Manual summary'], 'tags' => ['manual'], 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)], $actor->id);
    }

    private function aiOutput(array $sources): string
    {
        return json_encode(['intro' => 'Suggested intro', 'summary' => ['one_sentence' => 'Suggested summary', 'facts' => [['text' => 'A sourced fact', 'article_ids' => [$sources[1]->id]]]], 'tags' => ['suggested'], 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)]);
    }

    private function suggestion(Admin $actor, Topic $topic, array $sources): TopicBuildRun
    {
        $run = app(TopicGenerationService::class)->prepare($actor, 'primary', ['title' => $topic->title, 'model_id' => $this->model($actor)->id, 'suggestion_only' => true, 'after' => 'draft_only'], (string) Str::uuid(), $topic);
        $result = json_decode($this->aiOutput($sources), true);
        $result['source_hashes'] = array_column(array_map(fn ($a) => ['id' => $a->id, 'hash' => TopicService::contentHash($a)], $sources), 'hash', 'id');
        $run->update(['status' => 'needs_adoption', 'phase' => 'finished', 'result' => $result]);

        return $run->refresh();
    }
}
