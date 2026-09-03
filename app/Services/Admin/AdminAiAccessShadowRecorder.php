<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AdminAiAccessShadowEvent;
use App\Models\AiModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class AdminAiAccessShadowRecorder
{
    /** @param Collection<int,AiModel> $safeCandidates */
    public function record(Admin $admin, ?string $capability, Collection $safeCandidates): void
    {
        if (! (bool) config('geoflow.admin_ai_access.shadow_enabled', true)
            || ! Schema::hasTable('admin_ai_access_shadow_events')) {
            return;
        }

        $normalizedCapability = $this->normalizeCapability($capability);

        try {
            $legacyQuery = $this->compatibleLegacyModels($normalizedCapability);
            $legacyPreferredId = (clone $legacyQuery)->orderBy('id')->value('id');
            $safePreferred = $safeCandidates->first();
            $safePreferredId = $safePreferred instanceof AiModel
                ? (int) $safePreferred->getKey()
                : null;
            $visibleOwnerIds = [(int) $admin->getKey()];
            if (! $admin->isSuperAdmin() && $admin->shared_ai_config_owner_id !== null) {
                $visibleOwnerIds[] = (int) $admin->shared_ai_config_owner_id;
            }

            AdminAiAccessShadowEvent::unguarded(fn (): AdminAiAccessShadowEvent => AdminAiAccessShadowEvent::query()->create([
                'event_uuid' => (string) Str::uuid(),
                'execution_admin_id' => (int) $admin->getKey(),
                'execution_admin_role' => $admin->isSuperAdmin() ? 'super_admin' : 'admin',
                'ai_config_access_version' => max(1, (int) $admin->ai_config_access_version),
                'capability' => $normalizedCapability,
                'legacy_preferred_model_id' => $legacyPreferredId === null ? null : (int) $legacyPreferredId,
                'safe_preferred_model_id' => $safePreferredId,
                'safe_model_source' => $safePreferred instanceof AiModel
                    ? ((int) $safePreferred->owner_admin_id === (int) $admin->getKey()
                        ? AdminAiAccessShadowEvent::MODEL_SOURCE_PERSONAL
                        : AdminAiAccessShadowEvent::MODEL_SOURCE_SHARED)
                    : null,
                'comparison' => $this->comparison(
                    $legacyPreferredId === null ? null : (int) $legacyPreferredId,
                    $safePreferredId,
                ),
                'inaccessible_legacy_model_count' => (clone $legacyQuery)
                    ->where(function (Builder $query) use ($visibleOwnerIds): void {
                        $query
                            ->whereNotIn('owner_admin_id', $visibleOwnerIds)
                            ->orWhereNull('owner_admin_id')
                            ->orWhere('access_scope', '!=', AiModel::ACCESS_SCOPE_USER_CONTENT);
                    })
                    ->count(),
                'missing_owner_model_count' => (clone $legacyQuery)
                    ->whereNull('owner_admin_id')
                    ->count(),
                'created_at' => now(),
            ]));
        } catch (Throwable $exception) {
            Log::warning('Administrator AI access shadow event could not be recorded.', [
                'execution_admin_id' => (int) $admin->getKey(),
                'capability' => $normalizedCapability,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function compatibleLegacyModels(string $capability): Builder
    {
        return AiModel::query()
            ->active()
            ->unarchived()
            ->when($capability === 'chat', static fn (Builder $query): Builder => $query->where(
                static function (Builder $chat): void {
                    $chat->whereNull('model_type')
                        ->orWhere('model_type', '')
                        ->orWhere('model_type', 'chat');
                },
            ))
            ->when($capability !== 'chat', static fn (Builder $query): Builder => $query->where('model_type', $capability));
    }

    private function normalizeCapability(?string $capability): string
    {
        $normalized = strtolower(trim((string) $capability));

        return in_array($normalized, ['chat', 'embedding', 'image'], true)
            ? $normalized
            : 'chat';
    }

    private function comparison(?int $legacyPreferredId, ?int $safePreferredId): string
    {
        return match (true) {
            $legacyPreferredId === null && $safePreferredId === null => AdminAiAccessShadowEvent::COMPARISON_BOTH_MISSING,
            $legacyPreferredId === null => AdminAiAccessShadowEvent::COMPARISON_LEGACY_MISSING,
            $safePreferredId === null => AdminAiAccessShadowEvent::COMPARISON_SAFE_MISSING,
            $legacyPreferredId === $safePreferredId => AdminAiAccessShadowEvent::COMPARISON_MATCHED,
            default => AdminAiAccessShadowEvent::COMPARISON_DIFFERENT,
        };
    }
}
