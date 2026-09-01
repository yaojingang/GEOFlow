<?php

namespace App\Services\Admin;

use App\Contracts\Admin\AiModelWriteLock;
use App\Models\AiModel;
use Illuminate\Database\Eloquent\Collection;

final class DatabaseAiModelWriteLock implements AiModelWriteLock
{
    public function lockByIds(array $modelIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $modelIds)));
        sort($ids);

        return AiModel::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
