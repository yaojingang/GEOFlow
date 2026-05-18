@extends('admin.layouts.app')

@php
    $scoreBands = [
        ['min' => 80, 'label' => 'AI 可见度较好', 'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        ['min' => 60, 'label' => '有基础曝光', 'class' => 'bg-blue-50 text-blue-700 border-blue-200'],
        ['min' => 30, 'label' => '推荐稳定性不足', 'class' => 'bg-amber-50 text-amber-700 border-amber-200'],
        ['min' => 0, 'label' => '基本不可见', 'class' => 'bg-red-50 text-red-700 border-red-200'],
    ];
    $band = collect($scoreBands)->first(fn (array $item): bool => (int) $report->total_score >= $item['min']);
    $platformNames = $platformNames ?? [];
    $reportMode = (string) ($task->report_mode ?: 'with_recommendations');
    $reportModeLabel = $reportMode === 'visibility_only' ? '客户报告' : '内部优化报告';
    $yixiaoerPlatformLabels = [
        'xiaohongshu' => '小红书',
        'douyin' => '抖音',
        'shipinhao' => '视频号',
        'bilibili' => 'B站',
    ];
    $auditCheckLabels = [
        'brand_mentioned' => '品牌已出现',
        'service_area_mentioned' => '服务区域已出现',
        'question_answered' => '覆盖用户问题',
        'faq_section' => '包含 FAQ',
        'evidence_facts' => '有事实信息',
        'forbidden_terms' => '禁用词检查',
        'reference_coverage' => '参考来源覆盖',
        'local_intent' => '本地意图覆盖',
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('admin.geo.workspace') }}" class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    返回 GEO 工作台
                </a>
                <h1 class="mt-3 text-2xl font-semibold text-gray-900">{{ $report->title }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $organization->name }} · {{ $task->created_at?->format('Y-m-d H:i') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm font-medium text-gray-700">
                    {{ $reportModeLabel }}
                </div>
                <div class="rounded-lg border {{ $band['class'] ?? 'border-gray-200 bg-white text-gray-700' }} px-4 py-3 text-sm font-medium">
                    {{ $band['label'] ?? '待评估' }}
                </div>
                <form method="POST" action="{{ route('admin.geo.reports.article-draft.store', ['taskId' => (int) $task->id]) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-3 text-sm font-medium text-white hover:bg-gray-800">
                        <i data-lucide="file-plus-2" class="h-4 w-4"></i>
                        生成文章草稿
                    </button>
                </form>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">综合得分</span>
                    <i data-lucide="gauge" class="h-5 w-5 text-blue-500"></i>
                </div>
                <div class="mt-3 text-3xl font-semibold text-gray-900">{{ (int) $report->total_score }}</div>
            </section>
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">问题数</span>
                    <i data-lucide="circle-help" class="h-5 w-5 text-indigo-500"></i>
                </div>
                <div class="mt-3 text-3xl font-semibold text-gray-900">{{ $task->questions()->count() }}</div>
            </section>
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">平台回答</span>
                    <i data-lucide="messages-square" class="h-5 w-5 text-emerald-500"></i>
                </div>
                <div class="mt-3 text-3xl font-semibold text-gray-900">{{ $task->answers->count() }}</div>
            </section>
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">消耗点数</span>
                    <i data-lucide="coins" class="h-5 w-5 text-amber-500"></i>
                </div>
                <div class="mt-3 text-3xl font-semibold text-gray-900">{{ (int) $task->points_cost }}</div>
            </section>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-base font-semibold text-gray-900">诊断结论</h2>
            </div>
            <div class="space-y-4 p-5">
                <p class="text-sm leading-6 text-gray-700">{{ $report->summary }}</p>
                <div class="rounded-lg bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-600">
                    品牌：{{ $task->brandProfile->brand_name }} · 服务区域：{{ $task->brandProfile->service_area ?: '未填写' }}
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-base font-semibold text-gray-900">平台回答明细</h2>
            </div>
            <div class="divide-y divide-gray-100">
                @foreach ($task->answers as $answer)
                    @php
                        $score = $answer->score;
                        $platformName = $platformNames[$answer->platform_code] ?? $answer->platform_code;
                    @endphp
                    <article class="space-y-4 p-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">{{ $platformName }}</h3>
                                <p class="mt-1 text-sm text-gray-500">{{ $answer->question?->question }}</p>
                            </div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($score?->score ?? 0) }}</div>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="rounded-lg px-2.5 py-1 {{ $score?->brand_mentioned ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">提及品牌：{{ $score?->brand_mentioned ? '是' : '否' }}</span>
                            <span class="rounded-lg px-2.5 py-1 {{ $score?->is_recommended ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600' }}">正向推荐：{{ $score?->is_recommended ? '是' : '否' }}</span>
                            <span class="rounded-lg bg-gray-100 px-2.5 py-1 text-gray-600">排名：{{ $score?->rank_position ?: '—' }}</span>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm leading-7 text-gray-700 whitespace-pre-wrap">{{ $answer->raw_answer }}</div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-base font-semibold text-gray-900">{{ $reportMode === 'visibility_only' ? '报告正文' : '优化建议' }}</h2>
            </div>
            <div class="prose max-w-none p-5 text-sm leading-7 text-gray-700">
                {!! $report->html_report !!}
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                <h2 class="text-base font-semibold text-gray-900">文章草稿</h2>
                <span class="text-sm text-gray-500">{{ $writingTasks->sum(fn ($task) => $task->articleDrafts->count()) }} 篇</span>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse ($writingTasks as $writingTask)
                    @foreach ($writingTask->articleDrafts as $draft)
                        @php
                            $draftStatusLabel = $draft->status === 'converted' ? '已转文章' : '草稿';
                            $draftStatusClass = $draft->status === 'converted'
                                ? 'bg-emerald-50 text-emerald-700'
                                : 'bg-amber-50 text-amber-700';
                            $latestAudit = $draft->audits->first();
                            $latestPublishRecord = $draft->publishRecords->first();
                            $latestRetest = $draft->publishRetests->first();
                        @endphp
                        <article class="space-y-4 p-5">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="inline-flex rounded-lg px-2.5 py-1 text-xs font-medium {{ $draftStatusClass }}">{{ $draftStatusLabel }}</div>
                                    <h3 class="mt-2 text-lg font-semibold text-gray-900">{{ $draft->title }}</h3>
                                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ $draft->summary }}</p>
                                </div>
                                <div class="flex flex-col gap-2 sm:items-end">
                                    <div class="text-xs text-gray-500">{{ $draft->updated_at?->format('Y-m-d H:i') }}</div>
                                    <div class="flex flex-wrap gap-2">
                                        <a href="{{ route('admin.geo.reports.article-drafts.edit', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]) }}" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            <i data-lucide="file-pen-line" class="h-3.5 w-3.5"></i>
                                            编辑草稿
                                        </a>
                                        @if($draft->article)
                                            <a href="{{ route('admin.articles.edit', ['articleId' => (int) $draft->article->id]) }}" class="inline-flex items-center gap-1 rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800">
                                                <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
                                                打开文章
                                            </a>
                                            <form method="POST" action="{{ route('admin.geo.reports.article-drafts.audit', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                                    <i data-lucide="scan-search" class="h-3.5 w-3.5"></i>
                                                    {{ $latestAudit ? '重新检查' : 'GEO 检查' }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.geo.reports.article-drafts.retest', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100">
                                                    <i data-lucide="refresh-cw" class="h-3.5 w-3.5"></i>
                                                    发布后复测
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.geo.reports.article-drafts.yixiaoer-handoff', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]) }}" class="flex items-center gap-1 rounded-lg border border-purple-100 bg-purple-50 px-2 py-1">
                                                @csrf
                                                @foreach(['xiaohongshu', 'douyin', 'shipinhao'] as $platformCode)
                                                    <input type="hidden" name="platform_codes[]" value="{{ $platformCode }}">
                                                @endforeach
                                                <button type="submit" class="inline-flex items-center gap-1 px-1 text-xs font-medium text-purple-700 hover:text-purple-800">
                                                    <i data-lucide="send-to-back" class="h-3.5 w-3.5"></i>
                                                    蚁小二交接
                                                </button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.geo.reports.article-drafts.convert', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800">
                                                    <i data-lucide="send" class="h-3.5 w-3.5"></i>
                                                    转为正式文章
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @if($latestAudit)
                                <div class="rounded-lg border border-blue-100 bg-blue-50/60 p-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900">发布前 GEO 检查</h4>
                                            <p class="mt-1 text-xs text-gray-600">最近检查：{{ $latestAudit->created_at?->format('Y-m-d H:i') }}</p>
                                        </div>
                                        <div class="text-2xl font-semibold text-blue-700">{{ (int) $latestAudit->score }}</div>
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach((array) $latestAudit->passed_checks as $check)
                                            <span class="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">{{ $auditCheckLabels[$check] ?? $check }}</span>
                                        @endforeach
                                        @foreach((array) $latestAudit->failed_checks as $check)
                                            <span class="rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">{{ $auditCheckLabels[$check] ?? $check }}</span>
                                        @endforeach
                                    </div>
                                    @if(!empty($latestAudit->suggestions))
                                        <ul class="mt-3 list-disc space-y-1 pl-5 text-xs leading-5 text-gray-600">
                                            @foreach((array) $latestAudit->suggestions as $suggestion)
                                                <li>{{ $suggestion }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endif
                            @if($latestPublishRecord)
                                <div class="rounded-lg border border-purple-100 bg-purple-50/60 p-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900">蚁小二交接</h4>
                                            <p class="mt-1 text-xs text-gray-600">状态：待蚁小二接管 · {{ $latestPublishRecord->created_at?->format('Y-m-d H:i') }}</p>
                                        </div>
                                        <div class="text-xs font-medium text-purple-700">{{ $latestPublishRecord->publishTarget?->name ?? '蚁小二' }}</div>
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach((array) $latestPublishRecord->platform_codes as $platformCode)
                                            <span class="rounded-lg bg-white px-2.5 py-1 text-xs font-medium text-purple-700">{{ $yixiaoerPlatformLabels[$platformCode] ?? $platformCode }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @if($latestRetest)
                                <div class="rounded-lg border border-emerald-100 bg-emerald-50/60 p-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900">发布后复测</h4>
                                            <p class="mt-1 text-xs text-gray-600">{{ $latestRetest->tested_at?->format('Y-m-d H:i') }} · {{ $latestRetest->summary }}</p>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-xs text-emerald-700">复测得分</div>
                                            <div class="text-2xl font-semibold text-emerald-700">{{ (int) $latestRetest->after_score }}</div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm leading-7 text-gray-700 whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit((string) $draft->content_markdown, 700) }}</div>
                        </article>
                    @endforeach
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-500">暂无文章草稿</div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
