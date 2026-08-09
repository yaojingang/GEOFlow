<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\AiSourceProvider;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AiUsageQuotaService
{
    public function reserveModel(AiModel $model): ?AiUsageReservation
    {
        return DB::transaction(function () use ($model): ?AiUsageReservation {
            $locked = AiModel::query()->lockForUpdate()->find((int) $model->id);
            if (! $locked instanceof AiModel || (string) ($locked->status ?? 'inactive') !== 'active') {
                return null;
            }

            return $this->reserveLockedModel($locked);
        });
    }

    /**
     * 连接诊断允许测试停用模型，同时继续执行每日额度和用量审计。
     */
    public function reserveModelForTest(AiModel $model): ?AiUsageReservation
    {
        return DB::transaction(function () use ($model): ?AiUsageReservation {
            $locked = AiModel::query()->lockForUpdate()->find((int) $model->id);
            if (! $locked instanceof AiModel) {
                return null;
            }

            return $this->reserveLockedModel($locked);
        });
    }

    public function releaseModel(AiUsageReservation $reservation): void
    {
        if ($reservation->resourceType !== 'model') {
            throw new \InvalidArgumentException('Expected an AI model usage reservation.');
        }
        $this->finalizeReservation($reservation, static function () use ($reservation): void {
            AiModel::query()
                ->whereKey($reservation->resourceId)
                ->whereDate('usage_date', $reservation->usageDate)
                ->where('used_today', '>', 0)
                ->update([
                    'used_today' => DB::raw('COALESCE(used_today, 0) - 1'),
                    'updated_at' => now(),
                ]);
        });
    }

    public function recordModelSuccess(AiUsageReservation $reservation): void
    {
        if ($reservation->resourceType !== 'model') {
            throw new \InvalidArgumentException('Expected an AI model usage reservation.');
        }
        $this->finalizeReservation($reservation, static function () use ($reservation): void {
            AiModel::query()->whereKey($reservation->resourceId)->update([
                'total_used' => DB::raw('COALESCE(total_used, 0) + 1'),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * 完成一次模型诊断预占，但不把失败诊断计入成功调用总数。
     */
    public function recordModelAttempt(AiUsageReservation $reservation): void
    {
        if ($reservation->resourceType !== 'model') {
            throw new \InvalidArgumentException('Expected an AI model usage reservation.');
        }

        $this->finalizeReservation($reservation, static function (): void {});
    }

    public function reserveProvider(AiSourceProvider $provider): ?AiUsageReservation
    {
        return DB::transaction(function () use ($provider): ?AiUsageReservation {
            $locked = AiSourceProvider::query()->lockForUpdate()->find((int) $provider->id);
            if (! $locked instanceof AiSourceProvider || (string) $locked->status !== 'active') {
                return null;
            }

            return $this->reserveLockedProvider($locked);
        });
    }

    public function releaseProvider(AiUsageReservation $reservation): void
    {
        if ($reservation->resourceType !== 'provider') {
            throw new \InvalidArgumentException('Expected an AI source provider usage reservation.');
        }
        $this->finalizeReservation($reservation, static function () use ($reservation): void {
            AiSourceProvider::query()
                ->whereKey($reservation->resourceId)
                ->whereDate('usage_date', $reservation->usageDate)
                ->where('used_today', '>', 0)
                ->update([
                    'used_today' => DB::raw('COALESCE(used_today, 0) - 1'),
                    'updated_at' => now(),
                ]);
        });
    }

    public function recordProviderSuccess(AiUsageReservation $reservation): void
    {
        if ($reservation->resourceType !== 'provider') {
            throw new \InvalidArgumentException('Expected an AI source provider usage reservation.');
        }
        $this->finalizeReservation($reservation, static function () use ($reservation): void {
            AiSourceProvider::query()->whereKey($reservation->resourceId)->update([
                'total_used' => DB::raw('COALESCE(total_used, 0) + 1'),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * 完成一次信源诊断预占，但不把失败诊断计入成功调用总数。
     */
    public function recordProviderAttempt(AiUsageReservation $reservation): void
    {
        if ($reservation->resourceType !== 'provider') {
            throw new \InvalidArgumentException('Expected an AI source provider usage reservation.');
        }

        $this->finalizeReservation($reservation, static function (): void {});
    }

    private function reserveLockedModel(AiModel $model): ?AiUsageReservation
    {
        $usageDate = now()->toDateString();
        $usedToday = $this->usedOnDate($model->usage_date, (int) ($model->used_today ?? 0), $usageDate);
        $dailyLimit = (int) ($model->daily_limit ?? 0);
        if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
            return null;
        }

        $model->forceFill([
            'usage_date' => $usageDate,
            'used_today' => $usedToday + 1,
        ])->save();

        return new AiUsageReservation('model', (int) $model->id, $usageDate);
    }

    private function reserveLockedProvider(AiSourceProvider $provider): ?AiUsageReservation
    {
        $usageDate = now()->toDateString();
        $usedToday = $this->usedOnDate($provider->usage_date, (int) ($provider->used_today ?? 0), $usageDate);
        $dailyLimit = (int) ($provider->daily_limit ?? 0);
        if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
            return null;
        }

        $provider->forceFill([
            'usage_date' => $usageDate,
            'used_today' => $usedToday + 1,
        ])->save();

        return new AiUsageReservation('provider', (int) $provider->id, $usageDate);
    }

    private function usedOnDate(mixed $storedUsageDate, int $usedToday, string $usageDate): int
    {
        $date = $storedUsageDate instanceof \DateTimeInterface
            ? $storedUsageDate->format('Y-m-d')
            : trim((string) $storedUsageDate);

        return $date === $usageDate ? max(0, $usedToday) : 0;
    }

    private function finalizeReservation(AiUsageReservation $reservation, Closure $operation): void
    {
        if (! $reservation->claimFinalization()) {
            return;
        }

        try {
            $operation();
        } catch (Throwable $exception) {
            $reservation->cancelFinalization();

            throw $exception;
        }

        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) {
            $reservation->completeFinalization();

            return;
        }

        $connection->afterCommit(
            static fn () => $reservation->completeFinalization()
        );
        $connection->afterRollBack(
            static fn () => $reservation->cancelFinalization()
        );
    }
}
