<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\CaseRecord;
use App\Models\Category;
use App\Models\CollectionRecord;
use App\Models\DistributionChannel;
use App\Models\EntityRecord;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TitleLibrary;
use App\Jobs\ProcessGeoFlowTaskJob;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 后台任务页（Blade）最小可用测试：鉴权与页面渲染。
 */
class AdminTasksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_when_visiting_tasks_page(): void
    {
        $this->get(route('admin.tasks.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_view_tasks_page_with_filters(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin',
            'password' => 'secret-123',
            'email' => 'tasks-admin@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index', ['keyword' => 'demo', 'status' => 'active']))
            ->assertOk()
            ->assertSee(__('admin.tasks.page_title'))
            ->assertSee(__('admin.tasks.empty_title'))
            ->assertViewHas('tasks')
            ->assertViewHas('taskI18n');
    }

    public function test_authenticated_admin_can_open_task_create_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin_create',
            'password' => 'secret-123',
            'email' => 'tasks-admin-create@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        ImageLibrary::query()->create([
            'name' => '任务配图库',
            'description' => '',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        Category::query()->create([
            'name' => '任务分类',
            'slug' => 'task-create-category',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.page_heading'))
            ->assertSee(__('admin.task_create.option.no_image_library'))
            ->assertSee(__('admin.task_create.option.no_image_count'));
    }

    public function test_active_task_is_enqueued_immediately_after_creation_when_async_queue_is_enabled(): void
    {
        Config::set('queue.default', 'redis');
        Queue::fake();

        $admin = Admin::query()->create([
            'username' => 'tasks_enqueue_on_create',
            'password' => 'secret-123',
            'email' => 'tasks-enqueue-on-create@example.com',
            'display_name' => 'Tasks Enqueue Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $collection = CollectionRecord::query()->create([
            'name' => 'Immediate Queue Collection',
            'slug' => 'immediate-queue-collection',
            'status' => 'active',
        ]);
        $titleLibrary = TitleLibrary::query()->create(['name' => 'Immediate Queue Titles']);
        $prompt = Prompt::query()->create([
            'name' => 'Immediate Queue Prompt',
            'type' => 'content',
            'content' => 'Write an article.',
            'status' => 'active',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => 'Immediate Queue Model',
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => 'Immediate Queue Category',
            'slug' => 'immediate-queue-category',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => '创建后立即入队任务',
                'collection_id' => (int) $collection->id,
                'title_library_id' => (int) $titleLibrary->id,
                'prompt_id' => (int) $prompt->id,
                'ai_model_id' => (int) $aiModel->id,
                'fixed_category_id' => (int) $category->id,
                'status' => 'active',
                'publish_scope' => 'local_only',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', '创建后立即入队任务')->firstOrFail();
        $run = TaskRun::query()->where('task_id', (int) $task->id)->firstOrFail();

        $this->assertSame('pending', (string) $run->status);
        $this->assertSame('task_created', (string) (($run->meta['payload']['source'] ?? '')));
        Queue::assertPushed(ProcessGeoFlowTaskJob::class);
    }

    public function test_task_creation_requires_collection(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_without_collection',
            'password' => 'secret-123',
            'email' => 'tasks-without-collection@example.com',
            'display_name' => 'Tasks Without Collection Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $titleLibrary = TitleLibrary::query()->create(['name' => 'No Collection Titles']);
        $prompt = Prompt::query()->create([
            'name' => 'No Collection Prompt',
            'type' => 'content',
            'content' => 'Write an article.',
            'status' => 'active',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => 'No Collection Model',
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => 'No Collection Category',
            'slug' => 'no-collection-category',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => '未指定 Collection 任务',
                'collection_id' => '',
                'title_library_id' => (int) $titleLibrary->id,
                'prompt_id' => (int) $prompt->id,
                'ai_model_id' => (int) $aiModel->id,
                'fixed_category_id' => (int) $category->id,
                'status' => 'paused',
                'publish_scope' => 'local_only',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
            ])
            ->assertSessionHasErrors('collection_id');

        $this->assertDatabaseMissing('tasks', [
            'name' => '未指定 Collection 任务',
        ]);
    }

    public function test_task_can_be_created_with_optional_skill_prompt(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_with_skill_prompt',
            'password' => 'secret-123',
            'email' => 'tasks-with-skill-prompt@example.com',
            'display_name' => 'Tasks Skill Prompt Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $collection = CollectionRecord::query()->create([
            'name' => 'Skill Prompt Collection',
            'slug' => 'skill-prompt-collection',
            'status' => 'active',
        ]);
        $titleLibrary = TitleLibrary::query()->create(['name' => 'Skill Prompt Titles']);
        $prompt = Prompt::query()->create([
            'name' => 'Skill Prompt Master',
            'type' => 'content',
            'content' => 'Write an article.',
            'status' => 'active',
        ]);
        $skillPrompt = Prompt::query()->create([
            'name' => 'Comparison Skill',
            'type' => 'skill',
            'content' => 'Add a comparison table.',
            'status' => 'active',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => 'Skill Prompt Model',
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => 'Skill Prompt Category',
            'slug' => 'skill-prompt-category',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => '带 Skill Prompt 的任务',
                'collection_id' => (int) $collection->id,
                'title_library_id' => (int) $titleLibrary->id,
                'prompt_id' => (int) $prompt->id,
                'skill_prompt_id' => (int) $skillPrompt->id,
                'ai_model_id' => (int) $aiModel->id,
                'fixed_category_id' => (int) $category->id,
                'status' => 'paused',
                'publish_scope' => 'local_only',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $this->assertDatabaseHas('tasks', [
            'name' => '带 Skill Prompt 的任务',
            'prompt_id' => (int) $prompt->id,
            'skill_prompt_id' => (int) $skillPrompt->id,
        ]);
    }

    public function test_task_create_and_edit_forms_use_full_admin_content_width(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_form_layout_admin',
            'password' => 'secret-123',
            'email' => 'tasks-form-layout@example.com',
            'display_name' => 'Tasks Form Layout Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        Category::query()->create([
            'name' => '任务分类',
            'slug' => 'task-form-layout-category',
        ]);
        $task = Task::query()->create([
            'name' => 'Layout Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('data-task-form-shell', false)
            ->assertSee('xl:grid-cols-12', false)
            ->assertSee('lg:grid-cols-3', false)
            ->assertDontSee('max-w-4xl mx-auto', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => (int) $task->id]))
            ->assertOk()
            ->assertSee('data-task-form-shell', false)
            ->assertSee('xl:grid-cols-12', false)
            ->assertDontSee('max-w-4xl mx-auto', false);
    }

    public function test_task_form_disables_distribution_channels_when_local_only_scope_is_selected(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_distribution_scope_admin',
            'password' => 'secret-123',
            'email' => 'tasks-distribution-scope@example.com',
            'display_name' => 'Tasks Distribution Scope Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        Category::query()->create([
            'name' => '任务分类',
            'slug' => 'task-distribution-scope-category',
        ]);
        DistributionChannel::query()->create([
            'name' => '目标站点',
            'domain' => 'target.example.com',
            'endpoint_url' => 'https://target.example.com',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession([
                '_old_input' => [
                    'publish_scope' => 'local_only',
                    'distribution_channel_ids' => ['1'],
                ],
            ])
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('data-publish-scope-option', false)
            ->assertSee('data-distribution-channel-card', false)
            ->assertSee('data-distribution-channel-input', false)
            ->assertSee('syncDistributionChannelsByScope', false)
            ->assertSee('disabled data-distribution-channel-input', false)
            ->assertDontSee('value="1" checked', false);
    }

    public function test_local_only_task_submission_ignores_distribution_channel_ids(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_local_only_submit_admin',
            'password' => 'secret-123',
            'email' => 'tasks-local-only-submit@example.com',
            'display_name' => 'Tasks Local Only Submit Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => '测试模型',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-model',
            'model_type' => 'chat',
            'api_url' => 'https://api.example.com/v1',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => '正文提示词',
            'type' => 'content',
            'content' => '请写 {{title}}',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库',
        ]);
        $collection = CollectionRecord::query()->create([
            'name' => '本站任务 Collection',
            'slug' => 'local-only-task-collection',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '目标站点',
            'domain' => 'target.example.com',
            'endpoint_url' => 'https://target.example.com',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => '仅本站任务',
                'collection_id' => (int) $collection->id,
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $aiModel->id,
                'fixed_category_id' => $category->id,
                'status' => 'paused',
                'publish_scope' => 'local_only',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
                'distribution_channel_ids' => [(string) $channel->id],
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', '仅本站任务')->firstOrFail();
        $this->assertSame('local_only', (string) $task->publish_scope);
        $this->assertDatabaseMissing('task_distribution_channels', [
            'task_id' => (int) $task->id,
            'distribution_channel_id' => (int) $channel->id,
        ]);
    }

    public function test_task_form_can_select_knowledge_tags_for_cross_library_retrieval(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_knowledge_tag_admin',
            'password' => 'secret-123',
            'email' => 'tasks-knowledge-tag@example.com',
            'display_name' => 'Tasks Knowledge Tag Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => '知识标签测试模型',
            'model_id' => 'knowledge-tag-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => '知识标签提示词',
            'type' => 'content',
            'content' => '请基于 {{Knowledge}} 写 {{title}}',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '知识标签标题库',
        ]);
        $collection = CollectionRecord::query()->create([
            'name' => '知识标签 Collection',
            'slug' => 'knowledge-tag-collection',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '知识标签分类',
            'slug' => 'knowledge-tag-category',
        ]);
        $knowledgeA = KnowledgeBase::query()->create([
            'name' => '制造业售后知识库',
            'content' => '智能客服可以降低制造业售后响应时间。',
            'file_type' => 'markdown',
        ]);
        $knowledgeB = KnowledgeBase::query()->create([
            'name' => '制造业质检知识库',
            'content' => '视觉质检可以帮助制造业发现产线异常。',
            'file_type' => 'markdown',
        ]);
        app(TagService::class)->sync($knowledgeA, '行业:制造业');
        app(TagService::class)->sync($knowledgeB, '行业:制造业');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.field.knowledge_tags'))
            ->assertSee('data-tag-label-selector', false)
            ->assertSee('data-tag-label-selected class="flex min-h-[1.75rem] w-full flex-wrap items-center gap-2"', false)
            ->assertSee('data-tag-label-search autocomplete="off"', false)
            ->assertSee('data-tag-label-search-scope="knowledge"', false)
            ->assertSee(route('admin.material-tags.search'), false)
            ->assertDontSee('data-tag-label-selected class="flex min-h-[2.5rem] w-full flex-wrap items-center gap-2 rounded-md border', false)
            ->assertDontSee('行业:制造业')
            ->assertDontSee(__('admin.task_create.option.knowledge_tag_count', ['count' => 2]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => '跨知识库标签任务',
                'collection_id' => (int) $collection->id,
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $aiModel->id,
                'fixed_category_id' => $category->id,
                'status' => 'paused',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
                'knowledge_tag_filters' => ['行业:制造业'],
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', '跨知识库标签任务')->firstOrFail();
        $this->assertNull($task->knowledge_base_id);
        $this->assertSame('行业:制造业', (string) $task->knowledge_tag_filter);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => (int) $task->id]))
            ->assertOk()
            ->assertSee('name="knowledge_tag_filters[]"', false)
            ->assertSee('value="行业:制造业"', false);
    }

    public function test_task_form_can_select_image_tags_within_selected_library(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_image_tag_admin',
            'password' => 'secret-123',
            'email' => 'tasks-image-tag@example.com',
            'display_name' => 'Tasks Image Tag Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => '图片标签测试模型',
            'model_id' => 'image-tag-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => '图片标签提示词',
            'type' => 'content',
            'content' => '请写 {{title}}',
        ]);
        $titleLibrary = TitleLibrary::query()->create(['name' => '图片标签标题库']);
        $collection = CollectionRecord::query()->create([
            'name' => '图片标签 Collection',
            'slug' => 'image-tag-collection',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '图片标签分类',
            'slug' => 'image-tag-category',
        ]);
        $imageLibrary = ImageLibrary::query()->create([
            'name' => '产品图库',
            'image_count' => 2,
        ]);
        $productImage = Image::query()->create([
            'library_id' => (int) $imageLibrary->id,
            'filename' => 'product.png',
            'original_name' => 'product.png',
            'file_path' => 'storage/uploads/images/product.png',
        ]);
        Image::query()->create([
            'library_id' => (int) $imageLibrary->id,
            'filename' => 'other.png',
            'original_name' => 'other.png',
            'file_path' => 'storage/uploads/images/other.png',
        ]);
        app(TagService::class)->sync($productImage, '场景:产品图');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.field.image_tags'))
            ->assertSee('data-field-name="image_tag_filters"', false)
            ->assertSee('data-tag-label-selected class="flex min-h-[1.75rem] w-full flex-wrap items-center gap-2"', false)
            ->assertSee('data-tag-label-search-scope="images"', false)
            ->assertSee(route('admin.material-tags.search'), false)
            ->assertDontSee('场景:产品图')
            ->assertDontSee(__('admin.task_create.option.image_tag_count', ['count' => 1]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => '图库标签交叉筛选任务',
                'collection_id' => (int) $collection->id,
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $aiModel->id,
                'fixed_category_id' => $category->id,
                'status' => 'paused',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
                'image_library_id' => $imageLibrary->id,
                'image_count' => 2,
                'image_tag_filters' => ['场景:产品图'],
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', '图库标签交叉筛选任务')->firstOrFail();
        $this->assertSame('场景:产品图', (string) $task->image_tag_filter);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => (int) $task->id]))
            ->assertOk()
            ->assertSee('name="image_tag_filters[]"', false)
            ->assertSee('value="场景:产品图"', false);
    }

    public function test_task_form_can_save_collection_and_entity_context(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_context_admin',
            'password' => 'secret-123',
            'email' => 'tasks-context@example.com',
            'display_name' => 'Tasks Context Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $collection = CollectionRecord::query()->create([
            'name' => 'Automation Equipment Context',
            'slug' => 'automation-equipment-context',
            'status' => 'active',
        ]);
        $otherCollection = CollectionRecord::query()->create([
            'name' => 'Industrial Cooling Context',
            'slug' => 'industrial-cooling-context',
            'status' => 'active',
        ]);
        $entity = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'description' => '视觉点胶设备型号。',
        ]);
        $otherEntity = EntityRecord::query()->create([
            'collection_id' => (int) $otherCollection->id,
            'name' => 'CHILLER-9000',
            'entity_type' => '产品型号',
            'description' => '制冷设备型号。',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'entity_id' => (int) $entity->id,
            'title' => 'SJ4060 电池灌胶案例',
            'case_type' => '应用案例',
            'summary' => '客户使用 SJ4060 提升灌胶一致性。',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => '任务上下文模型',
            'model_id' => 'task-context-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => '任务上下文提示词',
            'type' => 'content',
            'content' => '请基于 {{Knowledge}} 写 {{title}}',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '任务上下文标题库',
        ]);
        $category = Category::query()->create([
            'name' => '任务上下文分类',
            'slug' => 'task-context-category',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.field.collection'))
            ->assertSee(__('admin.task_create.field.entities'))
            ->assertSee(__('admin.task_create.field.cases'))
            ->assertSee('data-tag-label-search-group="Topic"', false)
            ->assertSee('data-tag-label-search-group="Audience"', false)
            ->assertSee('data-tag-label-search-group="Intent"', false)
            ->assertSee('name="collection_id"', false)
            ->assertSee('name="cross_collection_mode"', false)
            ->assertSee('data-option-multi-selector', false)
            ->assertSee('data-field-name="entity_ids"', false)
            ->assertSee('data-field-name="case_ids"', false)
            ->assertSee('data-option-id="'.(int) $entity->id.'"', false)
            ->assertSee('data-option-collection-id="'.(int) $collection->id.'"', false)
            ->assertSee('data-option-id="'.(int) $otherEntity->id.'"', false)
            ->assertSee('data-option-collection-id="'.(int) $otherCollection->id.'"', false)
            ->assertSee('data-option-filter-hidden', false)
            ->assertSee('Automation Equipment Context')
            ->assertSee('SJ4060')
            ->assertSee('CHILLER-9000')
            ->assertSee('SJ4060 电池灌胶案例');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => 'Collection Entity 错误上下文任务',
                'collection_id' => (int) $collection->id,
                'entity_ids' => [(int) $otherEntity->id],
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $aiModel->id,
                'fixed_category_id' => $category->id,
                'status' => 'paused',
                'article_limit' => 1,
                'draft_limit' => 1,
                'publish_interval' => 60,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
            ])
            ->assertSessionHasErrors('entity_ids');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => 'Collection Entity 上下文任务',
                'collection_id' => (int) $collection->id,
                'cross_collection_mode' => '1',
                'entity_ids' => [(int) $entity->id],
                'case_ids' => [(int) $caseRecord->id],
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $aiModel->id,
                'fixed_category_id' => $category->id,
                'status' => 'paused',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
                'topic_tag_filters' => ['Topic:视觉定位'],
                'intent_tag_filters' => ['Intent:选型'],
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', 'Collection Entity 上下文任务')->firstOrFail();
        $this->assertSame((int) $collection->id, (int) $task->collection_id);
        $this->assertSame(1, (int) $task->cross_collection_mode);
        $this->assertSame((string) $entity->id, (string) $task->entity_filter);
        $this->assertSame((string) $caseRecord->id, (string) $task->case_filter);
        $this->assertSame('Topic:视觉定位, Intent:选型', (string) $task->knowledge_tag_filter);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => (int) $task->id]))
            ->assertOk()
            ->assertSee('name="collection_id"', false)
            ->assertSee('value="'.(int) $collection->id.'" selected', false)
            ->assertSee('name="entity_ids[]"', false)
            ->assertSee('value="'.(int) $entity->id.'"', false)
            ->assertSee('name="case_ids[]"', false)
            ->assertSee('value="'.(int) $caseRecord->id.'"', false)
            ->assertSee('value="Topic:视觉定位"', false)
            ->assertSee('value="Intent:选型"', false);
    }

    public function test_task_article_action_links_to_filtered_article_list(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_article_filter_admin',
            'password' => 'secret-123',
            'email' => 'tasks-article-filter-admin@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $task = Task::query()->create([
            'name' => 'Filtered Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('/'.AdminWeb::basePath().'/articles?task_id='.(int) $task->id, false);
    }

    public function test_task_lifecycle_button_matches_task_status(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_lifecycle_admin',
            'password' => 'secret-123',
            'email' => 'tasks-lifecycle-admin@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $activeTask = Task::query()->create([
            'name' => 'Active Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $pausedTask = Task::query()->create([
            'name' => 'Paused Task',
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk();

        $response->assertSee('id="batch-btn-'.(int) $activeTask->id.'"', false)
            ->assertSee('data-batch-action="stop"', false)
            ->assertSee('data-lucide="circle-stop"', false)
            ->assertDontSee('data-lucide="square"', false)
            ->assertSee('id="batch-btn-'.(int) $pausedTask->id.'"', false)
            ->assertSee('data-batch-action="start"', false)
            ->assertSee('text-green-600 hover:text-green-800 hover:bg-green-50', false);
    }

    public function test_task_list_shows_distribution_failure_summary(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_distribution_status_admin',
            'password' => 'secret-123',
            'email' => 'tasks-distribution-status@example.com',
            'display_name' => 'Tasks Distribution Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => 'Distribution Failure Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $category = Category::query()->create([
            'name' => '任务分发分类',
            'slug' => 'task-distribution-category',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '失败目标站点',
            'domain' => 'failed-target.example.com',
            'endpoint_url' => 'https://failed-target.example.com/geoflow/agent',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '任务分发失败文章',
            'slug' => 'task-distribution-failed-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'failed',
            'idempotency_key' => 'task-list-failed',
            'last_error_message' => 'Target timeout',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee(__('admin.distribution.task_status.failed', ['count' => 1]));
    }

    public function test_authenticated_admin_can_delete_task_without_legacy_article_queue_table(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_delete_admin',
            'password' => 'secret-123',
            'email' => 'tasks-delete-admin@example.com',
            'display_name' => 'Tasks Delete Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $task = Task::query()->create([
            'name' => 'Delete Task Without Legacy Queue',
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.tasks.index'))
            ->post(route('admin.tasks.delete', ['taskId' => (int) $task->id]))
            ->assertRedirect(route('admin.tasks.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', __('admin.tasks.message.delete_success'));

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }
}
