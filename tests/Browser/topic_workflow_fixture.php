<?php

use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminWelcomeModalService;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Services\Topics\TopicService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

$app = require __DIR__.'/topic_workflow_bootstrap.php';
$app->make(Kernel::class)->bootstrap();
if (($argv[1] ?? '') === 'execute-task') {
    config(['queue.default' => 'null']);
    $task = Task::query()->findOrFail((int) ($argv[2] ?? 0));
    if ($task->content_type !== 'topic' || $task->status !== 'active') {
        throw new RuntimeException('The browser worker only runs its active topic task.');
    }
    if (($argv[3] ?? '') === 'advance-next-interval') {
        $nextInterval = collect([$task->next_run_at, $task->next_publish_at])->filter()->max();
        Carbon::setTestNow($nextInterval?->copy()->addSecond() ?? now());
    }
    $queue = app(JobQueueService::class);
    $run = TaskRun::query()->where('task_id', $task->id)->where('status', 'pending')->orderBy('id')->first();
    $id = $run?->id ?? $queue->enqueueTaskJob($task->id);
    if (! $id) {
        throw new RuntimeException('The browser worker could not enqueue its next task run.');
    }
    (new ProcessGeoFlowTaskJob($id))->handle($queue, app(WorkerExecutionService::class), app(AiExecutionContextFactory::class));
    $run = TaskRun::query()->findOrFail($id);
    if ($run->status !== 'completed') {
        throw new RuntimeException('The browser task run did not complete: '.$run->status.' '.$run->error_message);
    }
    echo json_encode(['task_run_id' => $run->id, 'topic_id' => $run->meta['topic_id'] ?? null, 'status' => $run->status, 'test_clock' => Carbon::getTestNow()?->toIso8601String()], JSON_THROW_ON_ERROR).PHP_EOL;

    return;
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Browser fixture migration failed: '.Artisan::output());
}
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
$admin = Admin::query()->create(['username' => 'topic_browser', 'password' => 'topic-browser-test-only', 'email' => 'topic-browser@example.test', 'display_name' => '专题本地验收', 'role' => 'super_admin', 'status' => 'active', 'welcome_seen_version' => app(AdminWelcomeModalService::class)->currentWelcomeVersionKey()]);
$model = new AiModel(['name' => '本地测试模型（固定验收结果）', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-only-key'), 'model_id' => 'browser-test', 'model_type' => 'chat', 'api_url' => 'https://model.invalid/v1', 'status' => 'active']);
$model->forceFill(['owner_admin_id' => $admin->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();
$library = TitleLibrary::query()->create(['name' => 'GEO 专题测试标题库']);
foreach (['GEO 浏览器任务标题一', 'GEO 浏览器任务标题二'] as $title) {
    Title::query()->create(['library_id' => $library->id, 'title' => $title]);
}
$author = Author::query()->create(['name' => '专题测试编辑']);
$category = Category::query()->create(['name' => 'GEO 内容研究', 'slug' => 'topic-browser']);
$ids = [];
foreach (['明确文章的来源', '建立结构化摘要', '记录内容更新时间', '专题选文与阅读顺序', 'GEO 文章的引用信息', '前台模板中的专题模块'] as $i => $title) {
    $ids[] = Article::query()->create(['title' => 'GEO '.$title, 'slug' => 'topic-browser-'.$i, 'content' => '<p>本地验收材料 '.($i + 1).'：'.$title.'。每篇文章提供独立内容，供专题选文测试。</p>', 'excerpt' => $title.'的本地验收材料。', 'author_id' => $author->id, 'category_id' => $category->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()->subDays($i)])->id;
}
SiteSetting::query()->updateOrCreate(['setting_key' => 'site_name'], ['setting_value' => 'GEOFlow 专题体验站 · 示例数据']);
$service = app(TopicService::class);
$published = [];
foreach (['default' => 'GEO 来源与内容整理', 'guide' => 'GEO 专题阅读指南', 'roundup' => 'GEO 内容汇总'] as $template => $title) {
    $groups = ['基础核对', '结构摘要', '阅读实践'];
    $first = Article::query()->findOrFail($ids[0]);
    $quote = '明确文章的来源';
    $start = mb_strpos($first->content, $quote);
    $payload = ['title' => $title, 'intro' => '此页面展示专题的来源文章、结构化摘要与阅读建议。内容和模型结果均用于本地交互验收。', 'template_key' => $template,
        'summary' => ['one_sentence' => '专题由本站公开文章二次组合而成。', 'facts' => [['text' => '第一篇材料讨论明确文章的来源。', 'article_ids' => [$ids[0]], 'evidence' => [['article_id' => $ids[0], 'field' => 'content', 'start' => $start, 'end' => $start + mb_strlen($quote), 'text' => $quote, 'sha256' => hash('sha256', $quote)]]]], 'reading_advice' => '按序查看选文，并通过原文核对材料。'],
        'tags' => ['GEO', '内容整理'], 'articles' => array_map(fn (int $id, int $index): array => ['article_id' => $id, 'group' => $groups[intdiv($index, 2)], 'reason' => '公开来源示例：第 '.($index + 1).' 篇阅读材料'], $ids, array_keys($ids))];
    if ($template === 'default') {
        $payload['score'] = ['enabled' => true, 'type' => 'editorial', 'name' => '编辑评分示例', 'source' => '本地编辑评分示例：按当前来源的阅读组织与引用完整度评价，仅用于交互验收。', 'total' => 8.5,
            'dimensions' => [['name' => '阅读组织', 'score' => 8.0], ['name' => '引用完整度', 'score' => 9.0]], 'weights' => [1, 1],
            'evidence' => [['text' => '两篇公开材料分别提供来源与摘要示例，供编辑判断阅读组织及引用完整度。', 'article_ids' => array_slice($ids, 0, 2)]], 'rated_at' => now()->toDateString(), 'valid_until' => now()->addDays(7)->toDateString()];
    }
    if ($template === 'guide') {
        $payload['seo'] = ['title' => 'GEO 搜索阅读指南 · 本地示例', 'description' => '专题搜索展示描述示例：依据本站公开来源提供分组阅读指南。'];
    }
    $topic = $service->create('primary', $payload, $admin->id);
    $service->publish($topic, 1, $admin->id);
    $published[$template] = ['id' => $topic->id, 'slug' => $topic->slug, 'title' => $title];
}
echo json_encode(['admin_id' => $admin->id, 'article_ids' => $ids, 'model_id' => $model->id, 'library_id' => $library->id, 'published' => $published], JSON_THROW_ON_ERROR).PHP_EOL;
