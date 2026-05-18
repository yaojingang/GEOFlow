@extends('admin.layouts.app')

@php
    $aliasesText = implode("\n", (array) ($brandProfile?->aliases ?? []));
    $keywordTypeLabels = [
        'industry' => '行业词',
        'brand' => '品牌词',
        'competitor' => '竞品词',
        'question' => '问题词',
    ];
    $statusLabels = [
        'pending' => '待执行',
        'running' => '执行中',
        'completed' => '已完成',
        'partial_failed' => '部分失败',
        'failed' => '失败',
    ];
    $searchRunStatusLabels = [
        'pending' => '待运行',
        'running' => '运行中',
        'completed' => '已完成',
        'partial_failed' => '部分失败',
        'failed' => '失败',
    ];
    $citationSourceStatusLabels = [
        'pending_crawl' => '待采集',
        'crawled' => '已采集',
        'crawl_failed' => '采集失败',
    ];
    $trendMetrics = $trendMetrics ?? [
        'latest_score' => null,
        'average_score' => null,
        'delta' => null,
        'reports_count' => 0,
    ];
    $pipelineMetrics = $pipelineMetrics ?? [
        'drafts' => 0,
        'converted' => 0,
        'audits' => 0,
        'conversion_label' => '0 / 0',
    ];
    $realAiModels = $realAiModels ?? collect();
    $opportunities = $opportunities ?? collect();
    $searchRuns = $searchRuns ?? collect();
    $citationSources = $citationSources ?? collect();
    $aiPlatformCount = $platforms->count() + $realAiModels->count();
    $trendDelta = $trendMetrics['delta'];
    $trendDeltaLabel = $trendDelta === null
        ? '暂无对比'
        : '较上次 '.($trendDelta >= 0 ? '+'.$trendDelta : (string) $trendDelta);
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">GEO MVP</p>
                <h1 class="mt-2 text-2xl font-semibold text-gray-900">GEO 工作台</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $organization->name }}</p>
            </div>
            <div class="flex flex-wrap gap-2 text-sm text-gray-600">
                <span class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-2">
                    <i data-lucide="coins" class="h-4 w-4 text-amber-500"></i>
                    点数 {{ (int) $organization->points }}
                </span>
                <span class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-2">
                    <i data-lucide="badge-check" class="h-4 w-4 text-emerald-500"></i>
                    {{ $organization->plan_code }}
                </span>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">品牌资料</span>
                    <i data-lucide="building-2" class="h-5 w-5 text-blue-500"></i>
                </div>
                <div class="mt-3 text-2xl font-semibold text-gray-900">{{ $brandProfile ? 1 : 0 }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">关键词</span>
                    <i data-lucide="list-filter" class="h-5 w-5 text-emerald-500"></i>
                </div>
                <div class="mt-3 text-2xl font-semibold text-gray-900">{{ $keywords->count() }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">诊断任务</span>
                    <i data-lucide="radar" class="h-5 w-5 text-indigo-500"></i>
                </div>
                <div class="mt-3 text-2xl font-semibold text-gray-900">{{ $tasks->count() }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">AI 平台</span>
                    <i data-lucide="sparkles" class="h-5 w-5 text-purple-500"></i>
                </div>
                <div class="mt-3 text-2xl font-semibold text-gray-900">{{ $aiPlatformCount }}</div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">GEO 趋势</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ (int) $trendMetrics['reports_count'] }} 份报告纳入统计</p>
                    </div>
                    <i data-lucide="trending-up" class="h-5 w-5 text-emerald-500"></i>
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div>
                        <div class="text-sm text-gray-500">最新得分</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $trendMetrics['latest_score'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">平均得分</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $trendMetrics['average_score'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">趋势变化</div>
                        <div class="mt-2 text-sm font-medium {{ ($trendDelta ?? 0) >= 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ $trendDeltaLabel }}</div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">内容闭环</h2>
                        <p class="mt-1 text-sm text-gray-500">从诊断报告到草稿、正式文章和发布前检查</p>
                    </div>
                    <i data-lucide="route" class="h-5 w-5 text-blue-500"></i>
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div>
                        <div class="text-sm text-gray-500">文章草稿</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) $pipelineMetrics['drafts'] }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">已转文章</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $pipelineMetrics['conversion_label'] }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">GEO 检查</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) $pipelineMetrics['audits'] }}</div>
                    </div>
                </div>
            </section>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(360px,0.8fr)]">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">关键词机会库</h2>
                        <p class="mt-1 text-sm text-gray-500">从企业资料批量生成可用于 GEO 搜索的机会词</p>
                    </div>
                    <form method="POST" action="{{ route('admin.geo.opportunities.generate') }}" class="flex items-center gap-2">
                        @csrf
                        <input type="hidden" name="limit" value="12">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="sparkles" class="h-4 w-4"></i>
                            生成机会词
                        </button>
                    </form>
                </div>

                <form method="POST" action="{{ route('admin.geo.search-runs.store') }}" class="space-y-4 p-5">
                    @csrf
                    <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
                        <div>
                            <label for="search_run_name" class="block text-sm font-medium text-gray-700">批次名称</label>
                            <input id="search_run_name" name="name" type="text" value="{{ old('name') }}" placeholder="例如：第一批 GEO 机会搜索" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">可选机会</label>
                            <div class="mt-2 text-sm text-gray-500">{{ $opportunities->count() }} 个</div>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">选择</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">机会词</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">意图</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">机会分</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">理由</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse ($opportunities as $opportunity)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <input type="checkbox" name="opportunity_ids[]" value="{{ (int) $opportunity->id }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        </td>
                                        <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $opportunity->keyword }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ $opportunity->intent }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-emerald-700">{{ (int) $opportunity->opportunity_score }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">{{ $opportunity->rationale }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">还没有关键词机会，先生成一批</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <div class="mb-2 text-sm font-medium text-gray-700">用于搜索的 AI 平台</div>
                        <div class="grid gap-2 md:grid-cols-3">
                            @foreach ($platforms as $platform)
                                <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                    <input type="checkbox" name="platform_codes[]" value="{{ $platform->code }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>{{ $platform->name }}</span>
                                </label>
                            @endforeach
                            @foreach ($realAiModels as $model)
                                <label class="flex items-center gap-2 rounded-lg border border-blue-100 bg-blue-50/40 px-3 py-2 text-sm text-gray-700">
                                    <input type="checkbox" name="platform_codes[]" value="ai_model:{{ (int) $model->id }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="min-w-0">
                                        <span class="block truncate">{{ $model->name }}</span>
                                        <span class="block truncate text-xs text-gray-500">{{ $model->model_id }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" @disabled($opportunities->isEmpty()) class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300">
                            <i data-lucide="search" class="h-4 w-4"></i>
                            创建 AI 搜索批次
                        </button>
                    </div>
                </form>
            </section>

            <div class="space-y-6">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">AI 搜索批次</h2>
                        <span class="text-sm text-gray-500">{{ $searchRuns->count() }} 条</span>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse ($searchRuns as $run)
                            <div class="p-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-gray-900">{{ $run->name }}</div>
                                        <div class="mt-1 text-xs text-gray-500">问题 {{ (int) $run->total_questions }} · 平均可见度 {{ (int) $run->average_score }}</div>
                                    </div>
                                    <span class="shrink-0 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-600">{{ $searchRunStatusLabels[$run->status] ?? $run->status }}</span>
                                </div>
                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <div class="text-xs text-gray-500">完成 {{ (int) $run->completed_questions }} / 失败 {{ (int) $run->failed_questions }}</div>
                                    @if (in_array($run->status, ['pending', 'failed', 'partial_failed'], true))
                                        <form method="POST" action="{{ route('admin.geo.search-runs.run', ['runId' => (int) $run->id]) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                                <i data-lucide="play" class="h-3.5 w-3.5"></i>
                                                运行搜索
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-8 text-center text-sm text-gray-500">暂无 AI 搜索批次</div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">引用来源库</h2>
                            <p class="mt-1 text-xs text-gray-500">采集页面并筛选可借鉴参考内容</p>
                        </div>
                        <a href="{{ route('admin.geo.citation-sources.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">查看全部</a>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse ($citationSources as $source)
                            <div class="p-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-gray-900">{{ $source->domain ?: '未知域名' }}</div>
                                        <a href="{{ $source->url }}" target="_blank" class="mt-1 block truncate text-xs text-blue-600 hover:text-blue-700">{{ $source->url }}</a>
                                    </div>
                                    <span class="shrink-0 rounded-lg bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">{{ $citationSourceStatusLabels[$source->status] ?? $source->status }}</span>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                    <span>引用 {{ (int) $source->citation_count }} 次</span>
                                    <span>·</span>
                                    <span>{{ $source->last_seen_at?->format('Y-m-d H:i') }}</span>
                                    @if ($source->latestPageSnapshot?->latestScore)
                                        <span class="rounded-lg bg-emerald-50 px-2 py-1 font-medium text-emerald-700">评分 {{ (int) $source->latestPageSnapshot->latestScore->total_score }}</span>
                                    @endif
                                </div>
                                <div class="mt-3 flex justify-end">
                                    <a href="{{ route('admin.geo.citation-sources.show', ['sourceId' => (int) $source->id]) }}" class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">
                                        <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
                                        查看
                                    </a>
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-8 text-center text-sm text-gray-500">运行 AI 搜索后会自动沉淀引用来源</div>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(340px,0.85fr)]">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h2 class="text-base font-semibold text-gray-900">品牌知识库</h2>
                </div>
                <form method="POST" action="{{ route('admin.geo.brand-profile.save') }}" class="space-y-5 p-5">
                    @csrf
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="organization_name" class="block text-sm font-medium text-gray-700">企业名称</label>
                            <input id="organization_name" name="organization_name" type="text" value="{{ old('organization_name', $organization->name) }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="brand_name" class="block text-sm font-medium text-gray-700">品牌名称</label>
                            <input id="brand_name" name="brand_name" type="text" value="{{ old('brand_name', $brandProfile?->brand_name ?? '') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div>
                        <label for="aliases_text" class="block text-sm font-medium text-gray-700">品牌别名</label>
                        <textarea id="aliases_text" name="aliases_text" rows="2" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('aliases_text', $aliasesText) }}</textarea>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="products" class="block text-sm font-medium text-gray-700">产品/服务</label>
                            <textarea id="products" name="products" rows="4" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('products', $brandProfile?->products ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="advantages" class="block text-sm font-medium text-gray-700">核心优势</label>
                            <textarea id="advantages" name="advantages" rows="4" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('advantages', $brandProfile?->advantages ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="cases" class="block text-sm font-medium text-gray-700">案例素材</label>
                            <textarea id="cases" name="cases" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('cases', $brandProfile?->cases ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="pain_points" class="block text-sm font-medium text-gray-700">客户痛点</label>
                            <textarea id="pain_points" name="pain_points" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('pain_points', $brandProfile?->pain_points ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="service_area" class="block text-sm font-medium text-gray-700">服务区域</label>
                            <input id="service_area" name="service_area" type="text" value="{{ old('service_area', $brandProfile?->service_area ?? '') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="extra_facts" class="block text-sm font-medium text-gray-700">补充事实</label>
                            <input id="extra_facts" name="extra_facts" type="text" value="{{ old('extra_facts', $brandProfile?->extra_facts ?? '') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="save" class="h-4 w-4"></i>
                            保存品牌资料
                        </button>
                    </div>
                </form>
            </section>

            <div class="space-y-6">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">关键词库</h2>
                    </div>
                    <form method="POST" action="{{ route('admin.geo.keywords.store') }}" class="space-y-4 p-5">
                        @csrf
                        <div>
                            <label for="keyword" class="block text-sm font-medium text-gray-700">关键词/问题</label>
                            <input id="keyword" name="keyword" type="text" value="{{ old('keyword') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="type" class="block text-sm font-medium text-gray-700">类型</label>
                                <select id="type" name="type" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                                    @foreach ($keywordTypeLabels as $type => $label)
                                        <option value="{{ $type }}" @selected(old('type', 'question') === $type)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="intent" class="block text-sm font-medium text-gray-700">意图</label>
                                <input id="intent" name="intent" type="text" value="{{ old('intent', 'commercial') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">
                            <i data-lucide="plus" class="h-4 w-4"></i>
                            添加关键词
                        </button>
                    </form>

                    <div class="border-t border-gray-100 px-5 py-4">
                        @if ($keywords->isEmpty())
                            <p class="text-sm text-gray-500">暂无关键词</p>
                        @else
                            <div class="space-y-2">
                                @foreach ($keywords as $keyword)
                                    <div class="flex items-start justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-medium text-gray-900">{{ $keyword->keyword }}</div>
                                            <div class="mt-1 text-xs text-gray-500">{{ $keywordTypeLabels[$keyword->type] ?? $keyword->type }} · {{ $keyword->intent ?: '未标注意图' }}</div>
                                        </div>
                                        <span class="shrink-0 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-600">{{ $keyword->status }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">创建诊断任务</h2>
                    </div>
                    <form method="POST" action="{{ route('admin.geo.diagnosis.store') }}" class="space-y-5 p-5">
                        @csrf
                        <div>
                            <div class="mb-2 text-sm font-medium text-gray-700">选择关键词</div>
                            <div class="max-h-44 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3">
                                @forelse ($keywords as $keyword)
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="keyword_ids[]" value="{{ $keyword->id }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span class="min-w-0 truncate">{{ $keyword->keyword }}</span>
                                    </label>
                                @empty
                                    <div class="text-sm text-gray-500">暂无可选关键词</div>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <div class="mb-2 text-sm font-medium text-gray-700">选择 AI 平台</div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($platforms as $platform)
                                    <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                        <input type="checkbox" name="platform_codes[]" value="{{ $platform->code }}" checked class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span>{{ $platform->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50/50 p-3">
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <span class="text-sm font-medium text-gray-700">真实 AI 模型</span>
                                    <a href="{{ route('admin.ai-models.index') }}" class="text-xs font-medium text-blue-600 hover:text-blue-700">配置模型</a>
                                </div>
                                @if ($realAiModels->isEmpty())
                                    <div class="text-sm text-gray-500">暂无已启用聊天模型</div>
                                @else
                                    <div class="grid gap-2">
                                        @foreach ($realAiModels as $model)
                                            <label class="flex items-center gap-2 rounded-lg border border-blue-100 bg-white px-3 py-2 text-sm text-gray-700">
                                                <input type="checkbox" name="platform_codes[]" value="ai_model:{{ (int) $model->id }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate">{{ $model->name }}</span>
                                                    <span class="block truncate text-xs text-gray-500">{{ $model->model_id }}</span>
                                                </span>
                                                <span class="shrink-0 rounded-lg bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">真实</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                        <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                            <i data-lucide="play" class="h-4 w-4"></i>
                            创建诊断任务
                        </button>
                    </form>
                </section>
            </div>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                <h2 class="text-base font-semibold text-gray-900">最近诊断任务</h2>
                <span class="text-sm text-gray-500">{{ $tasks->count() }} 条</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">任务</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">状态</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">问题数</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">得分</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">点数</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">报告</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">创建时间</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($tasks as $task)
                            <tr>
                                <td class="px-5 py-4 text-sm font-medium text-gray-900">{{ $task->name }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">{{ $statusLabels[$task->status] ?? $task->status }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">{{ (int) $task->questions_count }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">{{ $task->status === 'completed' ? (int) $task->total_score : '—' }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">{{ (int) $task->points_cost }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">
                                    @if ($task->report)
                                        <div class="max-w-xs">
                                            <div class="font-medium text-gray-900">{{ $task->report->title }}</div>
                                            <div class="mt-1 line-clamp-2 text-xs text-gray-500">{{ $task->report->summary }}</div>
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-500">{{ $task->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-4 text-sm">
                                    @if (in_array($task->status, ['pending', 'failed'], true))
                                        <form method="POST" action="{{ route('admin.geo.diagnosis.run', ['taskId' => (int) $task->id]) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                                <i data-lucide="play" class="h-3.5 w-3.5"></i>
                                                执行诊断
                                            </button>
                                        </form>
                                    @elseif ($task->report)
                                        <a href="{{ route('admin.geo.reports.show', ['taskId' => (int) $task->id]) }}" class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100">
                                            <i data-lucide="file-check-2" class="h-3.5 w-3.5"></i>
                                            查看报告
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-8 text-center text-sm text-gray-500">暂无诊断任务</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
