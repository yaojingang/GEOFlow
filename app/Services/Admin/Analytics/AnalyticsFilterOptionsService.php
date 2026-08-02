<?php

namespace App\Services\Admin\Analytics;

use App\Models\Article;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Task;
use Illuminate\Support\Facades\Schema;

class AnalyticsFilterOptionsService
{
    /**
     * @return array<string, mixed>
     */
    public function get(bool $includeChannels = false, ?AnalyticsFilter $filter = null): array
    {
        return [
            'channels' => $includeChannels && Schema::hasTable('distribution_channels')
                ? DistributionChannel::query()->orderBy('name')->select('id', 'name')->get()
                : collect(),
            'tasks' => Schema::hasTable('tasks')
                ? Task::query()
                    ->when($filter?->taskId !== null, fn ($query) => $query->orderByRaw('CASE WHEN tasks.id = ? THEN 0 ELSE 1 END', [$filter->taskId]))
                    ->orderByDesc('created_at')
                    ->select('id', 'name')
                    ->limit(100)
                    ->get()
                : collect(),
            'categories' => Schema::hasTable('categories') ? Category::query()
                ->orderBy('name')
                ->select('id', 'name')
                ->get() : collect(),
            'articles' => Schema::hasTable('articles')
                ? Article::query()
                    ->whereNull('deleted_at')
                    ->when($filter?->articleId !== null, fn ($query) => $query->orderByRaw('CASE WHEN articles.id = ? THEN 0 ELSE 1 END', [$filter->articleId]))
                    ->orderByDesc('created_at')
                    ->select('id', 'title')
                    ->limit(100)
                    ->get()
                : collect(),
        ];
    }
}
