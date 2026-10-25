<?php

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\Task;
use App\Services\Admin\AdminWelcomeModalService;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Api\ThemeWorkspaceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$app = require __DIR__.'/link_pool_bootstrap.php';
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate', ['--force' => true]);
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

Admin::query()->create([
    'username' => 'browser_link_pool', 'password' => 'browser-link-pool-test-only',
    'email' => 'browser-link-pool@example.test', 'display_name' => 'Link pool browser fixture',
    'role' => 'super_admin', 'status' => 'active',
    'welcome_seen_version' => app(AdminWelcomeModalService::class)->currentWelcomeVersionKey(),
]);
SiteSetting::query()->updateOrCreate(['setting_key' => 'article_recommendations'], [
    'setting_value' => json_encode(['enabled' => false, 'revision' => 0, 'heading' => '推荐内容', 'default_limit' => 4, 'rotation_hours' => 24]),
]);
$themeId = (string) config('geoflow.default_theme');
SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $themeId]);
$files = app(ThemeWorkspaceService::class)->sourceContents($themeId, 'builtin');
$articlePath = 'resources/views/theme/'.$themeId.'/article.blade.php';
$files[$articlePath] = str_replace("    @include('site.partials.link-pool')", '', $files[$articlePath]);
$files[$articlePath] = preg_replace('/'.preg_quote("__('site.article_related')", '/').'/', "'相关文章'", $files[$articlePath], 1);
$baseRevision = app(ThemeRevisionStorage::class)->create((string) Str::uuid(), $themeId, $files, []);
SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => $themeId, 'revision_id' => $baseRevision->id, 'settings' => [], 'lock_version' => 0]);
$hostedThemeId = 'default';
$hostedFiles = app(ThemeWorkspaceService::class)->sourceContents($hostedThemeId, 'builtin');
$hostedArticlePath = 'resources/views/site/article.blade.php';
$hostedFiles[$hostedArticlePath] = str_replace("    @include('site.partials.link-pool')", '', $hostedFiles[$hostedArticlePath]);
$hostedFiles[$hostedArticlePath] = str_replace("__('site.article_related')", "'相关文章'", $hostedFiles[$hostedArticlePath]);
$hostedFiles['public/themes/default/theme.css'] = 'body { color: #111827; }';
$hostedBaseRevision = app(ThemeRevisionStorage::class)->create((string) Str::uuid(), $hostedThemeId, $hostedFiles, []);
$hostedChannel = DistributionChannel::query()->create([
    'name' => '浏览器托管站', 'domain' => 'adaptation.sites.test', 'endpoint_url' => 'https://adaptation.sites.test',
    'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
    'template_key' => $hostedThemeId, 'site_settings' => ['theme_id' => $hostedThemeId, 'site_name' => '浏览器托管站', 'article_recommendations' => ['enabled' => false]],
]);
$hostedProfile = HostedSiteProfile::query()->create([
    'distribution_channel_id' => $hostedChannel->id, 'hostname' => 'adaptation.sites.test', 'root_domain' => 'sites.test',
    'serving_status' => HostedSiteProfile::SERVING_ONLINE, 'indexing_status' => HostedSiteProfile::INDEXING_INDEX,
    'quality_status' => HostedSiteProfile::QUALITY_PASSED,
]);
$hostedSiteKey = 'hosted:'.$hostedProfile->id;
SiteThemeBinding::query()->create(['site_key' => $hostedSiteKey, 'theme_id' => $hostedThemeId, 'revision_id' => $hostedBaseRevision->id, 'settings' => [], 'lock_version' => 0]);
$author = Author::query()->create(['name' => 'Link pool browser author']);
$task = Task::query()->create(['name' => 'Link pool browser task', 'status' => 'active', 'publish_scope' => 'local_and_distribution']);
$ids = [];
foreach (['source' => 'Source browser article', 'target' => 'Target browser article'] as $slug => $title) {
    $category = Category::query()->create(['name' => $title.' category', 'slug' => 'browser-link-pool-'.$slug]);
    $article = Article::query()->create([
        'title' => $title, 'slug' => $slug, 'content' => str_repeat($title.' content. ', 20),
        'category_id' => $category->id, 'author_id' => $author->id, 'task_id' => $task->id,
        'status' => 'published', 'review_status' => 'approved', 'published_at' => now(),
    ]);
    $ids[$slug] = $article->id;
}
echo json_encode(['article_ids' => $ids, 'base_revision_id' => $baseRevision->id, 'hosted_site_key' => $hostedSiteKey, 'hosted_channel_id' => $hostedChannel->id, 'hosted_base_revision_id' => $hostedBaseRevision->id], JSON_THROW_ON_ERROR).PHP_EOL;
