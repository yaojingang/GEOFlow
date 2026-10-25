<?php

namespace Tests\Feature\Topics;

use App\Ai\Agents\TaskCreationAssistant;
use App\Models\Admin;
use App\Models\AiConversation;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\AiWorkspace\AiConversationRepository;
use App\Services\AiWorkspace\AiIntentResolver;
use App\Services\AiWorkspace\TaskCreationCatalog;
use App\Services\AiWorkspace\TaskCreationFlow;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TopicAiWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('ai-workspace.runtime_enabled', true);
        config()->set('ai-workspace.require_verified_model', false);
        Http::preventStrayRequests();
    }

    public function test_topic_confirmation_creates_one_paused_task_with_optional_configuration_and_result_links(): void
    {
        [$owner, $conversation] = $this->workspace();
        TaskCreationAssistant::fake([$this->reply()])->preventStrayPrompts();
        $card = $this->send($conversation, '帮我创建 4 个专题任务')['task_card'];
        self::assertSame('ready', $card['status']);
        self::assertSame([], $card['remaining_fields']);
        self::assertSame(0, Task::query()->count());
        self::assertContains(__('ai-task.topic_generation_modes.auto_publish'), array_column($card['rows'], 'value'));
        $created = $this->send($conversation, '按这个创建', $card)['task_card'];
        $retried = $this->send($conversation, '按这个创建', $created)['task_card'];
        self::assertSame($created['task_id'], $retried['task_id']);
        $task = Task::query()->sole();
        self::assertSame('topic', $task->content_type);
        self::assertSame('primary', $task->target_site_key);
        self::assertSame('paused', $task->status);
        self::assertFalse((bool) $task->schedule_enabled);
        self::assertNull($task->next_run_at);
        self::assertNull($task->title_library_id);
        self::assertNull($task->ai_model_id);
        self::assertSame(4, (int) $task->topic_limit);
        self::assertSame('auto_publish', $task->topic_settings['after']);
        self::assertSame('default', $task->topic_settings['template_key']);
        self::assertSame((int) $owner->id, (int) $task->model_access_admin_id);
        self::assertSame(0, TaskRun::query()->count());
        self::assertContains(route('admin.topics.index', ['site' => 'primary', 'task_id' => $task->id], false), array_column($created['links'], 'url'));
        $this->getJson(route('admin.ai-workspace.conversations.show', ['conversation' => $conversation->id]))
            ->assertOk()->assertJsonPath('data.task_card.task_id', $task->id);
        TaskCreationAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => true);
        TaskCreationAssistant::assertNotPrompted('按这个创建');
    }

    #[DataProvider('titleLibraryStates')]
    public function test_topic_libraries_allow_empty_and_article_consumed_titles(int $titles, int $usedCount): void
    {
        [$owner, $conversation] = $this->workspace();
        $library = TitleLibrary::query()->create(['name' => 'Topic subjects']);
        for ($index = 0; $index < $titles; $index++) {
            Title::query()->create(['library_id' => $library->id, 'title' => 'Subject '.$index, 'used_count' => $usedCount]);
        }
        TaskCreationAssistant::fake([$this->reply(['title_library_id' => $library->id, 'topic_limit' => 30])])->preventStrayPrompts();
        $card = $this->send($conversation, '创建一个专题任务')['task_card'];
        self::assertSame('ready', $card['status']);
        $catalog = app(TaskCreationCatalog::class)->forAdmin($owner);
        self::assertSame($titles, collect($catalog['title_libraries'])->firstWhere('id', $library->id)['titles_count']);
        $this->send($conversation, '按这个创建', $card);
        self::assertSame($library->id, Task::query()->sole()->title_library_id);
        TaskCreationAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    public static function titleLibraryStates(): array
    {
        return ['empty library' => [0, 0], 'used by article tasks' => [1, 7]];
    }

    public function test_super_admin_can_save_hosted_topic_settings_without_casting_strings_or_arrays(): void
    {
        [$owner, $conversation, $model] = $this->workspace('super_admin');
        $site = $this->hosted();
        $category = Category::query()->create(['name' => 'Research sources', 'slug' => 'research-sources']);
        $settings = ['site_key' => 'hosted:'.$site->id, 'ai_model_id' => $model->id, 'after' => 'review_then_publish', 'template_key' => 'guide', 'category_ids' => [$category->id], 'rules' => '使用有出处的研究文章', 'publish_interval_minutes' => 15];
        TaskCreationAssistant::fake([$this->reply($settings)])->preventStrayPrompts();
        $card = $this->send($conversation, '创建一个托管站点专题任务')['task_card'];
        self::assertSame('ready', $card['status']);
        $draft = $conversation->fresh()->task_draft['data'];
        foreach ($settings as $field => $value) {
            self::assertSame($value, $draft[$field]);
        }
        self::assertContains($site->hostname, array_column($card['rows'], 'value'));
        $this->send($conversation, '按这个创建', $card);
        $task = Task::query()->sole();
        self::assertSame('hosted:'.$site->id, $task->target_site_key);
        self::assertSame(900, (int) $task->publish_interval);
        self::assertSame($model->id, $task->ai_model_id);
        self::assertSame('review_then_publish', $task->topic_settings['after']);
        self::assertSame('guide', $task->topic_settings['template_key']);
        self::assertSame([$category->id], $task->topic_settings['category_ids']);
        self::assertSame('使用有出处的研究文章', $task->topic_settings['rules']);
        self::assertSame((int) $owner->id, (int) $task->model_access_admin_id);
        self::assertSame('paused', $task->status);
        TaskCreationAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    public function test_ordinary_admin_catalog_excludes_hosted_sites_and_private_models_and_rejects_fabricated_options(): void
    {
        [$owner, $conversation] = $this->workspace();
        $site = $this->hosted();
        $other = $this->admin('admin');
        $private = $this->model($other, 'Private model');
        $catalog = app(TaskCreationCatalog::class)->forAdmin($owner);
        self::assertSame(['primary'], array_column($catalog['sites'], 'id'));
        self::assertNotContains($private->id, array_column($catalog['models'], 'id'));
        TaskCreationAssistant::fake([$this->reply(['site_key' => 'hosted:'.$site->id, 'ai_model_id' => $private->id, 'title_library_id' => 99999, 'category_ids' => [99999]])])->preventStrayPrompts();
        $card = $this->send($conversation, '创建一个专题任务')['task_card'];
        self::assertSame('collecting', $card['status']);
        foreach (['site_key', 'ai_model_id', 'title_library_id', 'category_ids'] as $field) {
            self::assertContains($field, $card['remaining_fields']);
        }
        $this->send($conversation, '按这个创建', $card);
        self::assertSame(0, Task::query()->count());
        TaskCreationAssistant::assertPrompted(function (AgentPrompt $prompt) use ($private, $site): bool {
            return ! str_contains($prompt->agent->instructions(), $private->name)
                && ! str_contains($prompt->agent->instructions(), $site->hostname);
        });
        TaskCreationAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    #[DataProvider('invalidTopicValues')]
    public function test_invalid_topic_values_keep_other_settings_and_require_correction(string $field, mixed $value): void
    {
        [, $conversation] = $this->workspace();
        TaskCreationAssistant::fake([$this->reply([$field => $value])])->preventStrayPrompts();
        $card = $this->send($conversation, '创建一个专题任务')['task_card'];
        self::assertSame('collecting', $card['status']);
        self::assertContains($field, $card['remaining_fields']);
        self::assertSame('GEO 专题任务', $conversation->fresh()->task_draft['data']['name']);
        $this->send($conversation, '按这个创建', $card);
        self::assertSame(0, Task::query()->count());
        TaskCreationAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    public static function invalidTopicValues(): array
    {
        return [
            'positive count' => ['topic_limit', 0],
            'count ceiling' => ['topic_limit', 100000],
            'known generation mode' => ['after', 'start_now'],
            'known template' => ['template_key', 'invented'],
            'minute interval' => ['publish_interval_minutes', 0],
            'interval ceiling' => ['publish_interval_minutes', 525601],
            'distinct sources' => ['category_ids', [1, 1]],
            'rule length' => ['rules', str_repeat('r', 5001)],
        ];
    }

    public function test_changed_topic_rules_require_the_current_summary_and_preserve_exact_settings(): void
    {
        [, $conversation] = $this->workspace();
        TaskCreationAssistant::fake([
            $this->reply(['rules' => 'Original rule']),
            $this->reply(['rules' => 'Updated rule', 'template_key' => 'roundup', 'after' => 'draft_only', 'publish_interval_minutes' => 7]),
        ])->preventStrayPrompts();
        $old = $this->send($conversation, '创建一个专题任务')['task_card'];
        $oldHash = $conversation->fresh()->task_draft['review_hash'];
        $current = $this->send($conversation, '来源规则改为 Updated rule')['task_card'];
        self::assertNotSame($oldHash, $conversation->fresh()->task_draft['review_hash']);
        self::assertSame($current['revision'], $this->send($conversation, '按这个创建', $old)['task_card']['revision']);
        self::assertSame(0, Task::query()->count());
        $this->send($conversation, '按这个创建', $current);
        $task = Task::query()->sole();
        self::assertSame('Updated rule', $task->topic_settings['rules']);
        self::assertSame('roundup', $task->topic_settings['template_key']);
        self::assertSame('draft_only', $task->topic_settings['after']);
        self::assertSame(420, (int) $task->publish_interval);
        TaskCreationAssistant::assertPrompted('来源规则改为 Updated rule');
    }

    public function test_changed_catalog_label_and_revoked_model_block_the_old_topic_confirmation(): void
    {
        [, $conversation, $model] = $this->workspace();
        $library = TitleLibrary::query()->create(['name' => 'Old library name']);
        TaskCreationAssistant::fake([$this->reply(['title_library_id' => $library->id, 'ai_model_id' => $model->id])])->preventStrayPrompts();
        $card = $this->send($conversation, '创建专题任务')['task_card'];
        $library->update(['name' => 'New library name']);
        $changed = $this->send($conversation, '按这个创建', $card)['task_card'];
        self::assertGreaterThan($card['revision'], $changed['revision']);
        self::assertSame(0, Task::query()->count());
        $model->update(['status' => 'inactive']);
        $blocked = $this->send($conversation, '按这个创建', $changed)['task_card'];
        self::assertSame('collecting', $blocked['status']);
        self::assertContains('ai_model_id', $blocked['remaining_fields']);
        self::assertSame(0, Task::query()->count());
        TaskCreationAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    public function test_explicit_topic_request_cannot_be_created_as_an_article_and_ignores_article_only_fields(): void
    {
        [, $conversation] = $this->workspace();
        TaskCreationAssistant::fake([$this->reply([
            'content_type' => 'article', 'article_limit' => -2, 'prompt_id' => 99999,
            'image_count' => 50, 'knowledge_base_ids' => 'not-an-array', 'status' => 'active',
        ])])->preventStrayPrompts();
        $card = $this->send($conversation, '创建 4 个专题')['task_card'];
        self::assertSame('ready', $card['status']);
        self::assertSame('topic', $conversation->fresh()->task_draft['data']['content_type']);
        self::assertArrayNotHasKey('status', $conversation->fresh()->task_draft['data']);
        $this->send($conversation, '按这个创建', $card);
        self::assertSame('topic', Task::query()->sole()->content_type);
        self::assertSame('paused', Task::query()->sole()->status);
        TaskCreationAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    public function test_topic_local_choices_use_visible_models_and_reject_article_only_fields_without_a_model_call(): void
    {
        [, $conversation, $model] = $this->workspace();
        $library = TitleLibrary::query()->create(['name' => 'Empty topic library']);
        $prompt = Prompt::query()->create(['name' => 'Article template', 'type' => 'content', 'content' => 'Write']);
        TaskCreationAssistant::fake([$this->reply()])->preventStrayPrompts();
        $card = $this->send($conversation, '创建专题任务')['task_card'];
        foreach ([['title_library_id', $library->id], ['ai_model_id', $model->id]] as [$field, $id]) {
            $card = $this->send($conversation, '选择配置', $card, ['field' => $field, 'id' => $id])['task_card'];
        }
        $before = $conversation->fresh()->task_draft;
        $this->send($conversation, '选择文章模板', $card, ['field' => 'prompt_id', 'id' => $prompt->id]);
        self::assertSame($before, $conversation->fresh()->task_draft);
        $this->send($conversation, '按这个创建', $card);
        self::assertSame($library->id, Task::query()->sole()->title_library_id);
        self::assertSame($model->id, Task::query()->sole()->ai_model_id);
        TaskCreationAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    public function test_legacy_article_response_without_type_keeps_the_article_flow(): void
    {
        [, $conversation, $model] = $this->workspace();
        $library = TitleLibrary::query()->create(['name' => 'Article subjects']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'Article subject', 'used_count' => 0]);
        $prompt = Prompt::query()->create(['name' => 'Article template', 'type' => 'content', 'content' => 'Write {{title}}']);
        $category = Category::query()->create(['name' => 'Articles', 'slug' => 'articles']);
        $data = ['name' => 'Legacy article task', 'article_limit' => 1, 'title_library_id' => $library->id, 'prompt_id' => $prompt->id, 'ai_model_id' => $model->id, 'fixed_category_id' => $category->id, 'knowledge_base_ids' => [], 'author_id' => null, 'image_library_id' => null, 'image_count' => 0, 'publish_interval_minutes' => 60];
        $conversation->forceFill(['task_draft' => [...app(TaskCreationFlow::class)->emptyDraft(), 'revision' => 3, 'data' => $data]])->save();
        TaskCreationAssistant::fake([['intent' => 'collect', 'reply' => 'Continue', 'draft' => $data]])->preventStrayPrompts();
        $card = $this->send($conversation, '继续')['task_card'];
        self::assertSame('ready', $card['status']);
        self::assertSame('article', $conversation->fresh()->task_draft['data']['content_type']);
        $this->send($conversation, '按这个创建', $card);
        self::assertSame('article', Task::query()->sole()->content_type);
        self::assertSame(1, (int) Task::query()->sole()->need_review);
        TaskCreationAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    public function test_rules_identify_topic_creation_and_collect_the_topic_count(): void
    {
        $resolution = app(AiIntentResolver::class)->resolveRulesOnly('创建一个专题任务，专题数量为 8，发布间隔 10 分钟');
        self::assertSame('task.draft', $resolution->intent);
        self::assertSame('topic', $resolution->knownParameters['content_type']);
        self::assertSame(8, $resolution->knownParameters['topic_limit']);
        self::assertSame(600, $resolution->knownParameters['publish_interval']);
        $conversation = new AiConversation;
        self::assertTrue(app(TaskCreationFlow::class)->handles($conversation, '创建 4 个专题'));
        self::assertFalse(app(TaskCreationFlow::class)->handles($conversation, '如何创建专题任务？'));
        self::assertFalse(app(TaskCreationFlow::class)->handles($conversation, '当前有几个专题任务？'));
        self::assertFalse(app(TaskCreationFlow::class)->handles($conversation, '专题任务在哪里查看？'));
    }

    public function test_topic_followup_retains_type_and_defaults_when_the_model_omits_new_fields(): void
    {
        [, $conversation] = $this->workspace();
        $initial = $this->reply();
        foreach (['site_key', 'after', 'template_key', 'category_ids', 'rules'] as $field) {
            unset($initial['draft'][$field]);
        }
        $followup = $this->reply(['content_type' => 'article', 'name' => 'Updated topic name']);
        TaskCreationAssistant::fake([$initial, $followup])->preventStrayPrompts();
        $this->send($conversation, '创建专题任务');
        $card = $this->send($conversation, '名称改为 Updated topic name')['task_card'];
        self::assertSame('ready', $card['status']);
        self::assertSame('topic', $conversation->fresh()->task_draft['data']['content_type']);
        $this->send($conversation, '按这个创建', $card);
        $task = Task::query()->sole();
        self::assertSame('topic', $task->content_type);
        self::assertSame('Updated topic name', $task->name);
        self::assertSame('primary', $task->target_site_key);
        self::assertSame('auto_publish', $task->topic_settings['after']);
        self::assertSame('default', $task->topic_settings['template_key']);
        TaskCreationAssistant::assertPrompted('名称改为 Updated topic name');
    }

    public function test_topic_count_is_required_and_cancellation_prevents_creation(): void
    {
        [, $conversation] = $this->workspace();
        TaskCreationAssistant::fake([$this->reply(['topic_limit' => null])])->preventStrayPrompts();
        $card = $this->send($conversation, '创建一个专题任务')['task_card'];
        self::assertSame(['topic_limit'], $card['remaining_fields']);
        self::assertSame(__('ai-task.questions.topic_limit'), $card['questions'][0]['label']);
        $cancelled = $this->send($conversation, '取消创建', $card)['task_card'];
        self::assertSame('cancelled', $cancelled['status']);
        $this->send($conversation, '按这个创建', $cancelled);
        self::assertSame(0, Task::query()->count());
        TaskCreationAssistant::assertNotPrompted('取消创建');
        TaskCreationAssistant::assertNotPrompted('按这个创建');
    }

    #[DataProvider('articleTaskPromptsWithTopicWords')]
    public function test_article_theme_and_name_values_cannot_change_the_task_type(string $prompt): void
    {
        [, $conversation, $model] = $this->workspace();
        $library = TitleLibrary::query()->create(['name' => 'Article subjects']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'Article subject', 'used_count' => 0]);
        $template = Prompt::query()->create(['name' => 'Article template', 'type' => 'content', 'content' => 'Write {{title}}']);
        $category = Category::query()->create(['name' => 'Articles', 'slug' => 'articles']);
        $data = [...app(TaskCreationFlow::class)->emptyDraft()['data'], 'name' => 'AI 专题', 'article_limit' => 1, 'title_library_id' => $library->id, 'prompt_id' => $template->id, 'ai_model_id' => $model->id, 'fixed_category_id' => $category->id];
        $conversation->forceFill(['task_draft' => [...app(TaskCreationFlow::class)->emptyDraft(), 'revision' => 1, 'data' => $data]])->save();
        TaskCreationAssistant::fake([['intent' => 'collect', 'reply' => '已整理文章任务。', 'draft' => $data]])->preventStrayPrompts();
        $card = $this->send($conversation, $prompt)['task_card'];
        self::assertSame('article', $conversation->fresh()->task_draft['data']['content_type']);
        self::assertSame('ready', $card['status']);
        $this->send($conversation, '按这个创建', $card);
        self::assertSame('article', Task::query()->sole()->content_type);
        TaskCreationAssistant::assertPrompted($prompt);
    }

    public static function articleTaskPromptsWithTopicWords(): array
    {
        return [
            'Chinese article theme' => ['创建一个文章任务，主题为 AI 专题研究'],
            'English article theme' => ['Create an article task about topic modeling'],
            'quoted task name' => ['将文章任务名称改为“AI 专题”'],
            'source rule value' => ['将文章任务规则改为创建一个专题任务'],
            'name descriptor before actual article type' => ['创建一个名称为 AI 专题任务的文章任务'],
            'theme descriptor before actual article type' => ['创建一个主题为专题任务的文章任务'],
            'short name descriptor' => ['创建一个名为 AI 专题任务的文章任务'],
            'called descriptor' => ['创建一个叫做 AI 专题任务的文章任务'],
            'quoted name descriptor' => ['创建一个名为“的专题任务”的文章任务'],
            'English article with topic name' => ['Create an article task named Topic Task'],
            'English generic task name' => ['Create a task named Topic Task'],
            'English generic task called value' => ['Create a task called Topic Task'],
            'English generic task name field' => ['Create a task with the name Topic Task'],
            'English generic task theme' => ['Create a task about topic task naming'],
        ];
    }

    #[DataProvider('explicitTopicTaskPromptsWithArticleWords')]
    public function test_explicit_topic_type_actions_retain_topic_type_when_the_prompt_mentions_articles(string $prompt): void
    {
        [, $conversation] = $this->workspace();
        $conversation->forceFill(['task_draft' => [...app(TaskCreationFlow::class)->emptyDraft(), 'revision' => 1]])->save();
        TaskCreationAssistant::fake([$this->reply()])->preventStrayPrompts();
        $card = $this->send($conversation, $prompt)['task_card'];
        self::assertSame('topic', $conversation->fresh()->task_draft['data']['content_type']);
        self::assertSame('ready', $card['status']);
        $this->send($conversation, '按这个创建', $card);
        self::assertSame('topic', Task::query()->sole()->content_type);
        TaskCreationAssistant::assertPrompted($prompt);
    }

    public static function explicitTopicTaskPromptsWithArticleWords(): array
    {
        return [
            'Chinese topic source' => ['创建一个专题任务，使用已发布文章作为来源'],
            'English topic source' => ['Create a topic task about article quality'],
            'article descriptor before topic type' => ['创建一个用于文章汇总的专题任务'],
            'explicit type change' => ['将文章任务改为专题任务，使用现有文章'],
            'task type field' => ['任务类型改为专题，使用现有文章'],
            'short type change' => ['改成专题任务，来源是文章'],
            'topic with article task reference' => ['创建专题任务并使用文章任务的标题库'],
            'name descriptor before actual topic type' => ['创建一个名为文章任务的专题任务'],
        ];
    }

    public function test_ambiguous_task_name_or_theme_returns_no_forced_type(): void
    {
        $flow = app(TaskCreationFlow::class);
        self::assertNull($flow->requestedContentType('Create a task named Topic Task'));
        self::assertNull($flow->requestedContentType('创建一个名称为专题任务的内容任务'));
        self::assertNull($flow->requestedContentType('创建一个主题为专题任务的任务'));
    }

    public function test_quoted_values_in_explicit_task_type_commands_are_preserved(): void
    {
        $flow = app(TaskCreationFlow::class);
        foreach (['将文章任务类型改为“专题”', '任务类型改为「专题」', 'Change task type to "topic"'] as $prompt) {
            self::assertSame('topic', $flow->requestedContentType($prompt));
        }
        self::assertSame('article', $flow->requestedContentType('将专题任务类型改为“文章”'));
        self::assertNull($flow->requestedContentType('将文章任务名称改为“专题”'));
    }

    private function workspace(string $role = 'admin'): array
    {
        $owner = $this->admin($role);
        $model = $this->model($owner, 'Workspace runtime');
        $this->actingAs($owner, 'admin');

        return [$owner, app(AiConversationRepository::class)->create($owner), $model];
    }

    private function admin(string $role): Admin
    {
        return Admin::query()->create(['username' => 'topic-ai-'.Str::random(10), 'email' => Str::random(10).'@example.test', 'password' => 'secret-123', 'role' => $role, 'status' => 'active']);
    }

    private function model(Admin $owner, string $name): AiModel
    {
        $model = new AiModel(['name' => $name, 'model_id' => 'test-chat', 'model_type' => 'chat', 'api_url' => 'https://api.example.test/v1', 'api_key' => app(ApiKeyCrypto::class)->encrypt('topic-test-secret'), 'status' => 'active', 'daily_limit' => 0]);
        $model->forceFill(['owner_admin_id' => $owner->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();

        return $model;
    }

    private function hosted(): HostedSiteProfile
    {
        $channel = DistributionChannel::query()->create(['name' => 'Hosted topics', 'domain' => 'topics.sites.test', 'endpoint_url' => 'https://topics.sites.test', 'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE]);

        return HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id, 'hostname' => 'topics.sites.test', 'root_domain' => 'sites.test']);
    }

    private function reply(array $overrides = []): array
    {
        return ['intent' => 'collect', 'reply' => '已整理专题设置。', 'draft' => [
            'content_type' => 'topic', 'name' => 'GEO 专题任务', 'topic_limit' => 4,
            'site_key' => 'primary', 'title_library_id' => null, 'ai_model_id' => null,
            'after' => 'auto_publish', 'template_key' => 'default', 'category_ids' => [],
            'rules' => null, 'publish_interval_minutes' => 60, ...$overrides,
        ]];
    }

    private function send(AiConversation $conversation, string $prompt, ?array $card = null, ?array $choice = null): array
    {
        $stream = $this->postJson(route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]), [
            'prompt' => $prompt,
            ...($card ? ['task_draft_id' => $card['id'], 'task_draft_revision' => $card['revision']] : []),
            ...($choice ? ['task_choice' => $choice] : []),
        ])->assertOk()->streamedContent();
        self::assertStringNotContainsString('event: error', $stream, $stream);
        preg_match('/event: done\ndata: (.+)/', $stream, $matches);
        self::assertNotEmpty($matches, $stream);

        return json_decode($matches[1], true, 32, JSON_THROW_ON_ERROR);
    }
}
