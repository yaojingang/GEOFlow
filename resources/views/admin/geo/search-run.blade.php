@extends('admin.layouts.app')

@php
    $statusLabels = [
        'pending' => '待运行',
        'running' => '运行中',
        'completed' => '已完成',
        'partial_failed' => '部分失败',
        'failed' => '失败',
    ];
    $platformNames = $platformNames ?? [];
    $evidenceMetrics = $evidenceMetrics ?? [
        'answer_count' => 0,
        'brand_mentions' => 0,
        'brand_mention_rate' => 0,
        'keyword_hit_rate' => null,
        'target_keyword_hit_rate' => null,
        'previous_keyword_hit_rate' => null,
        'keyword_hit_rate_delta' => null,
        'citation_count' => 0,
        'citation_rate' => 0,
        'average_score' => null,
        'platform_count' => 0,
    ];
    $optimizationDirections = collect($run->optimization_directions ?? [])->filter();
    $keywordDeltaLabel = $evidenceMetrics['keyword_hit_rate_delta'] === null
        ? '—'
        : (($evidenceMetrics['keyword_hit_rate_delta'] > 0 ? '+' : '').(int) $evidenceMetrics['keyword_hit_rate_delta'].'%');
    $totalQuestions = max(0, (int) $run->total_questions);
    $processedQuestions = min($totalQuestions, (int) $run->completed_questions + (int) $run->failed_questions);
    $progressPercent = $totalQuestions > 0 ? (int) round($processedQuestions / $totalQuestions * 100) : 0;
    $progressClass = match ($run->status) {
        'completed' => 'bg-emerald-500',
        'running' => 'bg-blue-500',
        'partial_failed' => 'bg-amber-500',
        'failed' => 'bg-red-500',
        default => 'bg-gray-400',
    };
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('admin.geo.workspace') }}#external-qa" class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    返回外部问答检视
                </a>
                <p class="mt-4 text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">External QA Evidence</p>
                <h1 class="mt-2 text-2xl font-semibold text-gray-900">外部问答检视证据</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $run->name }} · {{ $organization->name }}</p>
            </div>
            <span class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700">
                <i data-lucide="activity" class="h-4 w-4"></i>
                {{ $statusLabels[$run->status] ?? $run->status }}
            </span>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-sm font-semibold text-gray-900">问题进度 {{ $processedQuestions }} / {{ $totalQuestions }} · {{ $progressPercent }}%</div>
                    <div class="mt-1 text-xs text-gray-500">成功 {{ (int) $run->completed_questions }} · 失败 {{ (int) $run->failed_questions }} · 平台 {{ (int) $evidenceMetrics['platform_count'] }}</div>
                </div>
                <div class="text-xs text-gray-500">
                    {{ $run->finished_at?->format('Y-m-d H:i') ?: '尚未完成' }}
                </div>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100">
                <div class="h-2 rounded-full {{ $progressClass }}" style="width: {{ $progressPercent }}%"></div>
            </div>
            @if ($run->error_message)
                <div class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $run->error_message }}</div>
            @endif
        </section>

        <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-gray-500">原始回答</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) $evidenceMetrics['answer_count'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-gray-500">目标命中率</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $evidenceMetrics['target_keyword_hit_rate'] !== null ? (int) $evidenceMetrics['target_keyword_hit_rate'].'%' : '—' }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-gray-500">关键词命中率</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $evidenceMetrics['keyword_hit_rate'] !== null ? (int) $evidenceMetrics['keyword_hit_rate'].'%' : '—' }}</div>
                <div class="mt-1 text-xs text-gray-500">上轮 {{ $evidenceMetrics['previous_keyword_hit_rate'] !== null ? (int) $evidenceMetrics['previous_keyword_hit_rate'].'%' : '—' }} · {{ $keywordDeltaLabel }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-gray-500">品牌命中</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) $evidenceMetrics['brand_mention_rate'] }}%</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-gray-500">引用率</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) $evidenceMetrics['citation_rate'] }}%</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-gray-500">平均可见度分</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $evidenceMetrics['average_score'] ?? '—' }}</div>
            </div>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-base font-semibold text-gray-900">创作文章优化方向</h2>
                <p class="mt-1 text-sm text-gray-500">根据检视名称和问题矩阵，给下一篇文章的选题、结构和关键词补齐建议。</p>
            </div>
            <div class="grid gap-3 p-5 md:grid-cols-3">
                @forelse ($optimizationDirections as $direction)
                    <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                        <div class="text-sm font-semibold text-gray-900">{{ $direction['title'] ?? '优化方向' }}</div>
                        <div class="mt-2 text-xs leading-5 text-gray-600">{{ $direction['body'] ?? '' }}</div>
                        @if (! empty($direction['keywords']))
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach (array_slice((array) $direction['keywords'], 0, 5) as $keyword)
                                    <span class="rounded-md bg-white px-2 py-1 text-xs text-gray-600 ring-1 ring-gray-200">{{ $keyword }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="md:col-span-3 rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center text-sm text-gray-500">
                        这个检视还没有生成文章优化方向。
                    </div>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-base font-semibold text-gray-900">问题与平台证据</h2>
                <p class="mt-1 text-sm text-gray-500">逐条保留问题、平台、原始回答、品牌命中、竞品和引用来源。</p>
            </div>

            <div class="divide-y divide-gray-100">
                @forelse ($run->questions as $question)
                    <div class="p-5">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div class="text-sm font-semibold text-gray-900">{{ $question->question }}</div>
                                <div class="mt-1 text-xs text-gray-500">意图：{{ $question->intent ?: '未标注' }}</div>
                            </div>
                            <span class="inline-flex shrink-0 rounded-lg bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">{{ $question->status }}</span>
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            @forelse ($question->answers as $answer)
                                @php
                                    $citations = collect((array) ($answer->source_urls ?: $answer->citations))->filter()->values();
                                    $competitors = collect((array) $answer->competitors_mentioned)->filter()->values();
                                @endphp
                                <article class="rounded-lg border border-gray-200 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900">{{ $platformNames[$answer->platform_code] ?? $answer->platform_code }}</div>
                                            <div class="mt-1 text-xs text-gray-500">{{ $answer->answered_at?->format('Y-m-d H:i') ?: '未执行' }}</div>
                                        </div>
                                        <span class="rounded-lg px-2.5 py-1 text-xs font-medium {{ $answer->brand_mentioned ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                            {{ $answer->brand_mentioned ? '品牌命中' : '未命中品牌' }}
                                        </span>
                                    </div>

                                    <div class="mt-3 grid gap-2 text-xs text-gray-600 sm:grid-cols-3">
                                        <div>可见度分 <span class="font-semibold text-gray-900">{{ (int) $answer->visibility_score }}</span></div>
                                        <div>引用 <span class="font-semibold text-gray-900">{{ $citations->count() }}</span></div>
                                        <div>竞品 <span class="font-semibold text-gray-900">{{ $competitors->count() }}</span></div>
                                    </div>

                                    @if ($competitors->isNotEmpty())
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @foreach ($competitors as $competitor)
                                                <span class="rounded-lg bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">{{ $competitor }}</span>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($citations->isNotEmpty())
                                        <div class="mt-3 space-y-1">
                                            @foreach ($citations as $citation)
                                                @if (filter_var($citation, FILTER_VALIDATE_URL))
                                                    <a href="{{ $citation }}" target="_blank" rel="noopener" class="block truncate text-xs font-medium text-blue-600 hover:text-blue-700">{{ $citation }}</a>
                                                @else
                                                    <span class="inline-flex max-w-full rounded-lg bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">{{ $citation }}</span>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="mt-4 rounded-lg bg-gray-50 p-3">
                                        <div class="mb-2 text-xs font-semibold text-gray-500">原始回答</div>
                                        <pre class="whitespace-pre-wrap break-words text-sm leading-6 text-gray-800">{{ $answer->raw_answer ?: $answer->error_message ?: '暂无回答' }}</pre>
                                    </div>
                                </article>
                            @empty
                                <div class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500">
                                    还没有平台回答，返回工作台运行搜索。
                                </div>
                            @endforelse
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-500">这个批次还没有问题。</div>
                @endforelse
            </div>
        </section>
    </div>
@endsection

@if ($run->status === 'running')
    @push('scripts')
        <script>
            window.setTimeout(() => window.location.reload(), 5000);
        </script>
    @endpush
@endif
