<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Services\GeoFlow\AiUsageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_a_model_reservation_can_only_be_finalized_once(): void
    {
        $model = $this->createModel();
        $model->update(['daily_limit' => 2]);
        $quota = app(AiUsageQuotaService::class);

        $releasedReservation = $quota->reserveModel($model);
        $successfulReservation = $quota->reserveModel($model);
        $this->assertNotNull($releasedReservation);
        $this->assertNotNull($successfulReservation);
        $this->assertSame(2, (int) $model->fresh()->used_today);

        $quota->releaseModel($releasedReservation);
        $quota->releaseModel($releasedReservation);
        $quota->recordModelSuccess($releasedReservation);

        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);

        $quota->recordModelSuccess($successfulReservation);
        $quota->recordModelSuccess($successfulReservation);
        $quota->releaseModel($successfulReservation);

        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
    }

    public function test_a_rolled_back_success_can_still_release_the_reserved_usage(): void
    {
        $model = $this->createModel();
        $quota = app(AiUsageQuotaService::class);
        $reservation = $quota->reserveModel($model);
        $this->assertNotNull($reservation);

        try {
            DB::transaction(function () use ($quota, $reservation): void {
                $quota->recordModelSuccess($reservation);

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $quota->releaseModel($reservation);

        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
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
