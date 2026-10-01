<?php

namespace Tests\Feature\Topics;

use App\Models\Admin;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Api\ThemeWorkspaceService;
use App\Services\SystemUpdater\RecoveryPreparation;
use App\Services\Topics\TopicSiteSettings;
use App\Services\Topics\TopicTemplateCatalog;
use App\Services\Topics\TopicThemeCompatibility;
use App\Support\Site\ThemeRevisionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class TopicThemeStateRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_compatibility_proof_keeps_recovery_reference_integrity(): void
    {
        Storage::fake('local');
        $actor = Admin::query()->create(['username' => 'compatibility-probe', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => 'default']);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'topics'], ['setting_value' => json_encode(['enabled' => false])]);
        $contents = app(ThemeWorkspaceService::class)->sourceContents('default', 'builtin');
        unset($contents['public/themes/default/topics.css']);
        $workspace = ThemeWorkspace::query()->create(['id' => (string) Str::uuid(), 'instance_id' => (string) Str::uuid(), 'admin_id' => $actor->id, 'site_key' => 'primary', 'theme_id' => 'default', 'source' => 'builtin', 'state' => 'released']);
        $base = app(ThemeRevisionStorage::class)->create($workspace->id, 'default', $contents, []);
        $workspace->update(['revision_id' => $base->id]);
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => 'default', 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 1]);
        $failure = null;
        try {
            app(TopicThemeCompatibility::class)->ensure('primary', $actor);
        } catch (Throwable $e) {
            $failure = $e;
        }
        $this->assertNotNull($failure, 'Disabled topic channel must reject render proof.');
        $this->assertSame($base->id, SiteThemeBinding::query()->find('primary')->revision_id);
        $this->assertDatabaseMissing('theme_workspaces', ['state' => 'draft', 'revision_id' => null]);
        $this->assertSame('pass', app(RecoveryPreparation::class)->inspect()['status']);
        $this->assertSame(1, ThemeWorkspace::query()->count(), 'A disabled channel must not create a workspace.');
    }

    public function test_failed_render_proof_retains_a_complete_failed_workspace_and_original_binding(): void
    {
        Storage::fake('local');
        $actor = Admin::query()->create(['username' => 'proof-failure', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => 'default']);
        app(TopicSiteSettings::class)->recordOpened('primary');
        $contents = app(ThemeWorkspaceService::class)->sourceContents('default', 'builtin');
        unset($contents['public/themes/default/topics.css']);
        $contents['resources/views/site/topics/index.blade.php'] = "@include('site.compatibility_missing_fixture')";
        $base = $this->revision($actor, $contents);
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => 'default', 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 1]);
        try {
            app(TopicThemeCompatibility::class)->ensure('primary', $actor);
            $this->fail('The missing view must fail template rendering.');
        } catch (Throwable $failure) {
            $this->assertStringContainsString('compatibility_missing_fixture', $failure->getMessage());
        }
        $failed = ThemeWorkspace::query()->where('state', 'failed')->sole();
        $this->assertNotNull($failed->revision_id);
        $this->assertSame($failed->id, ThemeRevision::query()->findOrFail($failed->revision_id)->workspace_id);
        $this->assertSame($base->id, SiteThemeBinding::query()->find('primary')->revision_id);
        $this->assertSame('pass', app(RecoveryPreparation::class)->inspect()['status']);
    }

    public function test_failed_rollback_precheck_preserves_revision_references_and_active_binding(): void
    {
        Storage::fake('local');
        $actor = Admin::query()->create(['username' => 'rollback-proof-failure', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => 'default']);
        app(TopicSiteSettings::class)->recordOpened('primary');
        $contents = app(ThemeWorkspaceService::class)->sourceContents('default', 'builtin');
        $previousContents = $contents;
        $previousContents['resources/views/site/topics/index.blade.php'] = "@include('site.rollback_missing_fixture')";
        $previous = $this->revision($actor, $previousContents);
        $current = $this->revision($actor, $contents);
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => 'default', 'revision_id' => $current->id, 'settings' => [], 'lock_version' => 1]);
        ThemeRelease::query()->create([
            'id' => (string) Str::uuid(), 'site_key' => 'primary', 'workspace_id' => $current->workspace_id,
            'revision_id' => $current->id, 'previous_revision_id' => $previous->id, 'admin_id' => $actor->id,
            'binding_version' => 1, 'kind' => 'topic_compatibility', 'changes' => [], 'plan_sha256' => str_repeat('a', 64),
        ]);
        try {
            app(TopicThemeCompatibility::class)->rollback('primary', $actor, 1);
            $this->fail('The missing rollback view must fail template rendering.');
        } catch (Throwable $failure) {
            $this->assertStringContainsString('rollback_missing_fixture', $failure->getMessage());
        }
        $failed = ThemeWorkspace::query()->where('state', 'failed')->sole();
        $this->assertNotNull($failed->revision_id);
        $this->assertSame($failed->id, ThemeRevision::query()->findOrFail($failed->revision_id)->workspace_id);
        $this->assertSame($current->id, SiteThemeBinding::query()->find('primary')->revision_id);
        $this->assertSame('pass', app(RecoveryPreparation::class)->inspect()['status']);
    }

    public function test_theme_compare_and_swap_failure_leaves_a_complete_failed_workspace(): void
    {
        Storage::fake('local');
        $actor = Admin::query()->create(['username' => 'cas-failure', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => 'default']);
        app(TopicSiteSettings::class)->recordOpened('primary');
        $contents = app(ThemeWorkspaceService::class)->sourceContents('default', 'builtin');
        unset($contents['public/themes/default/topics.css']);
        $base = $this->revision($actor, $contents);
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => 'default', 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 1]);
        ThemeRevision::created(function (ThemeRevision $revision) use ($base): void {
            if ($revision->parent_id === $base->id) {
                SiteSetting::query()->where('setting_key', 'active_theme')->update(['setting_value' => 'other-theme']);
            }
        });
        try {
            app(TopicThemeCompatibility::class)->ensure('primary', $actor);
            $this->fail('A concurrent theme selection must fail compare and swap.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('template_key', $failure->errors());
        }
        $failed = ThemeWorkspace::query()->where('state', 'failed')->sole();
        $this->assertNotNull($failed->revision_id);
        $this->assertSame($base->id, SiteThemeBinding::query()->find('primary')->revision_id);
        $this->assertSame('pass', app(RecoveryPreparation::class)->inspect()['status']);
    }

    private function revision(Admin $actor, array $contents): ThemeRevision
    {
        $workspace = ThemeWorkspace::query()->create([
            'id' => (string) Str::uuid(), 'instance_id' => (string) Str::uuid(), 'admin_id' => $actor->id,
            'site_key' => 'primary', 'theme_id' => 'default', 'source' => 'builtin', 'state' => 'released',
        ]);
        $revision = app(ThemeRevisionStorage::class)->create($workspace->id, 'default', $contents, []);
        $workspace->update(['revision_id' => $revision->id]);

        return $revision;
    }

    public function test_topic_runtime_layout_stays_bound_to_the_frozen_request_snapshot_after_theme_switch(): void
    {
        Storage::fake('local');
        $storage = app(ThemeRevisionStorage::class);
        $revisions = [];
        foreach (['frozen-a', 'frozen-b'] as $theme) {
            $topic = TopicTemplateCatalog::declaration();
            $topic['layouts'][0]['view'] = 'topics/templates/special.blade.php';
            $revisions[$theme] = $storage->create((string) Str::uuid(), $theme, [
                'resources/views/theme/'.$theme.'/manifest.json' => json_encode(['id' => $theme, 'name' => $theme, 'topic' => $topic]),
                'resources/views/theme/'.$theme.'/topics/templates/special.blade.php' => '<h2>'.$theme.'</h2>',
            ], []);
        }
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => 'frozen-a']);
        $binding = SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => 'frozen-a', 'revision_id' => $revisions['frozen-a']->id, 'settings' => [], 'lock_version' => 1]);
        $context = app(ThemeRevisionContext::class);
        $context->preview($revisions['frozen-a'], function () use ($context, $binding, $revisions): void {
            $context->views();
            SiteSetting::query()->where('setting_key', 'active_theme')->update(['setting_value' => 'frozen-b']);
            $binding->update(['theme_id' => 'frozen-b', 'revision_id' => $revisions['frozen-b']->id, 'lock_version' => 2]);
            $this->assertSame('theme.frozen-a.topics.templates.special', app(TopicTemplateCatalog::class)->viewForSite('primary', 'default'));
        });
    }
}
