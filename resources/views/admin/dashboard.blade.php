@extends('admin.layouts.app')

@section('content')
    @php
        $publishRate = ($stats['total_articles'] ?? 0) > 0 ? round((($stats['published_articles'] ?? 0) / ($stats['total_articles'] ?? 1)) * 100, 1) : 0;
        $aiRatio = ($stats['total_articles'] ?? 0) > 0 ? round((($stats['ai_generated_articles'] ?? 0) / ($stats['total_articles'] ?? 1)) * 100, 1) : 0;
        $materialTotal = ($stats['total_keywords'] ?? 0) + ($stats['total_titles'] ?? 0) + ($stats['total_images'] ?? 0);
        $tc = $trend_chart ?? [];
        $ch = (int) ($tc['chart_height'] ?? 148);
        $cw = (int) ($tc['chart_width'] ?? 600);
    @endphp

    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.dashboard.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.dashboard.subtitle', ['site' => e($adminSiteName)]) }}</p>
                </div>
                <div class="flex items-center space-x-3">
                    <span class="text-sm text-gray-500">{{ __('admin.dashboard.last_updated', ['time' => now()->format('Y-m-d H:i:s')]) }}</span>
                    <button type="button" onclick="location.reload()" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="refresh-cw" class="w-4 h-4 mr-1"></i>
                        {{ __('admin.dashboard.refresh') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file-text" class="h-8 w-8 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.dashboard.total_articles') }}</dt>
                                <dd class="text-2xl font-bold text-gray-900">{{ number_format($stats['total_articles'] ?? 0) }}</dd>
                                <dd class="text-xs text-gray-500">{{ __('admin.dashboard.today_added', ['count' => $today_stats['today_articles'] ?? 0]) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="globe" class="h-8 w-8 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.dashboard.published') }}</dt>
                                <dd class="text-2xl font-bold text-gray-900">{{ number_format($stats['published_articles'] ?? 0) }}</dd>
                                <dd class="text-xs text-gray-500">{{ __('admin.dashboard.publish_rate', ['rate' => $publishRate]) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="brain" class="h-8 w-8 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.dashboard.ai_generated') }}</dt>
                                <dd class="text-2xl font-bold text-gray-900">{{ number_format($stats['ai_generated_articles'] ?? 0) }}</dd>
                                <dd class="text-xs text-gray-500">{{ __('admin.dashboard.ai_generated_ratio', ['rate' => $aiRatio]) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="eye" class="h-8 w-8 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.dashboard.total_views') }}</dt>
                                <dd class="text-2xl font-bold text-gray-900">{{ number_format((int) ($stats['total_views'] ?? 0)) }}</dd>
                                <dd class="text-xs text-gray-500">{{ __('admin.dashboard.today_views', ['count' => number_format($today_stats['today_views'] ?? 0)]) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="zap" class="h-6 w-6 text-yellow-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.dashboard.active_tasks') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ ($stats['running_jobs'] ?? 0) + ($stats['pending_jobs'] ?? 0) }} / {{ $stats['total_tasks'] ?? 0 }}</dd>
                                <dd class="text-xs text-gray-500">{{ __('admin.dashboard.active_tasks_detail', ['running' => $stats['running_jobs'] ?? 0, 'pending' => $stats['pending_jobs'] ?? 0]) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="cpu" class="h-6 w-6 text-indigo-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.dashboard.ai_models') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $stats['active_ai_models'] ?? 0 }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="database" class="h-6 w-6 text-teal-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.dashboard.material_total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ number_format($materialTotal) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="clock" class="h-6 w-6 text-red-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.dashboard.pending_review') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $stats['pending_review'] ?? 0 }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <section class="mb-8 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-600">{{ __('admin.dashboard.quick_start.eyebrow') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.title') }}</h2>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 divide-y divide-gray-100 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">1</div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.api_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.api_desc') }}</p>
                            <a href="{{ route('admin.ai-models.index') }}" class="mt-4 inline-flex items-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="plug-zap" class="mr-1.5 h-4 w-4"></i>
                                {{ __('admin.dashboard.quick_start.api_button') }}
                            </a>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-sm font-semibold text-white">2</div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.material_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.material_desc') }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="{{ route('admin.knowledge-bases.index') }}" class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-medium text-orange-700 hover:bg-orange-100">
                                    {{ __('admin.dashboard.quick_start.knowledge') }}
                                </a>
                                <a href="{{ route('admin.title-libraries.index') }}" class="inline-flex items-center rounded-full border border-green-100 bg-green-50 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-100">
                                    {{ __('admin.dashboard.quick_start.titles') }}
                                </a>
                                <a href="{{ route('admin.keyword-libraries.index') }}" class="inline-flex items-center rounded-full border border-blue-100 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                    {{ __('admin.dashboard.quick_start.keywords') }}
                                </a>
                                <a href="{{ route('admin.image-libraries.index') }}" class="inline-flex items-center rounded-full border border-purple-100 bg-purple-50 px-3 py-1.5 text-xs font-medium text-purple-700 hover:bg-purple-100">
                                    {{ __('admin.dashboard.quick_start.images') }}
                                </a>
                                <a href="{{ route('admin.authors.index') }}" class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100">
                                    {{ __('admin.dashboard.quick_start.authors') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-900 text-sm font-semibold text-white">3</div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.task_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.task_desc') }}</p>
                            <a href="{{ route('admin.tasks.create') }}" class="mt-4 inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <i data-lucide="plus" class="mr-1.5 h-4 w-4"></i>
                                {{ __('admin.dashboard.quick_start.task_button') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.dashboard.category_distribution') }}</h3>
                        <a href="{{ route('admin.categories.index') }}" class="text-sm text-blue-600 hover:text-blue-800">
                            <i data-lucide="settings" class="w-4 h-4 inline mr-1"></i>
                            {{ __('admin.dashboard.manage_categories') }}
                        </a>
                    </div>
                </div>
                <div class="p-6">
                    @if (empty($category_distribution))
                        <p class="text-gray-500 text-center py-4">{{ __('admin.dashboard.no_data') }}</p>
                    @else
                        <div class="space-y-3">
                            @foreach ($category_distribution as $category)
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-sm font-medium text-gray-900">{{ $category['name'] }}</span>
                                            <span class="text-sm text-gray-500">{{ $category['count'] }}</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-blue-600 h-2 rounded-full" style="width: {{ ($stats['total_articles'] ?? 0) > 0 ? ($category['count'] / ($stats['total_articles'] ?? 1)) * 100 : 0 }}%"></div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.dashboard.system_performance') }}</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-700">{{ __('admin.dashboard.task_success_rate') }}</span>
                                <span class="text-sm text-gray-900">{{ number_format($performance_stats['success_rate'] ?? 0, 1) }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-green-600 h-2 rounded-full" style="width: {{ min($performance_stats['success_rate'] ?? 0, 100) }}%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-700">{{ __('admin.dashboard.avg_generation_time') }}</span>
                                <span class="text-sm text-gray-900">{{ number_format($performance_stats['avg_generation_time'] ?? 0, 1) }}s</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-yellow-600 h-2 rounded-full" style="width: {{ min((($performance_stats['avg_generation_time'] ?? 0) / 60) * 100, 100) }}%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-700">{{ __('admin.dashboard.daily_ai_quota') }}</span>
                                <span class="text-sm text-gray-900">{{ $performance_stats['daily_quota_used'] ?? 0 }} / 100</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-purple-600 h-2 rounded-full" style="width: {{ min((($performance_stats['daily_quota_used'] ?? 0) / 100) * 100, 100) }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.dashboard.latest_articles') }}</h3>
                        <a href="{{ route('admin.articles.index') }}" class="text-sm text-blue-600 hover:text-blue-800">{{ __('admin.dashboard.view_all') }}</a>
                    </div>
                </div>
                <div class="p-6">
                    @if (empty($latest_articles))
                        <p class="text-gray-500 text-center py-4">{{ __('admin.dashboard.no_articles') }}</p>
                    @else
                        <div class="space-y-3">
                            @foreach ($latest_articles as $article)
                                <div class="flex items-start space-x-3">
                                    <div class="flex-shrink-0">
                                        @if (!empty($article->is_ai_generated))
                                            <i data-lucide="brain" class="w-4 h-4 text-purple-500 mt-0.5"></i>
                                        @else
                                            <i data-lucide="edit" class="w-4 h-4 text-gray-400 mt-0.5"></i>
                                        @endif
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 truncate">{{ $article->title }}</p>
                                        <p class="text-xs text-gray-500">
                                            {{ $article->category_name ?? __('admin.dashboard.uncategorized') }} •
                                            {{ $article->created_at ? \Illuminate\Support\Carbon::parse($article->created_at)->format('m-d H:i') : '' }}
                                        </p>
                                    </div>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ ($article->status ?? '') === 'published' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                        {{ ($article->status ?? '') === 'published' ? __('admin.articles.status.published') : __('admin.articles.status.draft') }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.dashboard.trend_title') }}</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-blue-600">{{ $week_stats['week_articles'] ?? 0 }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.dashboard.week_articles') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-green-600">{{ $week_stats['week_tasks'] ?? 0 }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.dashboard.week_tasks') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-purple-600">{{ $stats['approved_articles'] ?? 0 }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.dashboard.approved_articles') }}</div>
                    </div>
                </div>

                @if (!empty($article_trend))
                    <div class="mt-6">
                        <h4 class="text-sm font-medium text-gray-700 mb-4">{{ __('admin.dashboard.article_trend') }}</h4>
                        <div class="relative rounded-2xl border border-slate-200 bg-gradient-to-b from-slate-50 via-white to-white px-4 pt-5 pb-10 overflow-hidden" style="height: 236px;">
                            <div class="absolute left-0 top-0 flex flex-col justify-between text-[11px] text-slate-400" style="height: {{ $ch }}px; width: 28px;">
                                @foreach ($tc['y_ticks'] ?? [] as $tick)
                                    <span class="text-right">{{ $tick }}</span>
                                @endforeach
                            </div>

                            <svg class="absolute top-0" style="left: 36px; height: {{ $ch }}px; width: calc(100% - 48px);" viewBox="0 0 {{ $cw }} {{ $ch }}" preserveAspectRatio="none">
                                <defs>
                                    <linearGradient id="articleTrendFill" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.18"/>
                                        <stop offset="100%" stop-color="#3b82f6" stop-opacity="0.02"/>
                                    </linearGradient>
                                </defs>
                                @for ($i = 0; $i <= 4; $i++)
                                    @php $yPos = ($ch / 4) * $i; @endphp
                                    <line x1="0" y1="{{ $yPos }}" x2="{{ $cw }}" y2="{{ $yPos }}"
                                          stroke="{{ $i === 4 ? '#cbd5e1' : '#e2e8f0' }}"
                                          stroke-width="1"
                                          stroke-dasharray="{{ $i === 4 ? '0' : '4 6' }}"/>
                                @endfor

                                @if (!empty($tc['area_path']))
                                    <path d="{{ $tc['area_path'] }}" fill="url(#articleTrendFill)"/>
                                @endif
                                @if (!empty($tc['line_path']))
                                    <path d="{{ $tc['line_path'] }}"
                                          fill="none"
                                          stroke="rgba(59, 130, 246, 0.12)"
                                          stroke-width="6"
                                          stroke-linecap="round"
                                          stroke-linejoin="round"
                                          vector-effect="non-scaling-stroke"/>
                                    <path d="{{ $tc['line_path'] }}"
                                          fill="none"
                                          stroke="#3b82f6"
                                          stroke-width="2"
                                          stroke-linecap="round"
                                          stroke-linejoin="round"
                                          vector-effect="non-scaling-stroke"/>
                                @endif
                                @foreach ($tc['points'] ?? [] as $index => $point)
                                    <circle cx="{{ $point['x'] }}"
                                            cy="{{ $point['y'] }}"
                                            r="{{ $index === ($tc['peak_index'] ?? 0) ? '3.8' : '2.4' }}"
                                            fill="{{ $index === ($tc['peak_index'] ?? 0) ? '#3b82f6' : '#ffffff' }}"
                                            stroke="#3b82f6"
                                            stroke-width="{{ $index === ($tc['peak_index'] ?? 0) ? '1.8' : '1.4' }}"
                                            vector-effect="non-scaling-stroke"/>
                                @endforeach
                            </svg>

                            <div class="absolute flex justify-between text-xs text-slate-500" style="left: 36px; bottom: 0; width: calc(100% - 48px); height: 40px;">
                                @foreach ($article_trend as $day)
                                    <div class="flex items-start justify-center pt-2">
                                        <span>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('m/d') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-3 flex items-center justify-center space-x-8 text-xs text-gray-600">
                            {!! __('admin.dashboard.total_stat', ['count' => '<strong class="text-gray-900">'.e($tc['total_trend_count'] ?? 0).'</strong>']) !!}
                            {!! __('admin.dashboard.avg_stat', ['count' => '<strong class="text-gray-900">'.e($tc['avg_articles'] ?? 0).'</strong>']) !!}
                            {!! __('admin.dashboard.peak_stat', ['count' => '<strong class="text-gray-900">'.e($tc['max_count'] ?? 0).'</strong>']) !!}
                        </div>
                    </div>
                @else
                    <div class="mt-6 text-center text-gray-500 py-8">
                        <p class="text-sm">{{ __('admin.dashboard.no_data') }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
