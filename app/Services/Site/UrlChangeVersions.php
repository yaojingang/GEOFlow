<?php

namespace App\Services\Site;

use Illuminate\Support\Facades\DB;

final class UrlChangeVersions
{
    /** @param list<string> $scopes @return array<string,int> */
    public function snapshot(array $scopes, bool $lock = false): array
    {
        sort($scopes, SORT_STRING);
        $existing = DB::table('url_change_scope_states')->whereIn('scope_key', $scopes)->pluck('scope_key')->all();
        foreach (array_diff(array_unique($scopes), $existing) as $scope) {
            DB::table('url_change_scope_states')->insertOrIgnore(['scope_key' => $scope]);
        }
        $query = DB::table('url_change_scope_states')->whereIn('scope_key', $scopes)->orderBy('scope_key');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->pluck('data_revision', 'scope_key')->map(fn ($value): int => (int) $value)->all();
    }

    /** @param list<string> $scopes */
    public function advance(array $scopes): void
    {
        $this->snapshot($scopes, true);
        DB::table('url_change_scope_states')->whereIn('scope_key', $scopes)->update([
            'data_revision' => DB::raw('data_revision + 1'), 'url_changed_at' => now(),
        ]);
    }
}
