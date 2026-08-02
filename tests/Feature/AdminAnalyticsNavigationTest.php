<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAnalyticsNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_growth_center_exposes_overview_and_five_topic_pages(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics'));

        $response
            ->assertOk()
            ->assertSee(route('admin.analytics.content'), false)
            ->assertSee(route('admin.analytics.traffic'), false)
            ->assertSee(route('admin.analytics.ai-visibility'), false)
            ->assertSee(route('admin.analytics.leads'), false)
            ->assertSee(route('admin.analytics.distribution'), false)
            ->assertDontSee('data-analytics-log-chart', false)
            ->assertDontSee('data-ai-visibility-series', false)
            ->assertDontSee('data-analytics-health-grid', false);

        $this->assertSame(4, substr_count($response->getContent(), 'lg:col-span-6'));
        $this->assertStringNotContainsString('lg:col-span-5', $response->getContent());
        $this->assertStringNotContainsString('lg:col-span-7', $response->getContent());

        foreach (['content', 'traffic', 'ai-visibility', 'leads', 'distribution'] as $page) {
            $this->get(route("admin.analytics.{$page}"))->assertOk();
        }
    }

    public function test_overview_shows_five_core_metrics_and_only_the_highest_priority_alert(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $form = LeadForm::query()->create([
            'name' => '咨询表单',
            'slug' => 'analytics-contact',
            'status' => LeadForm::STATUS_ACTIVE,
            'fields' => [],
        ]);
        LeadSubmission::query()->create([
            'lead_form_id' => $form->id,
            'status' => LeadSubmission::STATUS_NEW,
            'payload' => ['name' => '测试访客'],
            'source_url' => '/',
            'ip_address' => '10.0.0.1',
        ]);
        DB::table('view_logs')->insert([
            'source' => 'local',
            'method' => 'GET',
            'path' => '/',
            'status_code' => 200,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics'));

        $response
            ->assertOk()
            ->assertSee(__('admin.analytics.overview.metrics.today_visits'))
            ->assertSee(__('admin.analytics.overview.metrics.published_7d'))
            ->assertSee(__('admin.analytics.overview.metrics.brand_visibility_60d'))
            ->assertSee(__('admin.analytics.overview.metrics.new_leads'))
            ->assertSee(__('admin.analytics.overview.metrics.pending_followups'))
            ->assertSee(__('admin.analytics.overview.alerts.new_leads.title', ['count' => 1]))
            ->assertDontSee(__('admin.analytics.overview.alerts.ai_unconfigured.title'));

        $this->assertSame(1, substr_count($response->getContent(), 'data-analytics-priority-alert'));
    }

    public function test_content_report_does_not_render_dashboard_health_modules(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics.content'));

        $response
            ->assertOk()
            ->assertDontSee('data-analytics-health-grid', false)
            ->assertDontSee(__('admin.dashboard.task_health'))
            ->assertDontSee(__('admin.dashboard.material_health'))
            ->assertDontSee(__('admin.dashboard.ai_health'))
            ->assertDontSee(__('admin.dashboard.url_import_health'));
    }

    public function test_regular_admin_can_open_business_reports_but_cannot_open_distribution_report(): void
    {
        $admin = $this->admin('admin');

        $overview = $this->actingAs($admin, 'admin')->get(route('admin.analytics'));

        $overview
            ->assertOk()
            ->assertDontSee(route('admin.analytics.distribution'), false);

        foreach (['content', 'traffic', 'ai-visibility', 'leads'] as $page) {
            $this->get(route("admin.analytics.{$page}"))->assertOk();
        }

        $this->get(route('admin.analytics.distribution'))->assertForbidden();
    }

    public function test_legacy_growth_center_queries_redirect_to_the_matching_topic_page(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'log_preset' => '60d',
                'log_source' => 'local',
                'article_id' => 9,
            ]))
            ->assertRedirect(route('admin.analytics.traffic', [
                'log_preset' => '60d',
                'log_source' => 'local',
                'article_id' => 9,
            ]));

        $this->get(route('admin.analytics', [
            'preset' => '30d',
            'category_id' => 3,
        ]))->assertRedirect(route('admin.analytics.content', [
            'preset' => '30d',
            'category_id' => 3,
        ]));
    }

    public function test_legacy_channel_filter_redirects_to_the_protected_distribution_report(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics', [
                'preset' => '30d',
                'channel_id' => 7,
                'article_id' => 9,
            ]))
            ->assertRedirect(route('admin.analytics.distribution', [
                'preset' => '30d',
                'channel_id' => 7,
                'article_id' => 9,
            ]));

        $admin->update(['role' => 'admin']);

        $this->actingAs($admin->fresh(), 'admin')
            ->get(route('admin.analytics', ['channel_id' => 7]))
            ->assertForbidden();
    }

    public function test_growth_reports_require_admin_authentication(): void
    {
        foreach (['admin.analytics', 'admin.analytics.content', 'admin.analytics.traffic', 'admin.analytics.ai-visibility', 'admin.analytics.leads', 'admin.analytics.distribution'] as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('admin.login'));
        }
    }

    public function test_lead_report_has_a_safe_empty_state_before_lead_tables_are_migrated(): void
    {
        Schema::dropIfExists('lead_submissions');
        Schema::dropIfExists('lead_forms');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics.leads'))
            ->assertOk()
            ->assertSee(__('admin.analytics.no_data'));
    }

    public function test_lead_report_handles_a_missing_forms_table_with_existing_submissions(): void
    {
        $form = LeadForm::query()->create([
            'name' => '待迁移表单',
            'slug' => 'partially-migrated-form',
            'status' => LeadForm::STATUS_ACTIVE,
            'fields' => [],
        ]);
        LeadSubmission::query()->create([
            'lead_form_id' => $form->id,
            'status' => LeadSubmission::STATUS_NEW,
            'payload' => ['name' => '迁移期访客'],
            'source_url' => '/',
            'ip_address' => '10.0.0.2',
        ]);
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('lead_forms');
        Schema::enableForeignKeyConstraints();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics.leads'))
            ->assertOk()
            ->assertSee(__('admin.leads.deleted_form'));
    }

    public function test_distribution_report_has_a_safe_empty_state_when_a_dependency_table_is_missing(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('distribution_channels');
        Schema::enableForeignKeyConstraints();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics.distribution'))
            ->assertOk()
            ->assertSee(__('admin.analytics.no_data'));
    }

    public function test_old_task_failures_do_not_replace_the_current_overview_alert(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        LeadForm::query()->create([
            'name' => '启用表单',
            'slug' => 'active-overview-form',
            'status' => LeadForm::STATUS_ACTIVE,
            'fields' => [],
        ]);
        $task = Task::query()->create(['name' => '历史任务', 'status' => 'active']);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'failed',
            'error_message' => '历史错误',
        ]);
        $run->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->saveQuietly();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertDontSee(__('admin.analytics.overview.alerts.content_failed.title', ['count' => 1]))
            ->assertSee(__('admin.analytics.overview.alerts.ai_unconfigured.title'));
    }

    private function admin(string $role = 'super_admin'): Admin
    {
        return Admin::query()->create([
            'username' => 'analytics_navigation_admin',
            'password' => 'secret-123',
            'email' => 'analytics-navigation@example.com',
            'display_name' => 'Analytics Navigation Admin',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
