@extends('admin.layouts.app')

@php
    $statusLabels = [
        'pending_crawl' => '待采集',
        'crawled' => '已采集',
        'crawl_failed' => '采集失败',
    ];
    $crawlStatusLabels = [
        'pending' => '待采集',
        'succeeded' => '采集成功',
        'failed' => '采集失败',
    ];
    $usageLabels = [
        'core_reference' => '核心参考',
        'outline_or_angle' => '提纲/角度参考',
        'brand_competitor_clue' => '品牌/竞品线索',
        'background_reference' => '背景参考',
        'reference' => '普通参考',
    ];
    $latestAnalysis = $source->referenceAnalyses->first();
    $analysisStructure = (array) ($latestAnalysis?->structure_json ?? []);
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">GEO Reference</p>
                <h1 class="mt-2 truncate text-2xl font-semibold text-gray-900">{{ $source->domain ?: '引用来源详情' }}</h1>
                <a href="{{ $source->url }}" target="_blank" class="mt-1 block truncate text-sm text-blue-600 hover:text-blue-700">{{ $source->url }}</a>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.geo.citation-sources.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    返回来源库
                </a>
                <form method="POST" action="{{ route('admin.geo.citation-sources.crawl', ['sourceId' => (int) $source->id]) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="download-cloud" class="h-4 w-4"></i>
                        采集页面
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.geo.citation-sources.score', ['sourceId' => (int) $source->id]) }}">
                    @csrf
                    <button type="submit" @disabled(! $latestSnapshot || $latestSnapshot->crawl_status !== 'succeeded') class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300">
                        <i data-lucide="star" class="h-4 w-4"></i>
                        质量评分
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.geo.citation-sources.analyze', ['sourceId' => (int) $source->id]) }}">
                    @csrf
                    <button type="submit" @disabled(! $latestSnapshot?->latestScore) class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                        <i data-lucide="file-search" class="h-4 w-4"></i>
                        本地分析
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.geo.citation-sources.imitation-draft.store', ['sourceId' => (int) $source->id]) }}">
                    @csrf
                    <button type="submit" @disabled(! $latestAnalysis) class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                        <i data-lucide="file-pen-line" class="h-4 w-4"></i>
                        按结构仿写文章
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.geo.citation-sources.publishable-draft.store', ['sourceId' => (int) $source->id]) }}">
                    @csrf
                    <button type="submit" @disabled(! $latestAnalysis) class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                        <i data-lucide="sparkles" class="h-4 w-4"></i>
                        生成可发布正文
                    </button>
                </form>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-gray-500">来源状态</div>
                <div class="mt-2 text-lg font-semibold text-gray-900">{{ $statusLabels[$source->status] ?? $source->status }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-gray-500">引用次数</div>
                <div class="mt-2 text-lg font-semibold text-gray-900">{{ (int) $source->citation_count }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-gray-500">最近采集</div>
                <div class="mt-2 text-lg font-semibold text-gray-900">{{ $latestSnapshot?->crawled_at?->format('m-d H:i') ?? '暂无' }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-gray-500">质量评分</div>
                <div class="mt-2 text-lg font-semibold text-emerald-700">{{ $latestSnapshot?->latestScore?->total_score ?? '未评分' }}</div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(340px,0.9fr)]">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h2 class="text-base font-semibold text-gray-900">页面快照</h2>
                </div>
                @if ($latestSnapshot)
                    <div class="space-y-4 p-5">
                        <div>
                            <div class="text-sm text-gray-500">标题</div>
                            <div class="mt-1 text-lg font-semibold text-gray-900">{{ $latestSnapshot->title ?: '未识别标题' }}</div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <div class="text-sm text-gray-500">采集状态</div>
                                <div class="mt-1 text-sm font-medium text-gray-900">{{ $crawlStatusLabels[$latestSnapshot->crawl_status] ?? $latestSnapshot->crawl_status }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">HTTP 状态</div>
                                <div class="mt-1 text-sm font-medium text-gray-900">{{ $latestSnapshot->http_status ?? '—' }}</div>
                            </div>
                        </div>
                        @if ($latestSnapshot->description)
                            <div>
                                <div class="text-sm text-gray-500">描述</div>
                                <p class="mt-1 text-sm text-gray-700">{{ $latestSnapshot->description }}</p>
                            </div>
                        @endif
                        @if ($latestSnapshot->error_message)
                            <div class="rounded-lg border border-red-100 bg-red-50 p-4 text-sm text-red-700">{{ $latestSnapshot->error_message }}</div>
                        @endif
                        <div>
                            <div class="text-sm text-gray-500">正文摘要</div>
                            <p class="mt-1 text-sm leading-6 text-gray-700">{{ $latestSnapshot->content_summary ?: '暂无正文摘要' }}</p>
                        </div>
                    </div>
                @else
                    <div class="px-5 py-10 text-center text-sm text-gray-500">还没有采集快照</div>
                @endif
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h2 class="text-base font-semibold text-gray-900">可借鉴用途</h2>
                </div>
                @if ($latestSnapshot?->latestScore)
                    @php($score = $latestSnapshot->latestScore)
                    <div class="space-y-4 p-5">
                        <div class="flex items-end justify-between gap-3">
                            <div>
                                <div class="text-sm text-gray-500">总分</div>
                                <div class="mt-1 text-3xl font-semibold text-emerald-700">{{ (int) $score->total_score }}</div>
                            </div>
                            <span class="rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">{{ $usageLabels[$score->suggested_usage] ?? $score->suggested_usage }}</span>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-lg bg-gray-50 p-3">
                                <div class="text-xs text-gray-500">相关度</div>
                                <div class="mt-1 text-sm font-semibold text-gray-900">{{ (int) $score->relevance_score }}</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <div class="text-xs text-gray-500">结构完整度</div>
                                <div class="mt-1 text-sm font-semibold text-gray-900">{{ (int) $score->structure_score }}</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <div class="text-xs text-gray-500">可借鉴度</div>
                                <div class="mt-1 text-sm font-semibold text-gray-900">{{ (int) $score->actionability_score }}</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <div class="text-xs text-gray-500">证据密度</div>
                                <div class="mt-1 text-sm font-semibold text-gray-900">{{ (int) $score->evidence_density_score }}</div>
                            </div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">评分理由</div>
                            <p class="mt-1 text-sm leading-6 text-gray-700">{{ $score->score_reason }}</p>
                        </div>
                    </div>
                @else
                    <div class="px-5 py-10 text-center text-sm text-gray-500">成功采集后可以生成质量评分</div>
                @endif
            </section>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">本地分析档案</h2>
                    <p class="mt-1 text-sm text-gray-500">把高分来源落到本地，并还原文章结构与被 AI 引用的原因。</p>
                </div>
                @if ($latestAnalysis)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700">{{ $latestAnalysis->analyzed_at?->format('Y-m-d H:i') }}</span>
                        <form method="POST" action="{{ route('admin.geo.citation-sources.imitation-draft.store', ['sourceId' => (int) $source->id]) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-purple-700">
                                <i data-lucide="file-pen-line" class="h-3.5 w-3.5"></i>
                                按结构仿写文章
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.geo.citation-sources.publishable-draft.store', ['sourceId' => (int) $source->id]) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700">
                                <i data-lucide="sparkles" class="h-3.5 w-3.5"></i>
                                生成可发布正文
                            </button>
                        </form>
                    </div>
                @endif
            </div>
            @if ($latestAnalysis)
                <div class="grid gap-6 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.8fr)]">
                    <div class="space-y-5">
                        <div>
                            <div class="text-sm font-medium text-gray-900">文章结构拆解</div>
                            <div class="mt-3 space-y-3">
                                @foreach ((array) ($analysisStructure['article_sections'] ?? []) as $section)
                                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                                        <div class="text-sm font-semibold text-gray-900">{{ $section['title'] ?? '内容段落' }}</div>
                                        @if (! empty($section['summary']))
                                            <div class="mt-1 text-xs leading-5 text-gray-600">{{ $section['summary'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-900">为什么会被引用</div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ((array) ($analysisStructure['citation_reasons'] ?? []) as $reason)
                                    <span class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700">{{ $reason }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <div class="text-sm font-medium text-gray-900">可复用写法</div>
                            <ul class="mt-3 space-y-2 text-sm leading-6 text-gray-700">
                                @foreach ((array) ($analysisStructure['writing_patterns'] ?? []) as $pattern)
                                    <li>{{ $pattern }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <div class="rounded-lg border border-blue-100 bg-blue-50/60 p-4">
                            <div class="text-sm font-medium text-gray-900">本地文件</div>
                            <div class="mt-2 space-y-1 text-xs text-gray-600">
                                <div class="break-all">Markdown：{{ $latestAnalysis->markdown_path }}</div>
                                <div class="break-all">JSON：{{ $latestAnalysis->json_path }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="px-5 py-10 text-center text-sm text-gray-500">成功采集并评分后，可以生成本地分析档案</div>
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-base font-semibold text-gray-900">历史快照</h2>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse ($source->pageSnapshots as $snapshot)
                    <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-gray-900">{{ $snapshot->title ?: $snapshot->url }}</div>
                            <div class="mt-1 text-xs text-gray-500">{{ $crawlStatusLabels[$snapshot->crawl_status] ?? $snapshot->crawl_status }} · {{ $snapshot->crawled_at?->format('Y-m-d H:i') }}</div>
                        </div>
                        <div class="text-sm font-semibold text-emerald-700">{{ $snapshot->latestScore ? (int) $snapshot->latestScore->total_score.' 分' : '未评分' }}</div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-500">暂无历史快照</div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
