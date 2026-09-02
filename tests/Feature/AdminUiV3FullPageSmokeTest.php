<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiConversation;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\ManualPublication;
use App\Models\Prompt;
use App\Models\SiteThemeReplication;
use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use App\Models\Task;
use App\Models\TitleGenerationRun;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Support\AdminUiRegistry;
use Database\Seeders\UiV3ReviewSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminUiV3FullPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('admin_activity_logs')) {
            Schema::create('admin_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->nullable();
                $table->string('admin_username', 50);
                $table->string('admin_role', 20)->default('admin');
                $table->string('action', 120);
                $table->string('request_method', 10)->default('POST');
                $table->string('page')->default('');
                $table->string('target_type', 50)->default('');
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('ip_address', 64)->default('');
                $table->text('details')->default('');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function test_every_v3_shell_page_renders_with_review_fixtures(): void
    {
        Storage::fake('local');
        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('geoflow.update_center_enabled', true);
        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.root_domains', ['ui-v3-review.test']);
        $this->seed(UiV3ReviewSeeder::class);

        $admin = Admin::query()->where('username', 'ui_v3_reviewer')->firstOrFail();
        $parameters = $this->routeParameters();
        $registry = app(AdminUiRegistry::class);
        $shellRoutes = collect(Route::getRoutes())
            ->filter(fn (LaravelRoute $route): bool => in_array('GET', $route->methods(), true))
            ->filter(fn (LaravelRoute $route): bool => is_string($route->getName())
                && $registry->routeClassification($route->getName()) === 'shell')
            ->sortBy(fn (LaravelRoute $route): string => (string) $route->getName())
            ->values();

        $this->assertCount(102, $shellRoutes);

        foreach ($shellRoutes as $route) {
            $routeName = (string) $route->getName();
            $response = $this
                ->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])
                ->actingAs($admin, 'admin')
                ->get(route($routeName, $parameters[$routeName] ?? []));

            $this->assertSame(200, $response->status(), $routeName);
            $response->assertSee('data-gf-shell', false, $routeName);
            $response->assertSee('data-admin-product-footer', false, $routeName);

            $document = new \DOMDocument;
            @$document->loadHTML((string) $response->getContent());
            $xpath = new \DOMXPath($document);
            $headings = $xpath->query('//main[@id="main-content"]//h1');
            $topbarIdentities = $xpath->query('//*[@data-gf-topbar-identity]');
            $content = $xpath->query('//main[@id="main-content"]//*[@data-gf-page-heading]')?->item(0);
            $identity = $registry->pageIdentity($routeName);
            $settingsNavigations = $xpath->query('//*[@data-settings-navigation]');

            $this->assertSame(1, $headings?->length, $routeName.' must render exactly one page title');
            $this->assertSame(1, $topbarIdentities?->length, $routeName.' must render one topbar identity');
            $this->assertSame($identity['icon'], $topbarIdentities?->item(0)?->attributes?->getNamedItem('data-page-icon')?->nodeValue, $routeName);
            $this->assertSame($identity['body_heading'], $content?->attributes?->getNamedItem('data-gf-page-heading')?->nodeValue, $routeName);
            $this->assertStringContainsString($identity['title'], $topbarIdentities?->item(0)?->textContent ?? '', $routeName);

            $showsSettingsNavigation = $registry->activeKey($routeName) === 'site_settings'
                && ! str_starts_with($routeName, 'admin.account.');
            $this->assertSame($showsSettingsNavigation ? 1 : 0, $settingsNavigations?->length, $routeName);

            if ($showsSettingsNavigation) {
                $settingsNavigation = $settingsNavigations?->item(0);
                $activeSettingsItems = $xpath->query('.//*[@aria-current="page"]', $settingsNavigation);
                $expectedActiveItem = collect($registry->settingsNavigation($admin, $routeName))
                    ->firstWhere('active', true);

                $this->assertIsArray($expectedActiveItem, $routeName);
                $this->assertSame(1, $activeSettingsItems?->length, $routeName);
                $this->assertSame(
                    $expectedActiveItem['key'],
                    $activeSettingsItems?->item(0)?->attributes?->getNamedItem('data-settings-navigation-item')?->nodeValue,
                    $routeName,
                );
            }
        }
    }

    public function test_all_auxiliary_admin_get_flows_match_the_registry_contract(): void
    {
        Storage::fake('local');
        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('geoflow.update_center_enabled', true);
        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.root_domains', ['ui-v3-review.test']);
        $this->seed(UiV3ReviewSeeder::class);

        $admin = Admin::query()->where('username', 'ui_v3_reviewer')->firstOrFail();
        $parameters = $this->routeParameters();
        $registry = app(AdminUiRegistry::class);
        $routesByClassification = collect(Route::getRoutes())
            ->filter(fn (LaravelRoute $route): bool => in_array('GET', $route->methods(), true))
            ->filter(fn (LaravelRoute $route): bool => is_string($route->getName())
                && str_starts_with($route->getName(), 'admin.'))
            ->groupBy(fn (LaravelRoute $route): string => (string) $registry->routeClassification((string) $route->getName()));

        $this->assertCount(2, $routesByClassification->get('special', collect()));
        $this->assertCount(3, $routesByClassification->get('redirect', collect()));
        $this->assertCount(5, $routesByClassification->get('download', collect()));
        $this->assertCount(14, $routesByClassification->get('endpoint', collect()));

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertDontSee('data-gf-shell', false);

        $authenticated = $this
            ->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])
            ->actingAs($admin, 'admin');
        $authenticated
            ->get(route('admin.site-settings.theme-replications.preview', $parameters['admin.site-settings.theme-replications.preview']))
            ->assertOk()
            ->assertDontSee('data-gf-shell', false);

        foreach ($routesByClassification->get('redirect', collect()) as $route) {
            $routeName = (string) $route->getName();
            $this
                ->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])
                ->actingAs($admin, 'admin')
                ->get(route($routeName, $parameters[$routeName] ?? []))
                ->assertRedirect();
        }

        foreach ($routesByClassification->get('endpoint', collect()) as $route) {
            $routeName = (string) $route->getName();
            $response = $this
                ->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])
                ->actingAs($admin, 'admin')
                ->get(route($routeName, $parameters[$routeName] ?? []));

            $this->assertSame(200, $response->status(), $routeName);
            $identity = $registry->pageIdentity($routeName);
            if ($identity !== null) {
                $response
                    ->assertSee('data-gf-shell', false)
                    ->assertSee('data-page-icon="'.$identity['icon'].'"', false)
                    ->assertSee($identity['title']);
            }
        }

        foreach ($routesByClassification->get('download', collect()) as $route) {
            $routeName = (string) $route->getName();
            $response = $this
                ->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])
                ->actingAs($admin, 'admin')
                ->get(route($routeName, $parameters[$routeName] ?? []));

            if ($routeName === 'admin.articles.batch.export-markdown.download') {
                $response->assertForbidden();

                continue;
            }

            $this->assertContains($response->getStatusCode(), [200, 302], $routeName);
        }
    }

    public function test_review_fixture_pages_do_not_expose_raw_translation_keys(): void
    {
        Storage::fake('local');
        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('geoflow.update_center_enabled', true);
        $this->seed(UiV3ReviewSeeder::class);

        $admin = Admin::query()->where('username', 'ui_v3_reviewer')->firstOrFail();
        $parameters = $this->routeParameters();

        $this
            ->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])
            ->actingAs($admin, 'admin')
            ->get(route('admin.enterprise-knowledge.show', $parameters['admin.enterprise-knowledge.show']))
            ->assertOk()
            ->assertDontSee('admin.no_data');

        $this
            ->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])
            ->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertDontSee('admin.system_updates.backup.status_completed');
    }

    public function test_special_layouts_and_error_recovery_pages_keep_v3_accessibility_contracts(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('gf-login-v3', false)
            ->assertSee('aria-label="'.e(__('admin.auth.language_label')).'"', false);

        $standardAdmin = Admin::query()->create([
            'username' => 'ui_v3_standard_reviewer',
            'password' => 'ui-v3-review-only',
            'email' => 'ui-v3-standard-reviewer@example.test',
            'display_name' => 'UI V3 Standard Reviewer',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->actingAs($standardAdmin, 'admin')
            ->get(route('admin.distribution.index'))
            ->assertForbidden()
            ->assertSee('admin-error-v3.css', false)
            ->assertSee('403');

        $this->get('/admin/ui-v3-review-missing-page')
            ->assertNotFound()
            ->assertSee('admin-error-v3.css', false)
            ->assertSee('404');

        $serverError = view('errors.500')->render();
        $this->assertStringContainsString('admin-error-v3.css', $serverError);
        $this->assertStringContainsString('500', $serverError);
    }

    public function test_legacy_icon_controls_have_accessible_names(): void
    {
        Storage::fake('local');
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $this->seed(UiV3ReviewSeeder::class);

        $admin = Admin::query()->where('username', 'ui_v3_reviewer')->firstOrFail();
        $parameters = $this->routeParameters();
        $authenticated = $this
            ->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])
            ->actingAs($admin, 'admin');

        $authenticated
            ->get(route('admin.keyword-libraries.detail', $parameters['admin.keyword-libraries.detail']))
            ->assertOk()
            ->assertSee('aria-label="'.e(__('admin.common.back')).'"', false)
            ->assertSee('aria-label="'.e(__('admin.common.delete')).'：', false);

        $model = AiModel::query()->where('name', UiV3ReviewSeeder::AI_MODEL_NAME)->firstOrFail();
        $authenticated
            ->get(route('admin.ai-models.edit', ['modelId' => $model->id]))
            ->assertOk()
            ->assertSee('aria-label="'.e(__('admin.common.back')).'"', false)
            ->assertSee('action="'.route('admin.ai-models.update', ['modelId' => $model->id]).'"', false)
            ->assertDontSee('id="modelModal"', false);
    }

    /** @return array<string, array<string, int|string>> */
    private function routeParameters(): array
    {
        $admin = Admin::query()->where('username', 'ui_v3_reviewer')->firstOrFail();
        $aiConversation = AiConversation::query()->firstOrCreate([
            'id' => '01987f84-7f01-7000-8000-000000000001',
        ], [
            'participant_type' => $admin->getMorphClass(),
            'participant_id' => $admin->id,
            'title' => 'UI V3 AI Workspace Review',
        ]);
        $article = Article::query()->where('slug', 'ui-v3-review-article')->firstOrFail();
        $author = Author::query()->where('email', 'ui-v3-review-author@example.test')->firstOrFail();
        $category = Category::query()->where('slug', 'ui-v3-review')->firstOrFail();
        $channel = DistributionChannel::query()->where('name', UiV3ReviewSeeder::CHANNEL_NAME)->firstOrFail();
        $hostedChannel = DistributionChannel::query()->where('name', UiV3ReviewSeeder::HOSTED_CHANNEL_NAME)->firstOrFail();
        $distribution = ArticleDistribution::query()->where('idempotency_key', 'ui-v3-review-distribution')->firstOrFail();
        $project = EnterpriseKnowledgeProject::query()->where('name', UiV3ReviewSeeder::ENTERPRISE_PROJECT_NAME)->firstOrFail();
        $imageLibrary = ImageLibrary::query()->where('name', UiV3ReviewSeeder::IMAGE_LIBRARY_NAME)->firstOrFail();
        $keywordLibrary = KeywordLibrary::query()->where('name', UiV3ReviewSeeder::KEYWORD_LIBRARY_NAME)->firstOrFail();
        $knowledgeBase = KnowledgeBase::query()->where('name', UiV3ReviewSeeder::KNOWLEDGE_BASE_NAME)->firstOrFail();
        $factLibrary = KnowledgeFactLibrary::query()->firstOrCreate(['knowledge_base_id' => $knowledgeBase->id]);
        $factGenerationRun = KnowledgeFactGenerationRun::query()->firstOrCreate([
            'library_id' => $factLibrary->id,
            'request_key' => '01987f84-7f01-7000-8000-000000000103',
        ], [
            'mode' => 'initial', 'target_count' => 1, 'source_hash' => str_repeat('0', 64),
            'base_working_version' => 1, 'status' => 'completed', 'completed_at' => now(),
        ]);
        $leadForm = LeadForm::query()->where('slug', UiV3ReviewSeeder::LEAD_FORM_SLUG)->firstOrFail();
        $lead = LeadSubmission::query()->where('source_url', UiV3ReviewSeeder::LEAD_SOURCE_URL)->firstOrFail();
        $publication = ManualPublication::query()->where('target_url', UiV3ReviewSeeder::PUBLICATION_TARGET_URL)->firstOrFail();
        $model = AiModel::query()->where('name', UiV3ReviewSeeder::AI_MODEL_NAME)->firstOrFail();
        $sourceProvider = AiSourceProvider::query()->firstOrFail();
        $prompt = Prompt::query()->where('type', 'content')->firstOrFail();
        $run = SystemUpdateRun::query()->where('run_uuid', UiV3ReviewSeeder::UPDATE_RUN_UUID)->firstOrFail();
        $backup = SystemUpdateBackup::query()->where('backup_uuid', UiV3ReviewSeeder::BACKUP_UUID)->firstOrFail();
        $task = Task::query()->where('name', UiV3ReviewSeeder::TASK_NAME)->firstOrFail();
        $titleLibrary = TitleLibrary::query()->where('name', UiV3ReviewSeeder::TITLE_LIBRARY_NAME)->firstOrFail();
        $titleGenerationRun = TitleGenerationRun::query()->firstOrCreate([
            'title_library_id' => $titleLibrary->id,
            'status' => TitleGenerationRun::STATUS_COMPLETED,
        ], [
            'created_by_admin_id' => $admin->id,
            'requested_count' => 1,
            'batch_size' => 1,
            'model_request_budget' => 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO 内容工程'],
            'completed_at' => now(),
        ]);
        $import = UrlImportJob::query()->where('normalized_url', UiV3ReviewSeeder::IMPORT_URL)->firstOrFail();
        $replicationId = (int) SiteThemeReplication::query()
            ->where('theme_id', UiV3ReviewSeeder::THEME_ID)
            ->value('id');
        $qualityCheck = ArticleAiQualityCheck::query()->create([
            'article_id' => $article->id,
            'task_id' => $article->task_id,
            'prompt_id' => $prompt->id,
            'ai_model_id' => $model->id,
            'request_key' => '01987f84-7f01-7000-8000-000000000101',
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 88,
            'article_snapshot' => [
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => $article->content,
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
            ],
            'input_fingerprint' => hash('sha256', 'ui-v3-optimization-check'),
            'algorithm_version' => 'ui-v3-test',
            'evaluation_mode' => 'optimization_candidate',
            'inspection_scope' => 'full',
            'gate_applied' => false,
        ]);
        $optimizationRun = ArticleAiOptimizationRun::query()->create([
            'article_id' => $article->id,
            'task_id' => $article->task_id,
            'source_check_id' => $qualityCheck->id,
            'best_check_id' => $qualityCheck->id,
            'request_key' => '01987f84-7f01-7000-8000-000000000102',
            'trigger' => ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            'strategy' => 'excellent_80',
            'target_score' => 80,
            'status' => ArticleAiOptimizationRun::STATUS_CANDIDATE_READY,
            'base_article_hash' => hash('sha256', 'ui-v3-optimization-base'),
            'candidate_hash' => hash('sha256', 'ui-v3-optimization-candidate'),
            'policy_hash' => hash('sha256', 'ui-v3-optimization-policy'),
        ]);

        return [
            'admin.ai-workspace.conversations.show' => ['conversation' => $aiConversation->id],
            'admin.articles.edit' => ['articleId' => $article->id],
            'admin.articles.ai-quality.status' => ['articleId' => $article->id],
            'admin.articles.ai-quality.optimization.candidate' => [
                'articleId' => $article->id,
                'runId' => $optimizationRun->id,
            ],
            'admin.ai-models.edit' => ['modelId' => $model->id],
            'admin.ai-source-providers.edit' => ['providerId' => $sourceProvider->id],
            'admin.ai-prompts.edit' => ['promptId' => $prompt->id],
            'admin.admin-users.edit' => ['adminId' => $admin->id],
            'admin.articles.batch.export-markdown.download' => [
                'exportToken' => str_repeat('A', 40),
                'owner' => $admin->id,
                'filename' => 'geoflow-articles-20260827-120000.zip',
            ],
            'admin.authors.detail' => ['authorId' => $author->id],
            'admin.authors.edit' => ['authorId' => $author->id],
            'admin.categories.edit' => ['categoryId' => $category->id],
            'admin.distribution.article.edit' => ['distributionId' => $distribution->id],
            'admin.distribution.delete' => ['channelId' => $channel->id],
            'admin.distribution.edit' => ['channelId' => $channel->id],
            'admin.distribution.hosted-sites.edit' => ['hostedSite' => $hostedChannel->id],
            'admin.distribution.hosted-sites.show' => ['hostedSite' => $hostedChannel->id],
            'admin.distribution.show' => ['channelId' => $channel->id],
            'admin.distribution.sync-settings.preview' => ['channelId' => $channel->id],
            'admin.enterprise-knowledge.show' => ['projectId' => $project->id],
            'admin.enterprise-knowledge.status' => ['projectId' => $project->id],
            'admin.image-libraries.detail' => ['libraryId' => $imageLibrary->id],
            'admin.image-libraries.edit' => ['libraryId' => $imageLibrary->id],
            'admin.image-libraries.images.create' => ['libraryId' => $imageLibrary->id],
            'admin.keyword-libraries.detail' => ['libraryId' => $keywordLibrary->id],
            'admin.keyword-libraries.edit' => ['libraryId' => $keywordLibrary->id],
            'admin.keyword-libraries.import.create' => ['libraryId' => $keywordLibrary->id],
            'admin.keyword-libraries.keywords.create' => ['libraryId' => $keywordLibrary->id],
            'admin.knowledge-bases.detail' => ['knowledgeBaseId' => $knowledgeBase->id],
            'admin.knowledge-bases.chunks.index' => ['knowledgeBaseId' => $knowledgeBase->id],
            'admin.knowledge-bases.edit' => ['knowledgeBaseId' => $knowledgeBase->id],
            'admin.knowledge-bases.facts.index' => ['knowledgeBaseId' => $knowledgeBase->id],
            'admin.knowledge-bases.fact-generation.show' => ['knowledgeBaseId' => $knowledgeBase->id, 'runId' => $factGenerationRun->id],
            'admin.lead-forms.edit' => ['formId' => $leadForm->id],
            'admin.leads.show' => ['submissionId' => $lead->id],
            'admin.manual-publications.edit' => ['manualPublicationId' => $publication->id],
            'admin.manual-publications.show' => ['manualPublicationId' => $publication->id],
            'admin.site-settings.theme-replications.show' => ['replicationId' => $replicationId],
            'admin.site-settings.theme-replications.package' => ['replicationId' => $replicationId],
            'admin.site-settings.theme-replications.preview' => ['replicationId' => $replicationId, 'page' => 'home'],
            'admin.site-settings.theme-replications.status' => ['replicationId' => $replicationId],
            'admin.system-updates.backups.show' => ['backupUuid' => $backup->backup_uuid],
            'admin.system-updates.runs.show' => ['runUuid' => $run->run_uuid],
            'admin.tasks.edit' => ['taskId' => $task->id],
            'admin.title-libraries.ai-generate' => ['libraryId' => $titleLibrary->id],
            'admin.title-libraries.ai-generate.status' => ['libraryId' => $titleLibrary->id, 'runId' => $titleGenerationRun->id],
            'admin.title-libraries.detail' => ['libraryId' => $titleLibrary->id],
            'admin.title-libraries.edit' => ['libraryId' => $titleLibrary->id],
            'admin.title-libraries.import.create' => ['libraryId' => $titleLibrary->id],
            'admin.title-libraries.titles.create' => ['libraryId' => $titleLibrary->id],
            'admin.url-import.show' => ['jobId' => $import->id],
            'admin.url-import.status' => ['jobId' => $import->id],
            'admin.locale.switch' => ['locale' => 'zh_CN'],
        ];
    }
}
