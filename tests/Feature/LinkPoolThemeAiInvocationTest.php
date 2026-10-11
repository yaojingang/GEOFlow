<?php

namespace Tests\Feature;

use App\Ai\Agents\LinkPoolThemeAnchorAgent;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\LinkPool\LinkPoolThemePatchPlanner;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LinkPoolThemeAiInvocationTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = "@section('content')\n@if(\$relatedArticles->isNotEmpty())\n<section><h2>Related</h2></section>\n@endif\n@endsection";

    private const ANCHOR = "</section>\n@endif";

    private const PATH = 'resources/views/theme/ai-invocation-fixture/article.blade.php';

    public function test_an_exhausted_selected_model_blocks_the_provider_without_using_another_model(): void
    {
        $this->freezeTime();
        [$admin, $model] = $this->adminAndModel();
        $model->forceFill(['daily_limit' => 1, 'used_today' => 1, 'usage_date' => now()->toDateString()])->save();
        $this->chatModel($admin, 'available-fallback');
        LinkPoolThemeAnchorAgent::fake([['anchor' => self::ANCHOR, 'reason' => 'Related section end']])->preventStrayPrompts();

        try {
            $this->patchTheme($admin);
            $this->fail('The exhausted selected model must block adaptation.');
        } catch (ApiException $exception) {
            $this->assertSame('theme_ai_model_unavailable', $exception->getErrorCode());
        }

        LinkPoolThemeAnchorAgent::assertNeverPrompted();
        $this->assertSame(1, $model->fresh()->used_today);
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        $this->assertMutationLockAvailable($model);
    }

    public function test_a_successful_anchor_uses_the_selected_model_and_records_usage(): void
    {
        $this->freezeTime();
        [$admin, $model] = $this->adminAndModel();
        $otherModel = $this->chatModel($admin, 'available-fallback');
        LinkPoolThemeAnchorAgent::fake([['anchor' => self::ANCHOR, 'reason' => 'Related section end']])->preventStrayPrompts();

        $result = $this->patchTheme($admin);

        $this->assertSame($model->id, $result['model_id']);
        $this->assertStringContainsString("@include('site.partials.link-pool')", $result['files'][self::PATH]);
        LinkPoolThemeAnchorAgent::assertPrompted(fn ($prompt) => $prompt->model === 'selected-chat');
        $this->assertSame(1, $model->fresh()->used_today);
        $this->assertSame(1, $model->fresh()->total_used);
        $this->assertSame(0, $otherModel->fresh()->used_today);
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame($model->id, $event->ai_model_id);
        $this->assertSame($admin->id, $event->execution_admin_id);
        $this->assertSame(AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN, $event->execution_scope);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_PERSONAL, $event->model_source);
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $event->status);
        $this->assertMutationLockAvailable($model);
    }

    public function test_provider_failure_records_a_safe_error_and_releases_the_reservation_and_lock(): void
    {
        $this->freezeTime();
        [$admin, $model] = $this->adminAndModel();
        $reservedUsage = null;
        $lockedDuringPrompt = false;
        LinkPoolThemeAnchorAgent::fake(function () use ($model, &$reservedUsage, &$lockedDuringPrompt): never {
            $reservedUsage = $model->fresh()->used_today;
            $locks = app(AiModelInvocationLock::class);
            $mutation = $locks->acquireForMutation($model->id);
            $lockedDuringPrompt = $mutation === null;
            $locks->release($mutation);

            throw new RuntimeException('Provider failed with test-key and private payload');
        })->preventStrayPrompts();

        try {
            $this->patchTheme($admin);
            $this->fail('Provider failure must stop adaptation.');
        } catch (ApiException $exception) {
            $this->assertSame('theme_ai_anchor_failed', $exception->getErrorCode());
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
            $this->assertStringNotContainsString('private payload', $exception->getMessage());
        }

        $this->assertSame(1, $reservedUsage);
        $this->assertTrue($lockedDuringPrompt);
        $this->assertSame(0, $model->fresh()->used_today);
        $this->assertSame(0, $model->fresh()->total_used);
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_FAILED, $event->status);
        $this->assertSame('theme_ai_anchor_failed', $event->error_code);
        $this->assertMutationLockAvailable($model);
    }

    private function patchTheme(Admin $admin): array
    {
        return app(LinkPoolThemePatchPlanner::class)->patch([self::PATH => self::TEMPLATE], 'ai-invocation-fixture', $admin);
    }

    private function adminAndModel(): array
    {
        $admin = Admin::query()->create(['username' => 'theme-ai-admin', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
        $model = $this->chatModel($admin, 'selected-chat');
        (new AdminAiSetting)->forceFill(['admin_id' => $admin->id, 'default_chat_model_id' => $model->id])->save();

        return [$admin, $model];
    }

    private function chatModel(Admin $admin, string $modelId): AiModel
    {
        $model = AiModel::query()->create([
            'name' => $modelId, 'version' => 'test', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => $modelId, 'model_type' => 'chat', 'api_url' => 'https://ai.test', 'status' => 'active',
            'daily_limit' => 0, 'used_today' => 0, 'total_used' => 0,
        ]);
        $model->forceFill(['owner_admin_id' => $admin->id])->save();

        return $model;
    }

    private function assertMutationLockAvailable(AiModel $model): void
    {
        $locks = app(AiModelInvocationLock::class);
        $mutation = $locks->acquireForMutation($model->id);
        try {
            $this->assertNotNull($mutation);
        } finally {
            $locks->release($mutation);
        }
    }
}
