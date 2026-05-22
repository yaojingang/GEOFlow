<section class="mb-8">
    <div class="mb-5">
        <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.analytics.content_title') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.content_desc') }}</p>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">{{ __('admin.analytics.publication_trend') }}</h3>
            @include('admin.analytics._line-chart', ['series' => $publicationTrend, 'primaryKey' => 'created', 'secondaryKey' => 'published'])
            <div class="mt-4 flex gap-4 text-xs text-gray-500">
                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-blue-600"></span>{{ __('admin.analytics.created_articles') }}</span>
                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-emerald-600"></span>{{ __('admin.analytics.published_articles') }}</span>
            </div>
        </div>

        <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">{{ __('admin.analytics.task_trend') }}</h3>
            @include('admin.analytics._bar-chart', ['series' => $taskTrend])
            <div class="mt-4 grid grid-cols-2 gap-2 text-xs text-gray-500 sm:grid-cols-4">
                <span>{{ __('admin.analytics.completed') }}</span>
                <span>{{ __('admin.analytics.failed') }}</span>
                <span>{{ __('admin.analytics.running') }}</span>
                <span>{{ __('admin.analytics.pending') }}</span>
            </div>
        </div>

        <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">{{ __('admin.analytics.content_funnel') }}</h3>
            @include('admin.analytics._funnel', ['funnel' => $contentFunnel])
        </div>

        <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">{{ __('admin.analytics.ai_usage') }}</h3>
            <div class="grid grid-cols-3 gap-3 text-center">
                <div class="rounded-lg bg-indigo-50 px-3 py-4">
                    <div class="text-2xl font-bold text-indigo-700">{{ number_format((int) $aiUsageSummary['used_today']) }}</div>
                    <div class="mt-1 text-xs text-indigo-700">{{ __('admin.analytics.used_today') }}</div>
                </div>
                <div class="rounded-lg bg-slate-50 px-3 py-4">
                    <div class="text-2xl font-bold text-slate-800">{{ number_format((int) $aiUsageSummary['total_used']) }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ __('admin.analytics.total_used') }}</div>
                </div>
                <div class="rounded-lg bg-blue-50 px-3 py-4">
                    <div class="text-2xl font-bold text-blue-700">{{ number_format((int) $aiUsageSummary['active_models']) }}</div>
                    <div class="mt-1 text-xs text-blue-700">{{ __('admin.analytics.model') }}</div>
                </div>
            </div>
            <div class="mt-5 divide-y divide-gray-100">
                @forelse ($aiUsageSummary['model_rows'] as $model)
                    <div class="flex items-center justify-between gap-3 py-3 text-sm">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-gray-900">{{ $model->name }}</div>
                            <div class="truncate text-xs text-gray-500">{{ $model->model_id }}</div>
                        </div>
                        <div class="whitespace-nowrap text-gray-600">{{ number_format((int) $model->used_today) }} / {{ number_format((int) $model->total_used) }}</div>
                    </div>
                @empty
                    <div class="rounded-lg bg-gray-50 px-4 py-5 text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-100 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.dashboard.category_distribution') }}</h3>
                    <a href="{{ route('admin.categories.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">{{ __('admin.dashboard.manage_categories') }}</a>
                </div>
            </div>
            <div class="p-6">
                @forelse ($categoryDistribution as $category)
                    @php
                        $categoryPercent = (($kpis['articles'] ?? 0) > 0) ? min(100, round(((int) $category['count'] / max(1, (int) ($kpis['articles'] ?? 1))) * 100)) : 0;
                    @endphp
                    <div class="mb-4 last:mb-0">
                        <div class="mb-1 flex items-center justify-between gap-3">
                            <span class="truncate text-sm font-medium text-gray-900">{{ $category['name'] }}</span>
                            <span class="shrink-0 text-sm text-gray-500">{{ number_format((int) $category['count']) }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-gray-100">
                            <div class="h-full rounded-full bg-blue-600" style="width: {{ $categoryPercent }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg bg-gray-50 px-4 py-5 text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-100 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.dashboard.system_performance') }}</h3>
            </div>
            <div class="space-y-5 p-6">
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-gray-700">{{ __('admin.dashboard.task_success_rate') }}</span>
                        <span class="text-sm text-gray-900">{{ number_format($performanceStats['success_rate'] ?? 0, 1) }}%</span>
                    </div>
                    <div class="h-2 rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-emerald-600" style="width: {{ min($performanceStats['success_rate'] ?? 0, 100) }}%"></div>
                    </div>
                </div>
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-gray-700">{{ __('admin.dashboard.avg_generation_time') }}</span>
                        <span class="text-sm text-gray-900">{{ number_format($performanceStats['avg_generation_time'] ?? 0, 1) }}s</span>
                    </div>
                    <div class="h-2 rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-amber-500" style="width: {{ min((($performanceStats['avg_generation_time'] ?? 0) / 60) * 100, 100) }}%"></div>
                    </div>
                </div>
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-gray-700">{{ __('admin.dashboard.daily_ai_quota') }}</span>
                        <span class="text-sm text-gray-900">{{ number_format((int) ($performanceStats['daily_quota_used'] ?? 0)) }}</span>
                    </div>
                    <div class="h-2 rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-purple-600" style="width: {{ min((($performanceStats['daily_quota_used'] ?? 0) / 100) * 100, 100) }}%"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-100 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.dashboard.latest_articles') }}</h3>
                    <a href="{{ route('admin.articles.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">{{ __('admin.dashboard.view_all') }}</a>
                </div>
            </div>
            <div class="divide-y divide-gray-100 px-6">
                @forelse ($latestArticles as $article)
                    <div class="flex items-start gap-3 py-4">
                        <div class="mt-0.5 shrink-0">
                            @if (!empty($article->is_ai_generated))
                                <i data-lucide="brain" class="h-4 w-4 text-purple-500"></i>
                            @else
                                <i data-lucide="edit" class="h-4 w-4 text-gray-400"></i>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium text-gray-900">{{ $article->title }}</div>
                            <div class="mt-1 text-xs text-gray-500">
                                {{ $article->category_name ?? __('admin.dashboard.uncategorized') }} ·
                                {{ $article->created_at ? \Illuminate\Support\Carbon::parse($article->created_at)->format('m-d H:i') : '' }}
                            </div>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-1 text-xs font-medium {{ ($article->status ?? '') === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                            {{ ($article->status ?? '') === 'published' ? __('admin.articles.status.published') : __('admin.articles.status.draft') }}
                        </span>
                    </div>
                @empty
                    <div class="py-6 text-sm text-gray-500">{{ __('admin.dashboard.no_articles') }}</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2" data-analytics-health-grid>
        <section class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-100 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.dashboard.task_health') }}</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-lg bg-blue-50 p-4">
                        <div class="text-2xl font-bold text-blue-700">{{ $taskHealth['active_tasks'] ?? 0 }}</div>
                        <div class="mt-1 text-xs font-medium text-blue-700">{{ __('admin.dashboard.task_active') }}</div>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-4">
                        <div class="text-2xl font-bold text-slate-700">{{ $taskHealth['paused_tasks'] ?? 0 }}</div>
                        <div class="mt-1 text-xs font-medium text-slate-600">{{ __('admin.dashboard.task_paused') }}</div>
                    </div>
                    <div class="rounded-lg bg-emerald-50 p-4">
                        <div class="text-2xl font-bold text-emerald-700">{{ $taskHealth['running_jobs'] ?? 0 }}</div>
                        <div class="mt-1 text-xs font-medium text-emerald-700">{{ __('admin.dashboard.task_running') }}</div>
                    </div>
                    <div class="rounded-lg bg-amber-50 p-4">
                        <div class="text-2xl font-bold text-amber-700">{{ $taskHealth['pending_jobs'] ?? 0 }}</div>
                        <div class="mt-1 text-xs font-medium text-amber-700">{{ __('admin.dashboard.task_pending') }}</div>
                    </div>
                </div>
                <div class="mt-5">
                    <div class="mb-2 text-sm font-semibold text-gray-900">{{ __('admin.dashboard.recent_failures') }}</div>
                    @forelse (($taskHealth['recent_failures'] ?? []) as $failure)
                        <div class="mb-2 rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm last:mb-0">
                            <div class="font-medium text-red-700">{{ $failure->task_name ?? __('admin.dashboard.unknown_task') }}</div>
                            <div class="mt-1 line-clamp-2 text-xs text-red-600">{{ $failure->error_message }}</div>
                        </div>
                    @empty
                        <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500">{{ __('admin.dashboard.no_failures') }}</p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-100 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.dashboard.material_health') }}</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <a href="{{ route('admin.keyword-libraries.index') }}" class="rounded-lg border border-gray-100 p-4 hover:bg-gray-50">
                        <div class="text-xl font-bold text-gray-900">{{ $materialHealth['keyword_libraries'] ?? 0 }}</div>
                        <div class="mt-1 text-gray-500">{{ __('admin.dashboard.material_keywords') }}</div>
                    </a>
                    <a href="{{ route('admin.title-libraries.index') }}" class="rounded-lg border border-gray-100 p-4 hover:bg-gray-50">
                        <div class="text-xl font-bold text-gray-900">{{ $materialHealth['title_libraries'] ?? 0 }}</div>
                        <div class="mt-1 text-gray-500">{{ __('admin.dashboard.material_titles') }}</div>
                    </a>
                    <a href="{{ route('admin.knowledge-bases.index') }}" class="rounded-lg border border-gray-100 p-4 hover:bg-gray-50">
                        <div class="text-xl font-bold text-gray-900">{{ $materialHealth['knowledge_bases'] ?? 0 }}</div>
                        <div class="mt-1 text-gray-500">{{ __('admin.dashboard.material_knowledge') }}</div>
                    </a>
                    <a href="{{ route('admin.authors.index') }}" class="rounded-lg border border-gray-100 p-4 hover:bg-gray-50">
                        <div class="text-xl font-bold text-gray-900">{{ $materialHealth['authors'] ?? 0 }}</div>
                        <div class="mt-1 text-gray-500">{{ __('admin.dashboard.material_authors') }}</div>
                    </a>
                </div>
                @php
                    $chunkTotal = max(1, (int) ($materialHealth['knowledge_chunks'] ?? 0));
                    $vectorPercent = min(100, round(((int) ($materialHealth['vectorized_chunks'] ?? 0) / $chunkTotal) * 100));
                @endphp
                <div class="mt-5 rounded-lg bg-slate-50 p-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700">{{ __('admin.dashboard.material_vectorized') }}</span>
                        <span class="text-gray-500">{{ number_format($materialHealth['vectorized_chunks'] ?? 0) }} / {{ number_format($materialHealth['knowledge_chunks'] ?? 0) }}</span>
                    </div>
                    <div class="mt-3 h-2 rounded-full bg-white">
                        <div class="h-full rounded-full bg-emerald-600" style="width: {{ $vectorPercent }}%"></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-100 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.dashboard.ai_health') }}</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-lg bg-indigo-50 p-4">
                        <div class="text-2xl font-bold text-indigo-700">{{ $aiHealth['chat_models'] ?? 0 }}</div>
                        <div class="mt-1 text-xs font-medium text-indigo-700">{{ __('admin.dashboard.ai_chat_models') }}</div>
                    </div>
                    <div class="rounded-lg bg-purple-50 p-4">
                        <div class="text-2xl font-bold text-purple-700">{{ $aiHealth['embedding_models'] ?? 0 }}</div>
                        <div class="mt-1 text-xs font-medium text-purple-700">{{ __('admin.dashboard.ai_embedding_models') }}</div>
                    </div>
                </div>
                <div class="mt-5 space-y-3 text-sm">
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3">
                        <span class="text-gray-500">{{ __('admin.dashboard.ai_used_today') }}</span>
                        <span class="font-semibold text-gray-900">{{ number_format($aiHealth['used_today'] ?? 0) }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3">
                        <span class="text-gray-500">{{ __('admin.dashboard.ai_total_calls') }}</span>
                        <span class="font-semibold text-gray-900">{{ number_format($aiHealth['total_used'] ?? 0) }}</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-100 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.dashboard.url_import_health') }}</h3>
                    <a href="{{ route('admin.url-import.history') }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">{{ __('admin.dashboard.view_all') }}</a>
                </div>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-4 gap-3">
                    <div class="rounded-lg bg-slate-50 p-3 text-center">
                        <div class="text-xl font-bold text-slate-900">{{ $urlImportHealth['total'] ?? 0 }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ __('admin.dashboard.url_import_total') }}</div>
                    </div>
                    <div class="rounded-lg bg-blue-50 p-3 text-center">
                        <div class="text-xl font-bold text-blue-700">{{ $urlImportHealth['running'] ?? 0 }}</div>
                        <div class="mt-1 text-xs text-blue-700">{{ __('admin.dashboard.url_import_running') }}</div>
                    </div>
                    <div class="rounded-lg bg-emerald-50 p-3 text-center">
                        <div class="text-xl font-bold text-emerald-700">{{ $urlImportHealth['completed'] ?? 0 }}</div>
                        <div class="mt-1 text-xs text-emerald-700">{{ __('admin.dashboard.url_import_completed') }}</div>
                    </div>
                    <div class="rounded-lg bg-red-50 p-3 text-center">
                        <div class="text-xl font-bold text-red-700">{{ $urlImportHealth['failed'] ?? 0 }}</div>
                        <div class="mt-1 text-xs text-red-700">{{ __('admin.dashboard.url_import_failed') }}</div>
                    </div>
                </div>
                <div class="mt-5 space-y-2">
                    @forelse (($urlImportHealth['recent_jobs'] ?? []) as $job)
                        @php
                            $jobStatus = (string) ($job->status ?? 'queued');
                            $jobStatusLabel = in_array($jobStatus, ['queued', 'running', 'completed', 'failed'], true)
                                ? __('admin.url_import_history.status.'.$jobStatus)
                                : $jobStatus;
                        @endphp
                        <a href="{{ route('admin.url-import.show', $job->id) }}" class="flex items-center justify-between rounded-lg border border-gray-100 px-4 py-3 text-sm hover:bg-gray-50">
                            <span class="min-w-0 truncate text-gray-700">{{ $job->page_title ?: ($job->source_domain ?: '#'.$job->id) }}</span>
                            <span class="ml-3 shrink-0 rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">{{ $jobStatusLabel }}</span>
                        </a>
                    @empty
                        <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</p>
                    @endforelse
                </div>
            </div>
        </section>
    </div>

    <div class="mt-6">
        <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-100 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.analytics.top_content') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.analytics.article') }}</th>
                            <th class="whitespace-nowrap px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.analytics.category') }}</th>
                            <th class="whitespace-nowrap px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.analytics.views') }}</th>
                            <th class="whitespace-nowrap px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.analytics.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($topContent as $article)
                            @php
                                $articleStatus = (string) ($article->status ?? '');
                                $articleStatusLabel = in_array($articleStatus, ['draft', 'published', 'private'], true)
                                    ? __('admin.articles.status.'.$articleStatus)
                                    : ($articleStatus !== '' ? $articleStatus : __('admin.articles.status.draft'));
                            @endphp
                            <tr>
                                <td class="min-w-[18rem] px-6 py-4 text-sm font-medium text-gray-900">{{ $article->title }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $article->category_name ?? __('admin.dashboard.uncategorized') }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ number_format((int) $article->view_count) }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $articleStatusLabel }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
