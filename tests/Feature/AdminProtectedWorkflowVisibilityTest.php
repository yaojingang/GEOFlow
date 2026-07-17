<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DistributionChannel;
use App\Models\SiteThemeReplication;
use App\Models\UrlImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminProtectedWorkflowVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_admin_pages_do_not_query_or_render_super_admin_workflows(): void
    {
        $admin = Admin::query()->create([
            'username' => 'restricted_visibility_admin',
            'password' => 'secret-123',
            'email' => 'restricted-visibility@example.com',
            'display_name' => 'Restricted Visibility Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        DistributionChannel::query()->create([
            'name' => 'Sensitive Distribution Channel',
            'domain' => 'distribution-secret.example',
            'endpoint_url' => 'https://distribution-secret.example/api',
            'channel_type' => 'wordpress',
            'status' => 'active',
        ]);
        UrlImportJob::query()->create([
            'url' => 'https://url-import-secret.example/private',
            'normalized_url' => 'https://url-import-secret.example/private',
            'source_domain' => 'url-import-secret.example',
            'page_title' => 'Sensitive URL Import Title',
            'status' => 'completed',
            'current_step' => 'parsed',
            'progress_percent' => 100,
        ]);
        SiteThemeReplication::query()->create([
            'name' => 'Sensitive Replication Name',
            'theme_id' => 'sensitive-replication-theme',
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'home_url' => 'https://theme-secret.example/',
            'category_url' => 'https://theme-secret.example/category',
            'article_url' => 'https://theme-secret.example/article',
            'style_preference' => 'content_site',
        ]);

        $this->actingAs($admin, 'admin');

        $this->assertProtectedTablesAreNotQueried(
            ['distribution_channels', 'article_distributions', 'url_import_jobs'],
            fn () => $this->get(route('admin.dashboard'))
                ->assertOk()
                ->assertDontSee(route('admin.distribution.index'), false)
                ->assertDontSee(route('admin.distribution.jobs'), false)
                ->assertDontSee(route('admin.url-import.history'), false),
        );

        $this->assertProtectedTablesAreNotQueried(
            ['distribution_channels', 'article_distributions', 'url_import_jobs'],
            fn () => $this->get(route('admin.analytics'))
                ->assertOk()
                ->assertDontSee(route('admin.distribution.index'), false)
                ->assertDontSee(route('admin.url-import.history'), false)
                ->assertDontSee('Sensitive URL Import Title')
                ->assertDontSee('url-import-secret.example'),
        );

        $this->get(route('admin.materials.index'))
            ->assertOk()
            ->assertDontSee(route('admin.url-import'), false)
            ->assertDontSee(route('admin.url-import.store'), false)
            ->assertDontSee(route('admin.url-import.history'), false);

        $this->assertProtectedTablesAreNotQueried(
            ['site_theme_replications'],
            fn () => $this->get(route('admin.site-settings.index'))
                ->assertOk()
                ->assertDontSee(route('admin.site-settings.theme-replications.create'), false)
                ->assertDontSee('Sensitive Replication Name')
                ->assertDontSee('sensitive-replication-theme'),
        );
    }

    /**
     * @param  list<string>  $tables
     */
    private function assertProtectedTablesAreNotQueried(array $tables, callable $request): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $request();

        $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode("\n"));
        DB::disableQueryLog();

        foreach ($tables as $table) {
            $this->assertStringNotContainsString($table, $sql, "Unexpected query against {$table}.");
        }
    }
}
