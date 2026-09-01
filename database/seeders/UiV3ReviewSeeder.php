<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\HostedSiteProfile;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\Prompt;
use App\Models\SiteThemeReplication;
use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class UiV3ReviewSeeder extends Seeder
{
    public const TASK_NAME = 'UI V3 审查任务';

    public const KEYWORD_LIBRARY_NAME = 'UI V3 审查词库';

    public const TITLE_LIBRARY_NAME = 'UI V3 审查标题库';

    public const IMAGE_LIBRARY_NAME = 'UI V3 审查图片库';

    public const KNOWLEDGE_BASE_NAME = 'UI V3 审查知识库';

    public const ENTERPRISE_PROJECT_NAME = 'UI V3 审查企业知识项目';

    public const CHANNEL_NAME = 'UI V3 审查分发渠道';

    public const HOSTED_CHANNEL_NAME = 'UI V3 审查托管站点';

    public const HOSTNAME = 'ui-v3-review-hosted.test';

    public const LEAD_FORM_SLUG = 'ui-v3-review-contact';

    public const LEAD_SOURCE_URL = 'https://ui-v3-review.test/contact';

    public const PUBLICATION_TARGET_URL = 'https://ui-v3-review.test/community/topic';

    public const IMPORT_URL = 'https://ui-v3-review.test/reference';

    public const THEME_ID = 'ui-v3-review-theme';

    public const UPDATE_RUN_UUID = 'ui-v3-review-update-run';

    public const BACKUP_UUID = 'ui-v3-review-backup';

    public const AI_MODEL_NAME = 'UI V3 审查模型（禁用）';

    public const AI_SOURCE_PROVIDER_KEY = 'ui_v3_review_provider';

    public const REVIEW_ADMIN_USERNAME = 'ui_v3_reviewer';

    public const STANDARD_ADMIN_USERNAME = 'ui_v3_standard_reviewer';

    public const REVIEW_PASSWORD = 'ui-v3-review-only';

    private const REVIEW_ARTICLE_SLUG = 'ui-v3-review-article';

    private const REVIEW_MANIFEST_PATH = 'ui-v3-review/system-update/manifest.json';

    public function run(): void
    {
        DB::transaction(function (): void {
            $admin = Admin::query()->updateOrCreate(
                ['username' => self::REVIEW_ADMIN_USERNAME],
                [
                    'password' => self::REVIEW_PASSWORD,
                    'email' => 'ui-v3-review@example.test',
                    'display_name' => 'UI V3 Reviewer',
                    'role' => 'super_admin',
                    'status' => 'active',
                ],
            );

            Admin::query()->updateOrCreate(
                ['username' => self::STANDARD_ADMIN_USERNAME],
                [
                    'password' => self::REVIEW_PASSWORD,
                    'email' => 'ui-v3-standard-reviewer@example.test',
                    'display_name' => 'UI V3 Standard Reviewer',
                    'role' => 'admin',
                    'status' => 'active',
                ],
            );

            $apiKeyCrypto = app(ApiKeyCrypto::class);
            $model = AiModel::query()->updateOrCreate(
                ['name' => self::AI_MODEL_NAME],
                [
                    'version' => 'review',
                    'api_key' => $apiKeyCrypto->encrypt('ui-v3-review-disabled'),
                    'model_id' => 'ui-v3-review-disabled',
                    'model_type' => 'chat',
                    'api_url' => 'https://ui-v3-review.invalid/v1',
                    'failover_priority' => 99,
                    'daily_limit' => 0,
                    'used_today' => 0,
                    'total_used' => 0,
                    'status' => 'inactive',
                    'max_tokens' => 4096,
                ],
            );
            $model->forceFill([
                'owner_admin_id' => $admin->id,
                'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            ])->save();

            AiSourceProvider::query()->updateOrCreate(
                ['provider_key' => self::AI_SOURCE_PROVIDER_KEY],
                [
                    'name' => 'UI V3 审查信源（禁用）',
                    'endpoint_url' => 'https://ui-v3-review.invalid/search',
                    'api_key' => $apiKeyCrypto->encrypt('ui-v3-review-disabled'),
                    'status' => 'inactive',
                    'daily_limit' => 0,
                    'used_today' => 0,
                    'total_used' => 0,
                    'metadata_json' => ['review_fixture' => true],
                ],
            );

            $prompt = Prompt::query()->updateOrCreate(
                ['name' => 'UI V3 审查内容 Prompt'],
                [
                    'type' => 'article',
                    'content' => '基于 {{title}} 和内部审查资料生成结构清晰的演示内容。',
                    'variables' => json_encode(['title'], JSON_UNESCAPED_UNICODE),
                ],
            );

            $keywordLibrary = KeywordLibrary::query()->updateOrCreate(
                ['name' => self::KEYWORD_LIBRARY_NAME],
                ['description' => '用于 UI V3 逐页审查的演示词库。', 'keyword_count' => 2],
            );
            Keyword::query()->updateOrCreate(
                ['library_id' => $keywordLibrary->id, 'keyword' => '生成式引擎优化'],
                ['used_count' => 3, 'usage_count' => 8],
            );
            Keyword::query()->updateOrCreate(
                ['library_id' => $keywordLibrary->id, 'keyword' => '企业知识库'],
                ['used_count' => 2, 'usage_count' => 5],
            );

            $titleLibrary = TitleLibrary::query()->updateOrCreate(
                ['name' => self::TITLE_LIBRARY_NAME],
                [
                    'description' => '用于 UI V3 详情与编辑页审查的标题库。',
                    'title_count' => 2,
                    'generation_type' => 'manual',
                    'keyword_library_id' => $keywordLibrary->id,
                    'ai_model_id' => $model->id,
                    'prompt_id' => $prompt->id,
                    'generation_rounds' => 1,
                    'is_ai_generated' => 0,
                ],
            );
            $sourceTitle = Title::query()->updateOrCreate(
                ['library_id' => $titleLibrary->id, 'title' => 'GEO 内容工程完整实践指南'],
                ['keyword' => '生成式引擎优化', 'is_ai_generated' => false, 'used_count' => 1, 'usage_count' => 3],
            );
            Title::query()->updateOrCreate(
                ['library_id' => $titleLibrary->id, 'title' => '企业知识如何进入 AI 回答'],
                ['keyword' => '企业知识库', 'is_ai_generated' => false, 'used_count' => 0, 'usage_count' => 1],
            );

            $imageLibrary = ImageLibrary::query()->updateOrCreate(
                ['name' => self::IMAGE_LIBRARY_NAME],
                ['description' => '用于 UI V3 空态与非空态审查的图片库。', 'image_count' => 0, 'used_task_count' => 1],
            );

            $knowledgeBase = KnowledgeBase::query()->updateOrCreate(
                ['name' => self::KNOWLEDGE_BASE_NAME],
                [
                    'description' => '仅包含演示信息，不含稳定版数据。',
                    'content' => "GEOFlow 将问题地图、知识资产、任务生产和分发观测组织在一个工作台中。\n该记录用于 UI V3 页面审查。",
                    'character_count' => 61,
                    'used_task_count' => 1,
                    'file_type' => 'markdown',
                    'word_count' => 28,
                    'usage_count' => 3,
                    'source_name' => 'UI V3 Review Fixture',
                    'source_url' => self::IMPORT_URL,
                    'source_type' => 'manual',
                    'business_line' => '产品审查',
                    'risk_level' => 'low',
                    'review_status' => 'reviewed',
                    'chunk_sync_status' => 'idle',
                    'chunk_source_hash' => hash('sha256', self::KNOWLEDGE_BASE_NAME),
                    'chunk_sync_require_real_embedding' => false,
                ],
            );

            $category = Category::query()->updateOrCreate(
                ['slug' => 'ui-v3-review'],
                ['name' => 'UI V3 审查分类', 'description' => '演示分类', 'sort_order' => 98],
            );
            $author = Author::query()->updateOrCreate(
                ['email' => 'ui-v3-review-author@example.test'],
                ['name' => 'UI V3 审查作者', 'bio' => '演示作者资料', 'website' => 'https://ui-v3-review.test'],
            );

            $task = Task::query()->updateOrCreate(
                ['name' => self::TASK_NAME],
                $this->existingColumns('tasks', [
                    'title_library_id' => $titleLibrary->id,
                    'image_library_id' => $imageLibrary->id,
                    'image_count' => 0,
                    'prompt_id' => $prompt->id,
                    'ai_model_id' => $model->id,
                    'author_id' => $author->id,
                    'need_review' => 1,
                    'publish_interval' => 30,
                    'author_type' => 'fixed',
                    'auto_keywords' => 1,
                    'auto_description' => 1,
                    'draft_limit' => 5,
                    'article_limit' => 10,
                    'is_loop' => 0,
                    'model_selection_mode' => 'fixed',
                    'status' => 'paused',
                    'publish_scope' => 'draft',
                    'distribution_strategy' => 'all',
                    'created_count' => 1,
                    'published_count' => 0,
                    'loop_count' => 0,
                    'knowledge_base_id' => $knowledgeBase->id,
                    'category_mode' => 'fixed',
                    'fixed_category_id' => $category->id,
                    'schedule_enabled' => 0,
                    'max_retry_count' => 2,
                ]),
            );

            $article = Article::query()->updateOrCreate(
                ['slug' => self::REVIEW_ARTICLE_SLUG],
                [
                    'title' => 'GEO 内容工程完整实践指南',
                    'excerpt' => '用于 UI V3 内容详情、编辑与分发页面审查的演示文章。',
                    'content' => "# GEO 内容工程\n\n这是一篇可安全修改和恢复的 UI V3 演示文章。",
                    'category_id' => $category->id,
                    'author_id' => $author->id,
                    'task_id' => $task->id,
                    'source_title_id' => $sourceTitle->id,
                    'original_keyword' => '生成式引擎优化',
                    'keywords' => 'GEO,内容工程,AI 可见性',
                    'meta_description' => 'UI V3 页面审查演示文章。',
                    'status' => 'draft',
                    'review_status' => 'pending',
                    'view_count' => 128,
                    'is_ai_generated' => 1,
                ],
            );

            $persona = ManualPublicationPersona::query()->updateOrCreate(
                ['name' => 'UI V3 审查运营角色'],
                [
                    'bio' => '负责演示人工发布流程。',
                    'tone' => '专业清晰',
                    'domain' => 'GEO 内容工程',
                    'disclosure_text' => '此内容为 UI V3 审查演示。',
                    'is_active' => true,
                    'created_by_admin_id' => $admin->id,
                ],
            );
            $account = ManualPublicationAccount::query()->updateOrCreate(
                ['persona_id' => $persona->id, 'platform' => 'zhihu', 'account_name' => 'UI V3 审查账号'],
                [
                    'profile_url' => 'https://ui-v3-review.test/profile',
                    'notes' => '仅用于本地审查。',
                    'is_active' => true,
                    'created_by_admin_id' => $admin->id,
                ],
            );
            $publicationContent = '分享 GEO 内容工程的审查示例，覆盖任务、资产、分发与增长观测。';
            ManualPublication::query()->updateOrCreate(
                ['target_url' => self::PUBLICATION_TARGET_URL],
                [
                    'type' => ManualPublication::TYPE_POST,
                    'article_id' => $article->id,
                    'persona_id' => $persona->id,
                    'account_id' => $account->id,
                    'assigned_admin_id' => $admin->id,
                    'created_by_admin_id' => $admin->id,
                    'platform' => 'zhihu',
                    'target_url_hash' => hash('sha256', self::PUBLICATION_TARGET_URL),
                    'target_context' => 'UI V3 本地审查主题',
                    'content' => $publicationContent,
                    'content_fingerprint' => hash('sha256', $publicationContent),
                    'source_snapshot' => ['review_fixture' => true],
                    'identity_snapshot' => [
                        'persona' => ['id' => (int) $persona->id, 'name' => (string) $persona->name],
                        'account' => ['id' => (int) $account->id, 'account_name' => (string) $account->account_name],
                        'snapshotted_at' => now()->toAtomString(),
                    ],
                    'disclosure_snapshot' => '此内容为 UI V3 审查演示。',
                    'risk_status' => 'clean',
                    'risk_result' => ['passed' => true],
                    'status' => ManualPublication::STATUS_READY,
                    'status_changed_at' => now(),
                ],
            );

            $channel = DistributionChannel::query()->updateOrCreate(
                ['name' => self::CHANNEL_NAME],
                [
                    'domain' => 'ui-v3-review.test',
                    'endpoint_url' => 'https://ui-v3-review.invalid/geoflow-agent/v1',
                    'channel_type' => 'geoflow_agent',
                    'front_mode' => 'static',
                    'template_key' => 'default',
                    'site_settings' => ['site_name' => 'UI V3 Review Site', 'per_page' => 12],
                    'channel_config' => [
                        'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM,
                        DistributionChannel::FRONTEND_CAPABILITIES_CACHE_KEY => [
                            'status' => 'unavailable',
                            'checked_at' => now()->toISOString(),
                            'message' => '审查数据不连接外部站点。',
                            'reachable' => false,
                        ],
                    ],
                    'status' => DistributionChannel::STATUS_PAUSED,
                    'description' => '本地 UI V3 审查渠道，不执行真实分发。',
                    'last_health_status' => 'disabled',
                    'created_by_admin_id' => $admin->id,
                ],
            );
            ArticleDistribution::query()->updateOrCreate(
                ['idempotency_key' => 'ui-v3-review-distribution'],
                [
                    'article_id' => $article->id,
                    'distribution_channel_id' => $channel->id,
                    'action' => 'create',
                    'status' => 'failed',
                    'remote_meta' => ['review_fixture' => true],
                    'attempt_count' => 1,
                    'last_attempt_at' => now()->subMinutes(20),
                    'last_error_message' => '演示渠道已禁用，未发送任何请求。',
                    'payload_hash' => hash('sha256', $article->title),
                ],
            );

            $hostedChannel = DistributionChannel::query()->updateOrCreate(
                ['name' => self::HOSTED_CHANNEL_NAME],
                [
                    'domain' => self::HOSTNAME,
                    'endpoint_url' => 'https://'.self::HOSTNAME,
                    'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
                    'front_mode' => 'dynamic',
                    'template_key' => 'default',
                    'site_settings' => ['site_name' => 'UI V3 Review Hosted Site'],
                    'channel_config' => ['review_fixture' => true],
                    'status' => DistributionChannel::STATUS_PAUSED,
                    'description' => '本地 UI V3 托管站点审查数据。',
                    'created_by_admin_id' => $admin->id,
                ],
            );
            $hostedProfile = HostedSiteProfile::query()->updateOrCreate(
                ['hostname' => self::HOSTNAME],
                [
                    'distribution_channel_id' => $hostedChannel->id,
                    'root_domain' => 'ui-v3-review.test',
                    'topic' => 'GEO 内容工程',
                    'locale' => 'zh_CN',
                    'timezone' => 'Asia/Shanghai',
                    'daily_publish_limit' => 3,
                    'publish_weight' => 100,
                    'min_publish_interval_minutes' => 360,
                    'min_articles_before_index' => 10,
                    'serving_status' => HostedSiteProfile::SERVING_MAINTENANCE,
                    'indexing_status' => HostedSiteProfile::INDEXING_NOINDEX,
                    'quality_status' => HostedSiteProfile::QUALITY_PENDING,
                    'settings_version' => 1,
                    'consecutive_publish_failures' => 0,
                ],
            );

            $leadForm = LeadForm::query()->updateOrCreate(
                ['slug' => self::LEAD_FORM_SLUG],
                [
                    'name' => 'UI V3 审查联系表单',
                    'status' => LeadForm::STATUS_ACTIVE,
                    'description' => '覆盖线索列表、详情和表单编辑页。',
                    'submit_button_label' => '提交需求',
                    'success_message' => '已收到演示需求。',
                    'fields' => [
                        ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'options' => []],
                        ['name' => 'phone', 'label' => '手机', 'type' => 'phone', 'required' => true, 'options' => []],
                        ['name' => 'need', 'label' => '业务需求', 'type' => 'textarea', 'required' => false, 'options' => []],
                    ],
                ],
            );
            LeadSubmission::query()->updateOrCreate(
                ['source_url' => self::LEAD_SOURCE_URL],
                [
                    'lead_form_id' => $leadForm->id,
                    'hosted_site_profile_id' => $hostedProfile->id,
                    'status' => LeadSubmission::STATUS_NEW,
                    'payload' => ['name' => '演示客户', 'phone' => '138****0000', 'need' => '希望了解 GEO 内容工程。'],
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'GEOFlow UI V3 Review Fixture',
                    'note' => '本地审查数据。',
                ],
            );

            EnterpriseKnowledgeProject::query()->updateOrCreate(
                ['name' => self::ENTERPRISE_PROJECT_NAME],
                [
                    'description' => '用于企业知识项目详情和状态页审查。',
                    'status' => 'draft',
                    'draft_content' => 'UI V3 审查企业知识草稿。',
                    'structured_json' => json_encode(['sections' => [], 'review_fixture' => true], JSON_UNESCAPED_UNICODE),
                    'validation_json' => json_encode([['level' => 'pass', 'message' => '演示数据结构完整']], JSON_UNESCAPED_UNICODE),
                    'published_knowledge_base_id' => $knowledgeBase->id,
                    'ai_model_id' => $model->id,
                    'created_by_admin_id' => $admin->id,
                ],
            );

            UrlImportJob::query()->updateOrCreate(
                ['normalized_url' => self::IMPORT_URL],
                [
                    'url' => self::IMPORT_URL,
                    'source_domain' => 'ui-v3-review.test',
                    'page_title' => 'UI V3 审查参考资料',
                    'status' => 'completed',
                    'current_step' => 'completed',
                    'progress_percent' => 100,
                    'options_json' => json_encode(['review_fixture' => true], JSON_UNESCAPED_UNICODE),
                    'result_json' => json_encode(['knowledge_base_id' => $knowledgeBase->id], JSON_UNESCAPED_UNICODE),
                    'created_by' => self::REVIEW_ADMIN_USERNAME,
                    'started_at' => now()->subMinutes(10),
                    'finished_at' => now()->subMinutes(9),
                ],
            );

            SiteThemeReplication::query()->updateOrCreate(
                ['theme_id' => self::THEME_ID],
                [
                    'name' => 'UI V3 审查主题复刻',
                    'base_theme_id' => 'default',
                    'ai_model_id' => $model->id,
                    'status' => SiteThemeReplication::STATUS_READY,
                    'home_url' => 'https://ui-v3-review.test/',
                    'category_url' => 'https://ui-v3-review.test/category/review',
                    'article_url' => 'https://ui-v3-review.test/article/example',
                    'style_preference' => 'content_site',
                    'source_fingerprints' => ['review_fixture' => true],
                    'analysis_json' => ['summary' => '本地审查主题分析。'],
                    'generated_files_json' => ['files' => []],
                    'preview_snapshot_json' => ['review_fixture' => true, 'pages' => ['home', 'category', 'article']],
                    'current_version' => 1,
                    'compliance_status' => 'passed',
                    'compliance_report_json' => ['passed' => true, 'review_fixture' => true],
                    'iteration_count' => 0,
                    'created_by_admin_id' => $admin->id,
                ],
            );

            $run = SystemUpdateRun::query()->updateOrCreate(
                ['run_uuid' => self::UPDATE_RUN_UUID],
                [
                    'action' => 'apply',
                    'status' => 'succeeded',
                    'current_version' => '2.0.0',
                    'target_version' => '2.1.0-review',
                    'current_commit' => 'review-base',
                    'target_commit' => 'review-target',
                    'deployment_mode' => 'local-review',
                    'risk_level' => 'low',
                    'plan_json' => ['review_fixture' => true, 'steps' => ['build', 'test', 'verify']],
                    'started_by_admin_id' => $admin->id,
                    'started_at' => now()->subHour(),
                    'finished_at' => now()->subMinutes(55),
                ],
            );
            $manifest = [
                'review_fixture' => true,
                'files' => [
                    [
                        'path' => 'resources/css/app.css',
                        'action' => 'modified',
                        'old_sha256' => '',
                        'new_sha256' => is_file(base_path('resources/css/app.css'))
                            ? hash_file('sha256', base_path('resources/css/app.css'))
                            : '',
                    ],
                ],
            ];
            Storage::disk('local')->put(
                self::REVIEW_MANIFEST_PATH,
                (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
            SystemUpdateBackup::query()->updateOrCreate(
                ['backup_uuid' => self::BACKUP_UUID],
                [
                    'run_id' => $run->id,
                    'from_version' => '2.0.0',
                    'to_version' => '2.1.0-review',
                    'from_commit' => 'review-base',
                    'to_commit' => 'review-target',
                    'backup_path' => 'ui-v3-review/system-update',
                    'manifest_path' => self::REVIEW_MANIFEST_PATH,
                    'file_count' => 1,
                    'total_bytes' => 0,
                    'status' => 'completed',
                    'created_by_admin_id' => $admin->id,
                ],
            );
        });

        $this->command?->info('UI V3 review fixtures seeded without external requests or production data.');
    }

    /**
     * Keep the local review fixture compatible with the deliberately smaller
     * SQLite schema used by the automated test suite.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function existingColumns(string $table, array $attributes): array
    {
        return collect($attributes)
            ->filter(static fn (mixed $value, string $column): bool => Schema::hasColumn($table, $column))
            ->all();
    }
}
