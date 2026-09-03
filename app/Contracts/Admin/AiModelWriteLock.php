<?php

namespace App\Contracts\Admin;

use App\Models\AiModel;
use Illuminate\Database\Eloquent\Collection;

interface AiModelWriteLock
{
    /**
     * Lock model rows in ascending primary-key order.
     *
     * @param  list<int>  $modelIds
     * @return Collection<int, AiModel>
     */
    public function lockByIds(array $modelIds): Collection;
}
