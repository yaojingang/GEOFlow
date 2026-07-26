<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Services\GeoFlow\AiUsageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiUsageQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_releasing_a_model_reservation_after_midnight_does_not_decrement_the_new_day(): void
    {
        $this->travelTo('2026-07-26 23:59:00');
        $model = $this->createModel();
        $quota = app(AiUsageQuotaService::class);

        $oldReservation = $quota->reserveModel($model);
        $this->assertNotNull($oldReservation);

        $this->travelTo('2026-07-27 00:01:00');
        $newReservation = $quota->reserveModel($model);
        $this->assertNotNull($newReservation);
        $this->assertSame('2026-07-27', $model->fresh()->usage_date?->toDateString());
        $this->assertSame(1, (int) $model->fresh()->used_today);

        $quota->releaseModel($oldReservation);

        $this->assertSame('2026-07-27', $model->fresh()->usage_date?->toDateString());
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $quota->recordModelSuccess($newReservation);
        $this->assertSame(1, (int) $model->fresh()->total_used);
    }

    public function test_releasing_a_provider_reservation_after_midnight_does_not_decrement_the_new_day(): void
    {
        $this->travelTo('2026-07-26 23:59:00');
        $provider = AiSourceProvider::query()->create([
            'name' => 'Search Provider',
            'provider_key' => AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'endpoint_url' => 'https://search.test',
            'api_key' => 'encrypted-key',
            'daily_limit' => 1,
            'status' => 'active',
        ]);
        $quota = app(AiUsageQuotaService::class);

        $oldReservation = $quota->reserveProvider($provider);
        $this->assertNotNull($oldReservation);

        $this->travelTo('2026-07-27 00:01:00');
        $newReservation = $quota->reserveProvider($provider);
        $this->assertNotNull($newReservation);
        $this->assertSame('2026-07-27', $provider->fresh()->usage_date?->toDateString());
        $this->assertSame(1, (int) $provider->fresh()->used_today);

        $quota->releaseProvider($oldReservation);

        $this->assertSame('2026-07-27', $provider->fresh()->usage_date?->toDateString());
        $this->assertSame(1, (int) $provider->fresh()->used_today);
        $quota->recordProviderSuccess($newReservation);
        $this->assertSame(1, (int) $provider->fresh()->total_used);
    }

    private function createModel(): AiModel
    {
        return AiModel::query()->create([
            'name' => 'Quota Model',
            'version' => 'test',
            'api_key' => 'encrypted-key',
            'model_id' => 'quota-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'daily_limit' => 1,
            'status' => 'active',
        ]);
    }
}
