@extends('admin.layouts.app')

@php
    $statusLabels = [
        'draft' => '草稿',
        'ready' => '待转文章',
        'converted' => '已转文章',
    ];
    $brief = (array) ($draft->writingTask?->brief ?? []);
    $briefSource = ($brief['source'] ?? '') === 'reference_content' ? '参考内容简报' : 'GEO 诊断报告';
    $referenceCount = count((array) ($brief['references'] ?? []));
    $latestAudit = $draft->audits->first();
    $failedChecks = (array) ($latestAudit?->failed_checks ?? []);
    $hasBlockingFailure = count(array_intersect($failedChecks, ['forbidden_terms', 'brand_mentioned'])) > 0;
    $readinessLabel = $hasBlockingFailure
        ? '禁止发布'
        : (($latestAudit && (int) $latestAudit->score >= 80 && (($brief['source'] ?? '') !== 'reference_content' || $referenceCount > 0)) ? '可发布' : '需要补充');
    $readinessClass = match ($readinessLabel) {
        '可发布' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        '禁止发布' => 'bg-red-50 text-red-700 border-red-200',
        default => 'bg-amber-50 text-amber-700 border-amber-200',
    };
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('admin.geo.reports.show', ['taskId' => (int) $task->id]) }}" class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    返回诊断报告
                </a>
                <h1 class="mt-3 text-2xl font-semibold text-gray-900">编辑文章草稿</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $report->title }} · {{ $organization->name }}</p>
            </div>
            <span class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600">
                <i data-lucide="file-pen-line" class="h-4 w-4 text-blue-500"></i>
                {{ $statusLabels[$draft->status] ?? $draft->status }}
            </span>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <form method="POST" action="{{ route('admin.geo.reports.article-drafts.update', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]) }}" class="space-y-6">
                @csrf
                @method('PUT')

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">基础内容</h2>
                    </div>
                    <div class="space-y-5 p-5">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700">标题</label>
                            <input id="title" name="title" type="text" required value="{{ old('title', $draft->title) }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="summary" class="block text-sm font-medium text-gray-700">摘要</label>
                            <textarea id="summary" name="summary" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('summary', $draft->summary) }}</textarea>
                        </div>
                        <div>
                            <label for="content_markdown" class="block text-sm font-medium text-gray-700">正文 Markdown</label>
                            <textarea id="content_markdown" name="content_markdown" rows="20" required class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm leading-6 focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('content_markdown', $draft->content_markdown) }}</textarea>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">SEO 信息</h2>
                    </div>
                    <div class="space-y-5 p-5">
                        <div>
                            <label for="seo_title" class="block text-sm font-medium text-gray-700">SEO 标题</label>
                            <input id="seo_title" name="seo_title" type="text" value="{{ old('seo_title', $draft->seo_title) }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="seo_description" class="block text-sm font-medium text-gray-700">SEO 描述</label>
                            <textarea id="seo_description" name="seo_description" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('seo_description', $draft->seo_description) }}</textarea>
                        </div>
                    </div>
                </section>

                <div class="flex flex-wrap justify-end gap-3">
                    <a href="{{ route('admin.geo.reports.show', ['taskId' => (int) $task->id]) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        取消
                    </a>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="save" class="h-4 w-4"></i>
                        保存草稿
                    </button>
                </div>
            </form>

            <aside class="space-y-4">
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900">来源报告</h2>
                    <div class="mt-4 space-y-3 text-sm text-gray-600">
                        <div>
                            <div class="text-xs text-gray-500">报告</div>
                            <div class="mt-1 font-medium text-gray-900">{{ $report->title }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">综合得分</div>
                            <div class="mt-1 font-medium text-gray-900">{{ (int) $report->total_score }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">更新时间</div>
                            <div class="mt-1 font-medium text-gray-900">{{ $draft->updated_at?->format('Y-m-d H:i') }}</div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-900">发布准备</h2>
                        <span class="inline-flex rounded-lg border px-2.5 py-1 text-xs font-medium {{ $readinessClass }}">{{ $readinessLabel }}</span>
                    </div>
                    <div class="mt-4 space-y-3 text-sm text-gray-600">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-500">简报来源</span>
                            <span class="font-medium text-gray-900">{{ $briefSource }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-500">参考来源</span>
                            <span class="font-medium text-gray-900">参考来源 {{ $referenceCount }} 条</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-500">最近审核</span>
                            <span class="font-medium text-gray-900">{{ $latestAudit ? ((int) $latestAudit->score).' 分' : '未审核' }}</span>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900">文章管理</h2>
                    @if($draft->article)
                        <a href="{{ route('admin.articles.edit', ['articleId' => (int) $draft->article->id]) }}" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                            <i data-lucide="external-link" class="h-4 w-4"></i>
                            打开文章
                        </a>
                    @else
                        <form method="POST" action="{{ route('admin.geo.reports.article-drafts.convert', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]) }}" class="mt-4">
                            @csrf
                            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                                <i data-lucide="send" class="h-4 w-4"></i>
                                转为正式文章
                            </button>
                        </form>
                    @endif
                </section>
            </aside>
        </div>
    </div>
@endsection
