<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Services\Api\ThemeReleaseService;
use App\Services\Api\ThemeRevisionStorage;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LinkPoolThemeBindingReleaseTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        // The test base creates a new in-memory SQLite connection for each case.
        RefreshDatabaseState::$migrated = false;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => 'default']);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'article_recommendations'], [
            'setting_value' => json_encode(['enabled' => false, 'revision' => 0, 'heading' => '推荐内容']),
        ]);
    }

    public function test_primary_release_and_field_level_rollback_restore_the_frozen_base(): void
    {
        [$admin, $candidate, $proof] = $this->candidate('primary');
        $release = app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof);

        $this->assertSame('link_pool_adaptation', $release->kind);
        $this->assertSame($candidate->parent_id, $release->previous_revision_id);
        $this->assertSame($candidate->id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
        $this->assertTrue($this->primarySettings()['enabled']);
        $this->assertSame(1, $this->primarySettings()['revision']);
        $this->assertFalse(ThemeReleaseService::availability()['available']);

        $rolledBack = app(ThemeReleaseService::class)->rollbackLinkPoolAdaptation($release, $admin);
        $this->assertSame('link_pool_rollback', $rolledBack->kind);
        $this->assertSame($candidate->parent_id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
        $this->assertFalse($this->primarySettings()['enabled']);
        $this->assertSame('推荐内容', $this->primarySettings()['heading']);
        $this->assertSame(2, $this->primarySettings()['revision']);
    }

    public function test_hosted_release_updates_only_the_selected_channel_and_can_rollback(): void
    {
        $first = $this->hosted('first');
        $second = $this->hosted('second');
        $firstVersion = (int) $first->settings_version;
        $secondVersion = (int) $second->settings_version;
        $siteKey = 'hosted:'.$first->id;
        [$admin, $candidate, $proof] = $this->candidate($siteKey);
        $release = app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof);

        $this->assertSame($candidate->id, SiteThemeBinding::query()->findOrFail($siteKey)->revision_id);
        $this->assertTrue($first->channel->fresh()->site_settings['article_recommendations']['enabled']);
        $this->assertSame($firstVersion + 1, (int) $first->fresh()->settings_version);
        $this->assertFalse($second->channel->fresh()->site_settings['article_recommendations']['enabled']);
        $this->assertSame($secondVersion, (int) $second->fresh()->settings_version);

        app(ThemeReleaseService::class)->rollbackLinkPoolAdaptation($release, $admin);
        $this->assertSame($candidate->parent_id, SiteThemeBinding::query()->findOrFail($siteKey)->revision_id);
        $this->assertFalse($first->channel->fresh()->site_settings['article_recommendations']['enabled']);
        $this->assertSame($firstVersion + 2, (int) $first->fresh()->settings_version);
    }

    public function test_stale_settings_reject_the_release_without_writing_a_receipt(): void
    {
        [$admin, $candidate, $proof] = $this->candidate('primary');
        SiteSetting::query()->where('setting_key', 'article_recommendations')->update([
            'setting_value' => json_encode(['enabled' => false, 'revision' => 1]),
        ]);

        try {
            app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof);
            $this->fail('The stale release must be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame('site_settings_conflict', $exception->getErrorCode());
        }

        $this->assertSame(0, ThemeRelease::query()->count());
        $this->assertSame($candidate->parent_id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
    }

    public function test_rollback_rejects_a_later_settings_change(): void
    {
        [$admin, $candidate, $proof] = $this->candidate('primary');
        $release = app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof);
        SiteSetting::query()->where('setting_key', 'article_recommendations')->update([
            'setting_value' => json_encode(['enabled' => true, 'revision' => 2]),
        ]);

        try {
            app(ThemeReleaseService::class)->rollbackLinkPoolAdaptation($release, $admin);
            $this->fail('A later setting change must remain intact.');
        } catch (ApiException $exception) {
            $this->assertSame('theme_rollback_conflict', $exception->getErrorCode());
        }

        $this->assertSame($candidate->id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
        $this->assertSame(1, ThemeRelease::query()->count());
    }

    public function test_unbound_source_mismatch_cannot_publish_a_stale_candidate(): void
    {
        [$admin, $candidate, $proof] = $this->candidate('primary');
        SiteThemeBinding::query()->whereKey('primary')->update(['revision_id' => null]);

        try {
            app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof);
            $this->fail('A candidate from a different source snapshot must be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame('theme_source_changed', $exception->getErrorCode());
        }

        $this->assertSame(0, ThemeRelease::query()->count());
        $this->assertNull(SiteThemeBinding::query()->findOrFail('primary')->revision_id);
    }

    public function test_missing_validation_plan_cannot_publish(): void
    {
        [$admin, $candidate, $proof] = $this->candidate('primary');
        ThemeWorkspace::query()->whereKey($candidate->workspace_id)->update(['plan' => null]);

        try {
            app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof);
            $this->fail('A validated plan is required before release.');
        } catch (ApiException $exception) {
            $this->assertSame('theme_adaptation_validation_required', $exception->getErrorCode());
        }

        $this->assertSame(0, ThemeRelease::query()->count());
    }

    public function test_emergency_stop_keeps_the_existing_theme_and_settings(): void
    {
        [$admin, $candidate, $proof] = $this->candidate('primary');
        SiteSetting::query()->updateOrCreate(['setting_key' => 'link_pool_emergency_disabled'], ['setting_value' => '1']);

        try {
            app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof);
            $this->fail('Emergency stop must block a new release.');
        } catch (ApiException $exception) {
            $this->assertSame('link_pool_emergency_disabled', $exception->getErrorCode());
        }

        $this->assertSame($candidate->parent_id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
        $this->assertFalse($this->primarySettings()['enabled']);
        $this->assertSame(0, ThemeRelease::query()->count());
    }

    public function test_deleting_hosted_channel_cannot_publish(): void
    {
        $profile = $this->hosted('deleting');
        [$admin, $candidate, $proof] = $this->candidate('hosted:'.$profile->id);
        $profile->channel->update(['status' => DistributionChannel::STATUS_DELETING]);

        try {
            app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof);
            $this->fail('A deleting channel must block a new theme release.');
        } catch (ApiException $exception) {
            $this->assertSame('site_deleting', $exception->getErrorCode());
        }

        $this->assertSame($candidate->parent_id, SiteThemeBinding::query()->findOrFail('hosted:'.$profile->id)->revision_id);
        $this->assertFalse($profile->channel->fresh()->site_settings['article_recommendations']['enabled']);
        $this->assertSame(0, ThemeRelease::query()->count());
    }

    public function test_release_rejects_an_extra_executable_theme_file(): void
    {
        [$admin, $candidate, $proof] = $this->candidate('primary');
        $path = 'resources/views/theme/default/article.blade.php';
        $altered = app(ThemeRevisionStorage::class)->create($candidate->workspace_id, 'default', [
            $path => "<article>Article</article>\n    @include('site.partials.link-pool')",
            'public/themes/default/extra.js' => 'alert(1);',
        ], [], $candidate->parent_id);
        $proof['revision_id'] = $altered->id;
        $proof['content_sha256'] = $altered->content_sha256;
        ThemeWorkspace::query()->whereKey($candidate->workspace_id)->update([
            'revision_id' => $altered->id,
            'plan' => [
                'kind' => 'link_pool_adaptation',
                'revision_id' => $altered->id,
                'proof_sha256' => hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR)),
            ],
        ]);

        try {
            app(ThemeReleaseService::class)->publishLinkPoolAdaptation($altered, $admin, $proof);
            $this->fail('The release must reject changes outside the canonical include.');
        } catch (ApiException $exception) {
            $this->assertSame('theme_adaptation_patch_unsafe', $exception->getErrorCode());
        }

        $this->assertSame(0, ThemeRelease::query()->count());
    }

    public function test_release_requires_a_real_outer_commit_before_readback(): void
    {
        [$admin, $candidate, $proof] = $this->candidate('primary');

        try {
            DB::transaction(fn () => app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof));
            $this->fail('The release must not run inside an outer transaction.');
        } catch (ApiException $exception) {
            $this->assertSame('theme_release_transaction_conflict', $exception->getErrorCode());
        }

        $this->assertSame(0, ThemeRelease::query()->count());
        $this->assertFalse($this->primarySettings()['enabled']);
    }

    public function test_rollback_uses_the_persisted_receipt_instead_of_mutable_caller_fields(): void
    {
        [$admin, $candidate, $proof] = $this->candidate('primary');
        $release = app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof);
        $release->previous_revision_id = $candidate->id;
        $release->changes = ['before_enabled' => true, 'after_settings_revision' => 1];

        app(ThemeReleaseService::class)->rollbackLinkPoolAdaptation($release, $admin);

        $this->assertSame($candidate->parent_id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
        $this->assertFalse($this->primarySettings()['enabled']);
    }

    public function test_publish_uses_the_persisted_candidate_instead_of_mutable_caller_fields(): void
    {
        [$admin, $candidate, $proof] = $this->candidate('primary');
        $candidate->workspace_id = (string) Str::uuid();
        $candidate->content_sha256 = str_repeat('0', 64);

        $release = app(ThemeReleaseService::class)->publishLinkPoolAdaptation($candidate, $admin, $proof);

        $this->assertSame($candidate->id, $release->revision_id);
        $this->assertTrue($this->primarySettings()['enabled']);
    }

    /** @return array{Admin,ThemeRevision,array<string,mixed>} */
    private function candidate(string $siteKey): array
    {
        $admin = Admin::query()->create([
            'username' => 'theme-release-'.Str::random(8), 'password' => 'test-password',
            'role' => 'super_admin', 'status' => 'active',
        ]);
        $workspaceId = (string) Str::uuid();
        $source = '<article>Article</article>';
        $path = 'resources/views/theme/default/article.blade.php';
        $storage = app(ThemeRevisionStorage::class);
        $base = $storage->create($workspaceId, 'default', [$path => $source], []);
        $candidate = $storage->create($workspaceId, 'default', [$path => $source."\n    @include('site.partials.link-pool')"], [], $base->id);
        ThemeWorkspace::query()->create([
            'id' => $workspaceId, 'instance_id' => (string) Str::uuid(), 'admin_id' => $admin->id,
            'site_key' => $siteKey, 'theme_id' => 'default', 'source' => 'builtin',
            'revision_id' => $candidate->id, 'state' => 'draft',
        ]);
        SiteThemeBinding::query()->create([
            'site_key' => $siteKey, 'theme_id' => 'default', 'revision_id' => $base->id,
            'settings' => [], 'lock_version' => 0,
        ]);
        $proof = [
            'site_key' => $siteKey, 'revision_id' => $candidate->id,
            'content_sha256' => $candidate->content_sha256, 'base_sha256' => $base->content_sha256,
            'binding_version' => 0,
            'settings_revision' => $siteKey === 'primary' ? 0 : (int) HostedSiteProfile::query()->findOrFail((int) substr($siteKey, 7))->settings_version,
            'article_html_sha256' => str_repeat('a', 64), 'empty_html_sha256' => str_repeat('b', 64),
            'validated_at' => now()->toIso8601String(),
        ];
        ThemeWorkspace::query()->whereKey($workspaceId)->update(['plan' => [
            'kind' => 'link_pool_adaptation',
            'revision_id' => $candidate->id,
            'proof_sha256' => hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR)),
        ]]);

        return [$admin, $candidate, $proof];
    }

    private function hosted(string $name): HostedSiteProfile
    {
        $channel = DistributionChannel::query()->create([
            'name' => $name, 'domain' => $name.'.sites.test', 'endpoint_url' => 'https://'.$name.'.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
            'template_key' => 'default', 'site_settings' => [
                'theme_id' => 'default', 'site_name' => $name,
                'article_recommendations' => ['enabled' => false, 'heading' => '推荐内容'],
            ],
        ]);

        return HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id, 'hostname' => $name.'.sites.test', 'root_domain' => 'sites.test',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE, 'indexing_status' => HostedSiteProfile::INDEXING_INDEX,
            'quality_status' => HostedSiteProfile::QUALITY_PASSED,
        ]);
    }

    private function primarySettings(): array
    {
        return json_decode((string) SiteSetting::query()->where('setting_key', 'article_recommendations')->value('setting_value'), true);
    }
}
