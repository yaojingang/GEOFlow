<?php

namespace App\Services\Api;

use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Support\Site\SiteThemePackageStorage;
use Illuminate\Support\Facades\DB;

final class ThemeWorkspaceRetention
{
    public function __construct(private SiteThemePackageStorage $storage) {}

    /** Retain all release history and a 15 minute grace period for in-flight preview requests. */
    public function collect(string $workspaceId): array
    {
        return $this->storage->lock('workspace-'.$workspaceId, fn (): array => $this->storage->lock('managed-theme-storage', function () use ($workspaceId): array {
            $removed = 0;
            $bytes = 0;
            $quotaBytes = 0;
            $workspace = ThemeWorkspace::query()->findOrFail($workspaceId);
            if ($workspace->updated_at->greaterThanOrEqualTo(now()->subMinutes(15))) {
                return ['revisions_removed' => 0, 'bytes_reclaimed' => 0, 'quota_bytes_released' => 0, 'grace_until' => $workspace->updated_at->copy()->addMinutes(15)->toIso8601String()];
            }
            $current = $workspace->state === 'draft' ? ThemeRevision::query()->find($workspace->revision_id) : null;
            $protected = array_filter([$current?->id, $current?->parent_id]);
            foreach (ThemeRevision::query()->where('workspace_id', $workspaceId)->where('created_at', '<', now()->subMinutes(15))->get() as $revision) {
                if (in_array($revision->id, $protected, true) || $workspace->plan !== null) {
                    continue;
                }
                $deletable = DB::transaction(function () use ($revision): bool {
                    $row = ThemeRevision::query()->whereKey($revision->id)->lockForUpdate()->first();
                    if (! $row || SiteThemeBinding::query()->where('revision_id', $row->id)->exists()
                        || ThemeRelease::query()->where('revision_id', $row->id)->orWhere('previous_revision_id', $row->id)->exists()
                        || ThemeWorkspace::query()->where('state', 'draft')->where('revision_id', $row->id)->exists()
                        || ThemeRevision::query()->where('parent_id', $row->id)->whereIn('id', ThemeWorkspace::query()->where('state', 'draft')->select('revision_id'))->exists()) {
                        return false;
                    }
                    $row->update(['state' => 'deleting']);

                    return true;
                });
                if (! $deletable) {
                    continue;
                }
                $bytes += $this->storage->deleteRevision($revision->id);
                $quotaBytes += $revision->total_bytes;
                $revision->delete();
                $removed++;
            }

            return ['revisions_removed' => $removed, 'bytes_reclaimed' => $bytes, 'quota_bytes_released' => $quotaBytes];
        }));
    }
}
