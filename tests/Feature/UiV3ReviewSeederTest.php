<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\DistributionChannel;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\HostedSiteProfile;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\ManualPublication;
use App\Models\SiteThemeReplication;
use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use Database\Seeders\UiV3ReviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UiV3ReviewSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_seeder_is_idempotent_and_covers_parameterized_admin_pages(): void
    {
        Storage::fake('local');

        $this->seed(UiV3ReviewSeeder::class);
        $this->seed(UiV3ReviewSeeder::class);

        $this->assertSame(1, Task::query()->where('name', UiV3ReviewSeeder::TASK_NAME)->count());
        $this->assertSame(1, KeywordLibrary::query()->where('name', UiV3ReviewSeeder::KEYWORD_LIBRARY_NAME)->count());
        $this->assertSame(1, TitleLibrary::query()->where('name', UiV3ReviewSeeder::TITLE_LIBRARY_NAME)->count());
        $this->assertSame(1, ImageLibrary::query()->where('name', UiV3ReviewSeeder::IMAGE_LIBRARY_NAME)->count());
        $this->assertSame(1, KnowledgeBase::query()->where('name', UiV3ReviewSeeder::KNOWLEDGE_BASE_NAME)->count());
        $this->assertSame(1, EnterpriseKnowledgeProject::query()->where('name', UiV3ReviewSeeder::ENTERPRISE_PROJECT_NAME)->count());
        $this->assertSame(1, DistributionChannel::query()->where('name', UiV3ReviewSeeder::CHANNEL_NAME)->count());
        $this->assertSame(1, HostedSiteProfile::query()->where('hostname', UiV3ReviewSeeder::HOSTNAME)->count());
        $this->assertSame(1, LeadForm::query()->where('slug', UiV3ReviewSeeder::LEAD_FORM_SLUG)->count());
        $this->assertSame(1, LeadSubmission::query()->where('source_url', UiV3ReviewSeeder::LEAD_SOURCE_URL)->count());
        $this->assertSame(1, ManualPublication::query()->where('target_url', UiV3ReviewSeeder::PUBLICATION_TARGET_URL)->count());
        $this->assertSame(1, UrlImportJob::query()->where('normalized_url', UiV3ReviewSeeder::IMPORT_URL)->count());
        $this->assertSame(1, SiteThemeReplication::query()->where('theme_id', UiV3ReviewSeeder::THEME_ID)->count());
        $this->assertSame(1, SystemUpdateRun::query()->where('run_uuid', UiV3ReviewSeeder::UPDATE_RUN_UUID)->count());
        $this->assertSame(1, SystemUpdateBackup::query()->where('backup_uuid', UiV3ReviewSeeder::BACKUP_UUID)->count());
        $this->assertSame(1, AiModel::query()->where('name', UiV3ReviewSeeder::AI_MODEL_NAME)->count());
        $this->assertSame(1, AiSourceProvider::query()->where('provider_key', UiV3ReviewSeeder::AI_SOURCE_PROVIDER_KEY)->count());

        $model = AiModel::query()->where('name', UiV3ReviewSeeder::AI_MODEL_NAME)->firstOrFail();
        $provider = AiSourceProvider::query()->where('provider_key', UiV3ReviewSeeder::AI_SOURCE_PROVIDER_KEY)->firstOrFail();

        $this->assertSame('inactive', $model->status);
        $this->assertSame('inactive', $provider->status);
        $this->assertStringNotContainsString('secret', (string) $model->getRawOriginal('api_key'));
        $this->assertStringNotContainsString('secret', (string) $provider->getRawOriginal('api_key'));
    }
}
