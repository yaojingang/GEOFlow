<?php

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminWelcomeModalService;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

$app = require __DIR__.'/article_workflow_bootstrap.php';
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate', ['--force' => true]);
// The application's minimal SQLite test schema omits the PostgreSQL audit table.
if (! Schema::hasTable('admin_activity_logs')) {
    Schema::create('admin_activity_logs', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('admin_id')->nullable();
        $table->string('admin_username', 50);
        $table->string('admin_role', 20)->default('admin');
        $table->string('action', 120);
        $table->string('request_method', 10)->default('POST');
        $table->string('page')->default('');
        $table->string('target_type', 50)->default('');
        $table->unsignedBigInteger('target_id')->nullable();
        $table->string('ip_address', 64)->default('');
        $table->text('details')->default('');
        $table->timestamp('created_at')->useCurrent();
    });
}
$admin = Admin::query()->create([
    'username' => 'browser_workflow', 'password' => 'browser-workflow-test-only',
    'email' => 'browser-workflow@example.test', 'display_name' => 'Browser workflow fixture',
    'role' => 'super_admin', 'status' => 'active',
    'welcome_seen_version' => app(AdminWelcomeModalService::class)->currentWelcomeVersionKey(),
]);
$model = new AiModel([
    'name' => 'Browser deterministic model (never called)', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
    'model_id' => 'browser-test', 'model_type' => 'chat', 'api_url' => 'https://model.invalid/v1', 'status' => 'active',
]);
$model->forceFill(['owner_admin_id' => $admin->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();
$prompt = Prompt::query()->create(['name' => 'Browser content prompt', 'type' => 'content', 'content' => 'Write {{title}}']);
$library = TitleLibrary::query()->create(['name' => 'Browser title library']);
foreach (range(1, 10) as $number) {
    Title::query()->create(['library_id' => $library->id, 'title' => 'Unused browser title '.$number]);
}
$category = Category::query()->create(['name' => 'Browser category', 'slug' => 'browser-category']);
$author = Author::query()->create(['name' => 'Browser author']);
$content = 'Verified browser fixture facts.';
$contentHash = hash('sha256', $content);
$knowledge = KnowledgeBase::query()->create([
    'name' => 'Browser knowledge', 'content' => $content, 'file_type' => 'markdown', 'review_status' => 'reviewed',
    'chunk_sync_status' => 'ready', 'chunk_source_hash' => $contentHash,
    'chunk_serving_generation' => 'browser-generation', 'chunk_serving_source_hash' => $contentHash,
]);
KnowledgeChunk::query()->create([
    'knowledge_base_id' => $knowledge->id, 'chunk_index' => 0, 'content' => $content,
    'content_hash' => $contentHash, 'source_hash' => $contentHash, 'generation_key' => 'browser-generation',
]);
$task = Task::query()->create([
    'name' => 'Browser workflow task', 'title_library_id' => $library->id, 'prompt_id' => $prompt->id,
    'ai_model_id' => $model->id, 'model_access_admin_id' => $admin->id, 'author_id' => $author->id,
    'status' => 'paused', 'schedule_enabled' => 0, 'need_review' => 0, 'publish_interval' => 900,
    'article_limit' => 10, 'draft_limit' => 5, 'category_mode' => 'fixed', 'fixed_category_id' => $category->id,
    'publish_scope' => 'local_only', 'model_selection_mode' => 'fixed', 'ai_quality_enabled' => false,
    'ai_quality_prompt_id' => Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->value('id'),
    'ai_quality_model_id' => $model->id, 'ai_quality_pass_score' => 85, 'ai_quality_manual_override_min_score' => 70,
    'ai_quality_retrieval_mode' => 'knowledge_broad', 'ai_quality_optimization_level' => 'excellent_80',
]);
$task->knowledgeBases()->sync([$knowledge->id => ['sort_order' => 0]]);
$ids = [];
foreach (['First selected', 'Second selected', 'Mixed approved', 'Mixed blocked', 'Held article'] as $index => $title) {
    $article = Article::query()->create([
        'title' => $title, 'slug' => 'browser-article-'.$index, 'content' => 'Verified browser fixture facts.',
        'category_id' => $category->id, 'author_id' => $author->id,
        'task_id' => $index === 4 ? $task->id : null, 'status' => 'draft',
        'review_status' => $index === 2 ? 'approved' : 'pending', 'publication_intent' => 'hold',
    ]);
    $ids[] = $article->id;
}
$qualityTask = $task->replicate();
$qualityTask->fill(['name' => 'Score release fixture', 'ai_quality_enabled' => true])->save();
$qualityTask->knowledgeBases()->sync([$knowledge->id => ['sort_order' => 0]]);
$qualityArticle = Article::query()->create([
    'title' => 'Score release evidence example', 'slug' => 'score-release-example', 'content' => $content,
    'category_id' => $category->id, 'author_id' => $author->id, 'task_id' => $qualityTask->id,
    'status' => 'draft', 'review_status' => 'auto_approved', 'publication_intent' => 'scheduled',
]);
$qualityCheck = app(ArticleAiQualityInspectionService::class)->createOrReuse($qualityArticle->fresh(), dispatch: false);
$qualityCheck->forceFill([
    'status' => 'completed', 'decision' => 'passed', 'score' => 90, 'pass_score' => 85,
    'knowledge_coverage' => 'insufficient', 'gate_reasons' => [], 'active_dedupe_key' => null,
    'summary' => '普通证据缺口已计入最终分数。',
    'execution_meta' => array_replace((array) $qualityCheck->execution_meta, ['score_policy' => [
        'version' => 'score-release-1',
        'adjustments' => [['reason' => 'evidence_coverage_insufficient', 'dimension' => 'data_traceability', 'deduction' => 10]],
    ]]), 'finished_at' => now(),
])->save();
echo json_encode(['task_id' => $task->id, 'article_ids' => $ids, 'quality_article_id' => $qualityArticle->id], JSON_THROW_ON_ERROR).PHP_EOL;
