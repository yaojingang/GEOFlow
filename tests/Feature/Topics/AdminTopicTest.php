<?php

namespace Tests\Feature\Topics;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Jobs\ProcessTopicBatchJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Models\TopicImportBatch;
use App\Policies\TopicPolicy;
use App\Services\Topics\TopicGenerationService;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicSiteSettings;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminTopicTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('malformedEditorShapes')]
    public function test_malformed_editor_shapes_return_validation_errors_without_saving(array $input, string $field): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->postJson(route('admin.topics.store'), array_replace([
                'site' => 'primary', 'title' => 'Manual title', 'intro' => 'My manual introduction', 'template_key' => 'default',
            ], $input))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('topics', 0);
    }

    public static function malformedEditorShapes(): array
    {
        return [
            'scalar score' => [['score' => 'unexpected'], 'score'],
            'scalar dimensions' => [['score' => ['enabled' => true, 'dimensions' => 'unexpected']], 'score.dimensions'],
            'array tags input' => [['tags_text' => ['unexpected']], 'tags_text'],
            'array fact text' => [['summary' => ['facts' => [['text' => ['unexpected'], 'article_ids' => [1]]]]], 'summary.facts.0.text'],
            'scalar fact row' => [['summary' => ['facts' => ['unexpected']]], 'summary.facts.0'],
            'array article identifiers input' => [['faq' => [['question' => 'Question', 'article_ids_text' => ['unexpected']]]], 'faq.0.article_ids_text'],
            'scalar dimension row' => [['score' => ['enabled' => true, 'dimensions' => ['unexpected']]], 'score.dimensions.0'],
            'array dimension weight' => [['score' => ['enabled' => true, 'dimensions' => [['name' => 'Clarity', 'weight' => ['unexpected']]]]], 'score.dimensions.0.weight'],
            'scalar article row' => [['articles' => ['unexpected']], 'articles.0'],
            'scalar basic information row' => [['basic_info' => ['unexpected']], 'basic_info.0'],
        ];
    }

    public function test_editor_shape_validation_preserves_manual_title_and_introduction(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.topics.create'))
            ->post(route('admin.topics.store'), [
                'site' => 'primary', 'title' => 'Manual title', 'intro' => 'My manual introduction', 'template_key' => 'default',
                'score' => ['enabled' => true, 'dimensions' => 'unexpected'],
            ])
            ->assertRedirect(route('admin.topics.create'))
            ->assertSessionHasErrors('score.dimensions')
            ->assertSessionHasInput('title', 'Manual title')
            ->assertSessionHasInput('intro', 'My manual introduction');
        $this->assertDatabaseCount('topics', 0);
    }

    public function test_guests_cannot_open_private_topic_preview(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Private guide']);
        $this->get(route('admin.topics.preview', ['topic' => $topic->id]))->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_create_a_minimal_draft_and_list_it_in_the_article_section(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.store'), ['site' => 'primary', 'title' => 'Only a title', 'template_key' => 'default'])->assertRedirect();
        $topic = Topic::query()->sole();
        $this->assertSame('', $topic->draft_payload['intro']);
        $this->assertNull($topic->public_revision_id);
        $this->get(route('admin.topics.index'))->assertOk()->assertSee('Only a title')->assertSee('专题列表')->assertSee('创建专题任务');
        $this->get(route('admin.topics.edit', ['topic' => $topic->id]))->assertOk()->assertSee('核对来源后保存')->assertSee('保存并获取 AI 建议');
    }

    public function test_preview_is_private_and_escapes_user_content(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => '<script>alert(1)</script>']);
        $this->actingAs($this->admin(), 'admin')->get(route('admin.topics.preview', ['topic' => $topic->id]))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_stale_working_draft_cannot_overwrite_saved_changes(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Initial title']);
        $this->actingAs($this->admin(), 'admin')->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'title' => 'New title', 'template_key' => 'guide', 'expected_version' => 1])->assertRedirect();
        $this->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'title' => 'Stale edit', 'template_key' => 'default', 'expected_version' => 1])->assertSessionHasErrors('draft_version');
        $this->assertSame('New title', $topic->fresh()->title);
        $this->assertSame(2, $topic->fresh()->draft_version);
    }

    public function test_publish_requires_a_uuid_and_does_not_publish_an_incomplete_draft(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Incomplete']);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'publish']), ['expected_version' => 1])->assertSessionHasErrors('request_id');
        $this->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'publish']), ['expected_version' => 1, 'request_id' => (string) Str::uuid()])->assertSessionHasErrors('intro');
        $this->assertNull($topic->fresh()->public_revision_id);
    }

    public function test_admin_can_publish_withdraw_and_restore_a_trashed_topic_as_draft(): void
    {
        $topic = $this->completeTopic();
        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'publish']), ['expected_version' => 1, 'request_id' => (string) Str::uuid()])->assertRedirect();
        $public = $topic->fresh()->public_revision_id;
        $this->assertNotNull($public);
        $this->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'withdraw']), ['expected_revision_id' => $public, 'request_id' => (string) Str::uuid()])->assertRedirect();
        $this->assertNull($topic->fresh()->public_revision_id);
        $this->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'trash']))->assertRedirect();
        $this->get(route('admin.topics.index', ['status' => 'trash']))->assertOk()->assertSee('恢复到草稿');
        $this->get(route('admin.topics.history', ['topic' => $topic->id]))->assertOk();
        $this->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'restore']))->assertRedirect();
        $restored = Topic::query()->findOrFail($topic->id);
        $this->assertNull($restored->public_revision_id);
        $this->assertNull($restored->pending_revision_id);
    }

    public function test_bulk_publication_returns_independent_success_and_failure_results(): void
    {
        $valid = $this->completeTopic();
        $invalid = app(TopicService::class)->create('primary', ['title' => 'Missing sources']);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.bulk'), ['site' => 'primary', 'action' => 'publish', 'topic_ids' => [$valid->id, $invalid->id], 'versions' => [$valid->id => ['draft' => 1], $invalid->id => ['draft' => 1]], 'request_id' => (string) Str::uuid()])->assertRedirect()->assertSessionHas('bulk_results', fn ($rows) => count($rows) === 2 && $rows[0]['ok'] && ! $rows[1]['ok']);
        $this->assertNotNull($valid->fresh()->public_revision_id);
        $this->assertNull($invalid->fresh()->public_revision_id);
    }

    public function test_ordinary_admin_cannot_manage_hosted_topic_or_site(): void
    {
        $profile = $this->hosted();
        $topic = app(TopicService::class)->create('hosted:'.$profile->id, ['title' => 'Hosted draft']);
        $this->actingAs($this->admin(), 'admin')->get(route('admin.topics.index', ['site' => 'hosted:'.$profile->id]))->assertForbidden();
        $this->get(route('admin.topics.edit', ['topic' => $topic->id]))->assertForbidden();
        $this->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'trash']))->assertForbidden();
        $this->assertFalse($topic->fresh()->trashed());
    }

    public function test_topic_policy_allows_primary_admin_actions_and_protects_hosted_topics(): void
    {
        $admin = $this->admin();
        $super = $this->admin('super_admin');
        $primary = new Topic(['site_key' => 'primary']);
        $hosted = new Topic(['site_key' => 'hosted:1']);
        $policy = new TopicPolicy;
        foreach (['view', 'update', 'delete', 'publish', 'approve'] as $action) {
            $this->assertTrue($policy->$action($admin, $primary));
            $this->assertFalse($policy->$action($admin, $hosted));
            $this->assertTrue($policy->$action($super, $hosted));
        }
        $primary->deleted_at = now();
        $this->assertFalse($policy->update($admin, $primary));
        $this->assertTrue($policy->restore($admin, $primary));
    }

    public function test_settings_are_readable_by_admin_and_checkbox_zeroes_are_saved_by_super(): void
    {
        $this->actingAs($this->admin(), 'admin')->get(route('admin.topics.settings'))->assertOk()->assertSee('当前账号可查看专题设置')->assertDontSee('>保存设置<', false);
        $this->post(route('admin.topics.settings.save'), ['site' => 'primary', 'home_limit' => 3, 'default_template' => 'guide'])->assertForbidden();
        $this->actingAs($this->admin('super_admin'), 'admin')->post(route('admin.topics.settings.save'), ['site' => 'primary', 'home_limit' => 4, 'default_template' => 'guide', 'enabled' => '1'])->assertRedirect();
        $settings = app(TopicSiteSettings::class)->get('primary');
        $this->assertTrue($settings['enabled']);
        $this->assertFalse($settings['home_enabled']);
        $this->assertFalse($settings['navigation_enabled']);
        $this->assertFalse($settings['require_review']);
        $this->assertSame(4, $settings['home_limit']);
    }

    public function test_source_picker_does_not_require_title_relevance_and_excludes_drafts(): void
    {
        $public = $this->article('Unrelated public source');
        $draft = $this->article('Private draft', ['status' => 'draft']);
        $this->actingAs($this->admin(), 'admin')->get(route('admin.topics.articles', ['site' => 'primary']))->assertOk()->assertSee($public->title)->assertDontSee($draft->title);
        $this->getJson(route('admin.topics.articles', ['site' => 'primary']))->assertOk()->assertJsonPath('articles.0.article_id', $public->id)->assertJsonPath('articles.0.status', '可公开阅读');
    }

    public function test_csv_preview_supports_bom_quotes_and_newlines_and_marks_duplicates(): void
    {
        $csv = UploadedFile::fake()->createWithContent('topics.csv', "\xEF\xBB\xBF标题,备注\n\"Quoted, title\",a\n\"Multi\nline\",b\n\"Quoted, title\",c\n");
        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.batches.preview'), ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'guide', 'after' => 'auto_publish', 'target_count' => 8, 'csv' => $csv])->assertOk()->assertViewHas('rows', fn ($rows) => count($rows) === 3 && $rows[0]['title'] === 'Quoted, title' && $rows[1]['title'] === 'Multi line' && $rows[2]['duplicate_of'] === 1)->assertViewHas('settings', fn ($s) => $s['after'] === 'draft_only');
    }

    public function test_batch_draft_creation_is_idempotent_and_dispatches_a_single_batch_identity(): void
    {
        Queue::fake([ProcessTopicBatchJob::class]);
        $this->actingAs($this->admin(), 'admin');
        $payload = ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'default', 'after' => 'draft_only', 'target_count' => 8, 'titles' => ['First', 'Second'], 'request_key' => (string) Str::uuid()];
        $this->post(route('admin.topics.batches.store'), $payload)->assertRedirect();
        $this->post(route('admin.topics.batches.store'), $payload)->assertRedirect();
        $this->assertSame(1, TopicImportBatch::query()->count());
        $this->assertSame(['First', 'Second'], array_column(TopicImportBatch::query()->sole()->rows, 'title'));
        Queue::assertPushed(ProcessTopicBatchJob::class, 2);
    }

    public function test_batch_and_generation_polling_hide_private_inputs_and_require_owner(): void
    {
        $owner = $this->admin();
        $other = $this->admin();
        $run = TopicBuildRun::query()->create(['request_key' => 'secret-run', 'site_key' => 'primary', 'owner_admin_id' => $owner->id, 'identity' => ['provider_key' => 'secret'], 'input' => ['title' => 'Secret raw input', 'rules' => 'private rules'], 'result' => ['source_hashes' => [1 => 'hash']], 'status' => 'pending', 'phase' => 'waiting']);
        $batch = TopicImportBatch::query()->create(['request_key' => 'secret-batch', 'owner_admin_id' => $owner->id, 'site_key' => 'primary', 'settings' => ['model_id' => 3], 'rows' => [['number' => 1, 'title' => 'Visible', 'status' => 'pending', 'topic_id' => null, 'run_id' => null, 'duplicate_of' => null, 'error' => null]], 'status' => 'pending', 'generation' => 1]);
        $this->actingAs($other, 'admin')->getJson(route('admin.topics.runs.status', ['run' => $run->id]))->assertForbidden();
        $this->getJson(route('admin.topics.batches.status', ['batch' => $batch->id]))->assertForbidden();
        $this->actingAs($owner, 'admin')->getJson(route('admin.topics.runs.status', ['run' => $run->id]))->assertOk()->assertJsonMissingPath('identity')->assertJsonMissingPath('input')->assertDontSee('private rules');
        $this->getJson(route('admin.topics.batches.status', ['batch' => $batch->id]))->assertOk()->assertJsonMissingPath('settings')->assertJsonPath('rows.0.title', 'Visible');
    }

    public function test_generation_requires_model_before_creating_a_new_draft(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.store'), ['site' => 'primary', 'title' => 'No model', 'template_key' => 'default', 'action' => 'generate', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('model_id');
        $this->assertSame(0, Topic::query()->count());
        $this->assertSame(0, TopicBuildRun::query()->count());
    }

    public function test_topic_task_can_be_saved_paused_without_model_title_library_or_category(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get(route('admin.tasks.create', ['content_type' => 'topic']))->assertOk()->assertSee('仅保存暂停')->assertDontSee('name="image_library_id"', false);
        $this->post(route('admin.tasks.store'), $this->taskPayload())->assertRedirect(route('admin.tasks.index'));
        $task = Task::query()->sole();
        $this->assertSame('topic', $task->content_type);
        $this->assertSame('paused', $task->status);
        $this->assertNull($task->ai_model_id);
        $this->assertNull($task->title_library_id);
        $this->assertTrue($task->topic_settings['protect_manual']);
        $this->get(route('admin.tasks.edit', ['taskId' => $task->id]))->assertOk()->assertSee('编辑专题任务')->assertSee('查看本任务专题');
        $this->get(route('admin.tasks.index'))->assertOk()->assertSee('专题任务')->assertSee('已生成 0 / 10 个专题');
    }

    public function test_topic_task_edit_keeps_type_and_previous_results(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post(route('admin.tasks.store'), $this->taskPayload())->assertRedirect();
        $task = Task::query()->sole();
        $task->update(['created_count' => 4, 'published_count' => 2]);
        $this->put(route('admin.tasks.update', ['taskId' => $task->id]), array_replace($this->taskPayload(), ['name' => 'Updated task', 'topic_config_version' => 1]))->assertRedirect();
        $this->assertSame(4, $task->fresh()->created_count);
        $this->assertSame(2, $task->fresh()->published_count);
        $this->put(route('admin.tasks.update', ['taskId' => $task->id]), ['content_type' => 'article'])->assertSessionHasErrors('content_type');
        $this->assertSame('topic', $task->fresh()->content_type);
    }

    public function test_normal_save_does_not_accept_a_forged_source_baseline_and_explicit_check_allows_publication(): void
    {
        $topic = $this->completeTopic();
        $article = Article::query()->findOrFail($topic->draft_payload['articles'][0]['article_id']);
        $article->update(['content' => 'A changed source body']);
        $data = ['site' => 'primary', 'title' => $topic->title, 'intro' => 'Checked introduction', 'template_key' => 'default', 'articles' => $topic->draft_payload['articles'], 'expected_version' => 1, 'source_hashes' => [$article->id => TopicService::contentHash($article)]];
        $this->actingAs($this->admin(), 'admin')->put(route('admin.topics.update', ['topic' => $topic->id]), $data)->assertRedirect();
        $this->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'publish']), ['expected_version' => 2, 'request_id' => (string) Str::uuid()])->assertSessionHasErrors('articles');
        $this->put(route('admin.topics.update', ['topic' => $topic->id]), array_replace($data, ['expected_version' => 2, 'action' => 'verify_sources']))->assertRedirect();
        $this->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'publish']), ['expected_version' => 3, 'request_id' => (string) Str::uuid()])->assertRedirect();
        $this->assertNotNull($topic->fresh()->public_revision_id);
    }

    public function test_editor_converts_readable_tags_and_weight_rows_to_a_valid_score_payload(): void
    {
        $topic = $this->completeTopic();
        $id = $topic->draft_payload['articles'][0]['article_id'];
        $this->actingAs($this->admin(), 'admin')->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'title' => $topic->title, 'intro' => 'A sourced introduction.', 'template_key' => 'guide', 'expected_version' => 1, 'articles' => $topic->draft_payload['articles'], 'tags_text' => '阅读指南，AI,来源', 'score' => ['enabled' => '1', 'type' => 'editorial', 'name' => 'Editorial quality', 'source' => 'Documented editorial assessment', 'total' => 8, 'rated_at' => now()->format('Y-m-d'), 'valid_until' => now()->addDay()->format('Y-m-d'), 'dimensions' => [['name' => 'Completeness', 'score' => 9, 'weight' => 2], ['name' => 'Clarity', 'score' => 7, 'weight' => 2]], 'evidence' => [['text' => 'Evidence statement', 'article_ids' => [$id]]]]])->assertRedirect();
        $payload = $topic->fresh()->draft_payload;
        $this->assertSame(['阅读指南', 'AI', '来源'], $payload['tags']);
        $this->assertSame('guide', $payload['template_key']);
        $this->assertSame([2, 2], $payload['score']['weights']);
        $this->assertArrayNotHasKey('weight', $payload['score']['dimensions'][0]);
        $this->get(route('admin.topics.preview', ['topic' => $topic->id]))->assertOk()->assertSee('Editorial quality')->assertSee('8 / 10');
    }

    public function test_all_batch_and_run_screens_render_with_real_record_links(): void
    {
        $owner = $this->admin();
        $topic = $this->completeTopic();
        $run = TopicBuildRun::query()->create(['request_key' => 'display-run', 'site_key' => 'primary', 'topic_id' => $topic->id, 'owner_admin_id' => $owner->id, 'identity' => [], 'input' => ['field' => 'intro'], 'result' => ['intro' => 'Suggested introduction'], 'status' => 'needs_adoption', 'phase' => 'finished']);
        $batch = TopicImportBatch::query()->create(['request_key' => 'display-batch', 'owner_admin_id' => $owner->id, 'site_key' => 'primary', 'settings' => [], 'rows' => [['number' => 1, 'title' => 'Visible title', 'status' => 'failed', 'topic_id' => $topic->id, 'run_id' => $run->id, 'duplicate_of' => null, 'error' => 'Retry this row']], 'status' => 'needs_attention', 'generation' => 1]);
        $this->actingAs($owner, 'admin')->get(route('admin.topics.batches.create'))->assertOk()->assertSee('批量新建专题');
        $this->get(route('admin.topics.batches.show', ['batch' => $batch->id]))->assertOk()->assertSee('仅重试失败项')->assertSee('专题 #'.$topic->id)->assertSee('生成 #'.$run->id);
        $this->get(route('admin.topics.runs.show', ['run' => $run->id]))->assertOk()->assertSee('Suggested introduction')->assertSee('采用到工作稿')->assertSee('本次仅采用「导语」字段');
    }

    public function test_task_interval_edit_preserves_a_valid_nonminute_interval(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.tasks.store'), array_replace($this->taskPayload(), ['interval_value' => 61, 'interval_unit' => 'second']))->assertRedirect();
        $task = Task::query()->sole();
        $this->get(route('admin.tasks.edit', ['taskId' => $task->id]))->assertOk()->assertSee('value="61"', false);
        $this->assertSame(61, $task->publish_interval);
    }

    public function test_editor_json_cannot_close_its_script_tag_with_user_content(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => '</script><script>alert(1)</script>']);
        $this->actingAs($this->admin(), 'admin')->get(route('admin.topics.edit', ['topic' => $topic->id]))
            ->assertOk()->assertDontSee('</script><script>alert(1)</script>', false)
            ->assertSee('\\u003C', false);
    }

    public function test_adopting_one_field_keeps_other_manual_fields(): void
    {
        $owner = $this->admin();
        $topic = $this->completeTopic();
        $manual = $topic->draft_payload;
        $hashes = Article::query()->whereIn('id', array_column($manual['articles'], 'article_id'))->get()->mapWithKeys(fn (Article $a) => [$a->id => TopicService::contentHash($a)])->all();
        $run = TopicBuildRun::query()->create([
            'request_key' => 'single-field-run', 'site_key' => 'primary', 'topic_id' => $topic->id, 'owner_admin_id' => $owner->id,
            'identity' => ['model_access_admin_id' => $owner->id, 'model_access_admin_role' => 'admin', 'ai_config_access_version' => 1, 'resolver_policy_version' => 1],
            'input' => ['field' => 'intro'], 'result' => ['intro' => 'Suggested introduction.', 'summary' => ['one_sentence' => 'Replace everything'], 'tags' => ['Suggested tag'], 'articles' => $manual['articles'], 'source_hashes' => $hashes],
            'status' => 'needs_adoption', 'phase' => 'finished',
        ]);
        $this->actingAs($owner, 'admin')->post(route('admin.topics.runs.action', ['run' => $run->id, 'action' => 'adopt']), ['expected_version' => 1])
            ->assertRedirect(route('admin.topics.edit', ['topic' => $topic->id]));
        $saved = $topic->fresh()->draft_payload;
        $this->assertSame('Suggested introduction.', $saved['intro']);
        $this->assertSame($manual['summary'], $saved['summary']);
        $this->assertSame($manual['tags'], $saved['tags']);
        $this->assertSame($manual['articles'], $saved['articles']);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_approved_task_topic_shows_fixed_revision_and_waits_for_scheduled_publication(): void
    {
        $owner = $this->admin();
        $topic = $this->completeTopic();
        $task = Task::query()->create(['name' => 'Scheduled topic task', 'content_type' => 'topic', 'target_site_key' => 'primary']);
        $topic->update(['task_id' => $task->id]);
        TopicBuildRun::query()->create(['request_key' => 'deferred-review', 'site_key' => 'primary', 'topic_id' => $topic->id, 'task_id' => $task->id, 'expected_version' => 1, 'owner_admin_id' => $owner->id, 'input' => ['defer_publication' => true], 'identity' => [], 'status' => 'completed', 'phase' => 'finished']);
        $revision = app(TopicService::class)->publish($topic, 1, $owner->id, true);
        $this->actingAs($owner, 'admin')->post(route('admin.topics.action', ['topic' => $topic->id, 'action' => 'approve']), ['expected_revision_id' => $revision->id])->assertRedirect();
        $this->assertNull($topic->fresh()->public_revision_id);
        $this->assertSame($revision->id, $topic->fresh()->approved_revision_id);
        $this->get(route('admin.topics.edit', ['topic' => $topic->id]))->assertOk()->assertSee('已审核，等待计划发布')->assertSee('预览审核版本');
        $this->get(route('admin.topics.index'))->assertOk()->assertSee(route('admin.topics.revisions.preview', ['topic' => $topic->id, 'revision' => $revision->id]), false);
    }

    public function test_returning_from_csv_preview_keeps_mode_and_imported_multiline_titles(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $settings = ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'guide', 'after' => 'draft_only', 'target_count' => 8];
        $this->post(route('admin.topics.batches.preview'), $settings + ['csv' => UploadedFile::fake()->createWithContent('topics.csv', "标题\n\"Multi\nline\"\nSecond\n")])->assertOk();
        $this->get(route('admin.topics.batches.create'))->assertOk()->assertSee('value="draft" selected', false)->assertSee('Multi');
        $this->post(route('admin.topics.batches.preview'), $settings + ['titles_text' => "Multi line\nSecond"])->assertOk()->assertViewHas('rows', fn ($rows) => count($rows) === 2 && $rows[0]['title'] === 'Multi line');
    }

    public function test_review_csv_recognizes_topic_title_header(): void
    {
        $csv = UploadedFile::fake()->createWithContent('topics.csv', "topic_title\nFirst topic\nSecond topic\n");
        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.batches.preview'), ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'default', 'after' => 'draft_only', 'target_count' => 8, 'csv' => $csv])->assertOk()->assertViewHas('rows', fn ($rows) => count($rows) === 2 && $rows[0]['title'] === 'First topic');
    }

    public function test_review_csv_normalizes_embedded_newline_to_one_title(): void
    {
        $csv = UploadedFile::fake()->createWithContent('topics.csv', "标题\n\"Multi\nline\"\n");
        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.batches.preview'), ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'default', 'after' => 'draft_only', 'target_count' => 8, 'csv' => $csv])->assertOk()->assertViewHas('rows', fn ($rows) => count($rows) === 1 && $rows[0]['title'] === 'Multi line');
    }

    public function test_review_task_can_clear_all_selected_categories_and_bound_topics(): void
    {
        $actor = $this->admin();
        $topic = $this->completeTopic();
        $category = Category::query()->first();
        $payload = $this->taskPayload();
        $payload['topic_settings']['category_ids'] = [$category->id];
        $payload['topic_settings']['bound_topic_ids'] = [$topic->id];
        $this->actingAs($actor, 'admin')->post(route('admin.tasks.store'), $payload)->assertRedirect();
        $task = Task::query()->sole();
        $this->put(route('admin.tasks.update', ['taskId' => $task->id]), array_replace($this->taskPayload(), ['topic_config_version' => 1]))->assertRedirect();
        $this->assertSame([], $task->fresh()->topic_settings['category_ids'] ?? []);
        $this->assertSame([], $task->fresh()->topic_settings['bound_topic_ids'] ?? []);
        $this->assertNull($topic->fresh()->maintenance_task_id);
    }

    public function test_review_primary_editor_does_not_disclose_ineligible_source_title(): void
    {
        $profile = $this->hosted();
        $task = Task::query()->create(['name' => 'Hosted source task', 'publish_scope' => 'distribution_only']);
        $source = $this->article('Secret hosted source', ['status' => 'private', 'task_id' => $task->id]);
        HostedSiteArticleAssignment::query()->create(['article_id' => $source->id, 'hosted_site_profile_id' => $profile->id, 'status' => 'published', 'published_at' => now(), 'capacity_date' => now()->toDateString(), 'content_fingerprint' => hash('sha256', $source->content), 'assigned_at' => now()]);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.topics.store'), ['site' => 'primary', 'title' => 'Primary draft', 'template_key' => 'default', 'articles' => [['article_id' => $source->id]], 'action' => 'save'])->assertRedirect();
        $topic = Topic::query()->sole();
        $this->get(route('admin.topics.edit', ['topic' => $topic->id]))->assertOk()->assertDontSee('Secret hosted source');
    }

    public function test_review_first_ai_suggestion_preserves_manually_entered_intro(): void
    {
        $this->withoutExceptionHandling();
        Queue::fake();
        $actor = $this->admin();
        $sources = [$this->article('GEO first'), $this->article('GEO second')];
        $model = AiModel::query()->create(['name' => 'Review model', 'version' => 'test', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'), 'model_id' => 'test-model', 'model_type' => 'chat', 'api_url' => 'https://ai.test', 'daily_limit' => 100, 'status' => 'active', 'owner_admin_id' => $actor->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT]);
        $model->forceFill(['owner_admin_id' => $actor->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();
        $result = ['intro' => 'AI overwrites manual introduction', 'summary' => ['one_sentence' => 'GEO summary'], 'tags' => [], 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)];
        MarkdownContentWriterAgent::fake([json_encode($result), json_encode(['supported' => true, 'unsupported_fields' => []])])->preventStrayPrompts();
        $this->actingAs($actor, 'admin')->post(route('admin.topics.store'), ['site' => 'primary', 'title' => 'GEO Guide', 'intro' => 'My manual introduction', 'template_key' => 'default', 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources), 'action' => 'generate', 'model_id' => $model->id, 'request_key' => (string) Str::uuid(), 'target_count' => 8])->assertRedirect();
        $run = TopicBuildRun::query()->sole();
        app(TopicGenerationService::class)->process($run->id);
        $this->assertSame('My manual introduction', Topic::query()->sole()->draft_payload['intro']);
        $this->assertSame('needs_adoption', $run->fresh()->status);
    }

    public function test_review_task_form_shows_current_manual_publication_count(): void
    {
        $actor = $this->admin();
        $topic = $this->completeTopic();
        $this->actingAs($actor, 'admin')->post(route('admin.tasks.store'), $this->taskPayload())->assertRedirect();
        $task = Task::query()->sole();
        $topic->update(['task_id' => $task->id]);
        $task->update(['created_count' => 1, 'published_count' => 0]);
        app(TopicService::class)->publish($topic, 1, $actor->id);
        $this->get(route('admin.tasks.edit', ['taskId' => $task->id]))->assertOk()->assertSee('已公开 1 个');
    }

    public function test_review_trashed_topic_job_links_to_accessible_history(): void
    {
        $actor = $this->admin();
        $topic = $this->completeTopic();
        $this->actingAs($actor, 'admin')->post(route('admin.tasks.store'), $this->taskPayload())->assertRedirect();
        $task = Task::query()->sole();
        $topic->update(['task_id' => $task->id]);
        TaskRun::query()->create(['task_id' => $task->id, 'content_type' => 'topic', 'topic_id' => $topic->id, 'status' => 'completed', 'meta' => []]);
        $topic->delete();
        $this->get(route('admin.tasks.jobs'))->assertOk()->assertDontSee(route('admin.topics.edit', ['topic' => $topic->id]), false);
        $this->get(route('admin.topics.history', ['topic' => $topic->id]))->assertOk();
    }

    public function test_review_primary_run_comparison_does_not_disclose_hosted_source_title(): void
    {
        $profile = $this->hosted();
        $task = Task::query()->create(['name' => 'Hosted source task', 'publish_scope' => 'distribution_only']);
        $source = $this->article('Secret hosted source', ['status' => 'private', 'task_id' => $task->id]);
        HostedSiteArticleAssignment::query()->create(['article_id' => $source->id, 'hosted_site_profile_id' => $profile->id, 'status' => 'published', 'published_at' => now(), 'capacity_date' => now()->toDateString(), 'content_fingerprint' => hash('sha256', $source->content), 'assigned_at' => now()]);
        $actor = $this->admin();
        $topic = app(TopicService::class)->create('primary', ['title' => 'Primary draft', 'articles' => [['article_id' => $source->id]]], $actor->id);
        $run = TopicBuildRun::query()->create(['request_key' => 'private-comparison', 'site_key' => 'primary', 'topic_id' => $topic->id, 'owner_admin_id' => $actor->id, 'identity' => [], 'input' => [], 'result' => ['intro' => 'Safe suggestion'], 'status' => 'needs_adoption', 'phase' => 'finished']);
        $this->actingAs($actor, 'admin')->get(route('admin.topics.runs.show', ['run' => $run->id]))->assertOk()->assertDontSee('Secret hosted source');
    }

    private function taskPayload(): array
    {
        return ['content_type' => 'topic', 'name' => 'Topic task', 'target_site_key' => 'primary', 'topic_limit' => 10, 'interval_value' => 1, 'interval_unit' => 'hour', 'status' => 'paused', 'topic_settings' => ['after' => 'auto_publish', 'template_key' => 'default', 'target_count' => 8, 'protect_manual' => '1']];
    }

    private function admin(string $role = 'admin'): Admin
    {
        return Admin::query()->create(['username' => 'topics-'.Str::random(10), 'password' => 'secret-123', 'email' => Str::random(10).'@example.test', 'role' => $role, 'status' => 'active']);
    }

    private function article(string $title, array $overrides = []): Article
    {
        $category = Category::query()->firstOrCreate(['slug' => 'topic-sources'], ['name' => 'Sources']);
        $author = Author::query()->firstOrCreate(['email' => 'topic-sources@example.test'], ['name' => 'Source Author']);

        return Article::query()->create(array_replace(['title' => $title, 'slug' => 'source-'.Str::random(12), 'content' => 'Distinct body '.Str::random(40), 'excerpt' => 'Public source excerpt', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()], $overrides));
    }

    private function completeTopic(): Topic
    {
        $first = $this->article('First source');
        $second = $this->article('Second source');

        return app(TopicService::class)->create('primary', ['title' => 'Complete '.Str::random(6), 'intro' => 'A sourced introduction.', 'articles' => [['article_id' => $first->id], ['article_id' => $second->id]]]);
    }

    private function hosted(): HostedSiteProfile
    {
        $channel = DistributionChannel::query()->create(['name' => 'Hosted', 'domain' => 'topics.sites.test', 'endpoint_url' => 'https://topics.sites.test', 'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE]);

        return HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id, 'hostname' => 'topics.sites.test', 'root_domain' => 'sites.test']);
    }
}
