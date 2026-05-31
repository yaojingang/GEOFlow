@extends('admin.layouts.app')

@php
    $statusLabels = [
        'pending_crawl' => '待采集',
        'crawled' => '已采集',
        'crawl_failed' => '采集失败',
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">GEO Reference</p>
                <h1 class="mt-2 text-2xl font-semibold text-gray-900">引用来源库</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $organization->name }} · 采集来源页面，筛选后续仿写和内容简报可借鉴素材</p>
            </div>
            <a href="{{ route('admin.geo.workspace') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                返回工作台
            </a>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">批量处理</h2>
                    <p class="mt-1 text-sm text-gray-500">勾选来源后，可批量采集、批量评分，并把高分来源沉淀为参考内容简报</p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div>
                        <label for="reference_brief_title" class="block text-sm font-medium text-gray-700">简报标题</label>
                        <input id="reference_brief_title" form="citation-sources-batch-form" name="title" type="text" placeholder="例如：涪陵全屋定制参考内容简报" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 sm:w-72">
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" form="citation-sources-batch-form" formaction="{{ route('admin.geo.citation-sources.batch-crawl') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="download-cloud" class="h-4 w-4"></i>
                            批量采集
                        </button>
                        <button type="submit" form="citation-sources-batch-form" formaction="{{ route('admin.geo.citation-sources.batch-score') }}" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                            <i data-lucide="star" class="h-4 w-4"></i>
                            批量评分
                        </button>
                        <button type="submit" form="citation-sources-batch-form" formaction="{{ route('admin.geo.citation-sources.reference-brief.store') }}" class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100">
                            <i data-lucide="file-plus-2" class="h-4 w-4"></i>
                            生成简报
                        </button>
                    </div>
                </div>
            </div>
            <form id="citation-sources-batch-form" method="POST" action="{{ route('admin.geo.citation-sources.batch-crawl') }}">
                @csrf
            </form>
        </section>

        @if ($referenceBriefs->isNotEmpty())
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h2 class="text-base font-semibold text-gray-900">参考内容简报</h2>
                </div>
                <div class="divide-y divide-gray-100">
                    @foreach ($referenceBriefs as $brief)
                        <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-gray-900">{{ $brief->title }}</div>
                                <div class="mt-1 text-xs text-gray-500">参考 {{ count((array) (($brief->brief ?? [])['references'] ?? [])) }} 条 · {{ $brief->created_at?->format('Y-m-d H:i') }}</div>
                            </div>
                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                @if ($brief->articleDrafts->isNotEmpty())
                                    <span class="rounded-lg bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">已生成草稿</span>
                                @else
                                    <form method="POST" action="{{ route('admin.geo.citation-sources.reference-briefs.article-draft.store', ['writingTaskId' => (int) $brief->id]) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800">
                                            <i data-lucide="file-text" class="h-3.5 w-3.5"></i>
                                            生成草稿
                                        </button>
                                    </form>
                                @endif
                                <span class="rounded-lg bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600">{{ $brief->status }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">选择</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">来源</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">状态</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">引用</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">采集摘要</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">质量评分</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($sources as $source)
                            @php
                                $snapshot = $source->latestPageSnapshot;
                                $score = $snapshot?->latestScore;
                                $analysis = $source->latestReferenceAnalysis;
                            @endphp
                            <tr>
                                <td class="px-5 py-4">
                                    <input form="citation-sources-batch-form" type="checkbox" name="source_ids[]" value="{{ (int) $source->id }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                </td>
                                <td class="px-5 py-4">
                                    <div class="max-w-md">
                                        <div class="text-sm font-semibold text-gray-900">{{ $source->domain ?: '未知域名' }}</div>
                                        <a href="{{ $source->url }}" target="_blank" class="mt-1 block truncate text-xs text-blue-600 hover:text-blue-700">{{ $source->url }}</a>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-600">{{ $statusLabels[$source->status] ?? $source->status }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">{{ (int) $source->citation_count }} 次</td>
                                <td class="px-5 py-4">
                                    @if ($snapshot)
                                        <div class="max-w-sm">
                                            <div class="truncate text-sm font-medium text-gray-900">{{ $snapshot->title ?: '未识别标题' }}</div>
                                            <div class="mt-1 line-clamp-2 text-xs text-gray-500">{{ $snapshot->content_summary ?: $snapshot->error_message }}</div>
                                        </div>
                                    @else
                                        <span class="text-sm text-gray-400">暂无快照</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    @if ($score)
                                        <div class="text-sm font-semibold text-emerald-700">{{ (int) $score->total_score }} 分</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $score->suggested_usage }}</div>
                                        @if ($analysis)
                                            <div class="mt-1 inline-flex items-center gap-1 rounded-lg bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">
                                                <i data-lucide="file-search" class="h-3 w-3"></i>
                                                已分析
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-sm text-gray-400">未评分</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <a href="{{ route('admin.geo.citation-sources.show', ['sourceId' => (int) $source->id]) }}" class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">
                                            <i data-lucide="eye" class="h-3.5 w-3.5"></i>
                                            详情
                                        </a>
                                        <form method="POST" action="{{ route('admin.geo.citation-sources.crawl', ['sourceId' => (int) $source->id]) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                                <i data-lucide="download-cloud" class="h-3.5 w-3.5"></i>
                                                采集
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.geo.citation-sources.analyze', ['sourceId' => (int) $source->id]) }}">
                                            @csrf
                                            <button type="submit" @disabled(! $score) class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                                                <i data-lucide="file-search" class="h-3.5 w-3.5"></i>
                                                本地分析
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-sm text-gray-500">暂无引用来源，先运行 AI 搜索批次</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($sources->hasPages())
                <div class="border-t border-gray-100 px-5 py-4">
                    {{ $sources->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
