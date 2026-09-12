@extends('admin.layouts.app')

@php
    $filterData = $filters->toArray();
    $ai = $aiVisibilityOverview ?? [];
    $kpis = $ai['kpis'] ?? [];
    $polling = $ai['polling'] ?? [];
    $trend = $ai['trend'] ?? [];
    $definitionKeys = ['sampling', 'visibility', 'top1', 'top3', 'trend', 'sentiment', 'term_cloud', 'source', 'attention'];
    $sourceConfigRoute = auth('admin')->user()?->isSuperAdmin()
        ? route('admin.ai-source-providers.index')
        : route('admin.ai.configurator');
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.analytics._page-header', [
            'title' => __('admin.analytics.pages.ai_visibility.title'),
            'subtitle' => __('admin.analytics.pages.ai_visibility.subtitle'),
        ])

        <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm" data-ai-visibility-filters>
            <form method="GET" action="{{ route('admin.analytics.ai-visibility') }}" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap gap-2" aria-label="{{ __('admin.growth_center.ai_visibility.period_label', ['start' => $filterData['ai_date_from'], 'end' => $filterData['ai_date_to']]) }}">
                    @foreach (['14d', '30d', '60d', '90d'] as $preset)
                        <button type="submit" name="ai_preset" value="{{ $preset }}" class="inline-flex min-h-10 items-center rounded-md border px-3 text-sm font-semibold transition duration-[120ms] motion-reduce:transition-none active:scale-[.98] motion-reduce:active:scale-100 {{ $filters->preset === $preset ? 'border-violet-600 bg-violet-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-violet-300 hover:text-violet-700' }}" aria-pressed="{{ $filters->preset === $preset ? 'true' : 'false' }}">
                            {{ __('admin.analytics.filters.'.$preset) }}
                        </button>
                    @endforeach
                    </div>
                    <button type="button" class="text-sm font-medium text-gray-600 underline-offset-4 hover:text-violet-700 hover:underline" onclick="document.querySelectorAll('[data-ai-visibility-advanced]').forEach((element) => element.toggleAttribute('hidden'))">
                        {{ __('admin.growth_center.ai_visibility.advanced_filters') }}
                    </button>
                </div>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4" data-ai-visibility-advanced hidden>
                    <div>
                        <label for="ai-date-from" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.date_from') }}</label>
                        <input id="ai-date-from" type="date" name="ai_date_from" value="{{ $filterData['ai_date_from'] }}" max="{{ now()->toDateString() }}" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                    </div>
                    <div>
                        <label for="ai-date-to" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.date_to') }}</label>
                        <input id="ai-date-to" type="date" name="ai_date_to" value="{{ $filterData['ai_date_to'] }}" max="{{ now()->toDateString() }}" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                    </div>
                    <div>
                        <label for="ai-keyword" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.ai_visibility.keyword') }}</label>
                        <select id="ai-keyword" name="ai_keyword" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                            <option value="">{{ __('admin.analytics.filters.all') }}</option>
                            @foreach ($filterOptions['keywords'] as $keyword)
                                <option value="{{ $keyword }}" @selected($filters->keyword === $keyword)>{{ $keyword }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="ai-provider" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.ai_visibility.provider') }}</label>
                        <select id="ai-provider" name="ai_provider" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                            <option value="all">{{ __('admin.analytics.filters.all') }}</option>
                            @foreach ($filterOptions['providers'] as $provider)
                                <option value="{{ $provider }}" @selected($filters->provider === $provider)>{{ __('admin.analytics.ai_visibility.providers.'.$provider) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="ai-topic" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.ai_visibility.topic') }}</label>
                        <select id="ai-topic" name="ai_topic" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                            <option value="all">{{ __('admin.analytics.ai_visibility.topic_all') }}</option>
                            @foreach ($filterOptions['visibilityTopics'] as $visibilityTopic)
                                <option value="{{ $visibilityTopic->id }}" @selected((int) $filters->topicId === (int) $visibilityTopic->id)>{{ $visibilityTopic->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3" data-ai-visibility-advanced hidden>
                    <a href="{{ route('admin.analytics.ai-visibility') }}" class="text-sm font-medium text-gray-600 underline-offset-4 hover:text-violet-700 hover:underline">{{ __('admin.growth_center.ai_visibility.clear_filters') }}</a>
                    <button type="submit" name="ai_preset" value="custom" class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white transition duration-[120ms] motion-reduce:transition-none hover:bg-violet-700 active:scale-[.98] motion-reduce:active:scale-100">
                        <i data-lucide="filter" class="mr-2 h-4 w-4"></i>{{ __('admin.analytics.filters.apply') }}
                    </button>
                </div>
            </form>
        </section>

        <section class="mb-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm" data-ai-visibility-search-workspace>
            <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-600">Doubao Search</p>
                    <h2 class="mt-1 text-xl font-semibold text-gray-950">搜索获取结果前的设置</h2>
                    <p class="mt-1 text-sm text-gray-500">Global 适合快速浏览，Custom 适合精细筛选与竞品清洗。</p>
                </div>
                @if ($selectedRun)
                    <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>本次搜索已完成 {{ $selectedRun->sources->count() }} 条</span>
                @endif
            </div>
            <form method="POST" action="{{ route('admin.analytics.ai-visibility.search') }}" class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.25fr)]">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label for="doubao-query" class="mb-1 block text-sm font-semibold text-gray-700">搜索问题</label>
                        <input id="doubao-query" name="query" required maxlength="100" value="{{ old('query', $selectedRun?->keyword ?? '') }}" class="block min-h-11 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500" placeholder="例如：线上托福网课机构有哪些">
                    </div>
                    <div>
                        <label for="doubao-topic" class="mb-1 block text-sm font-semibold text-gray-700">{{ __('admin.analytics.ai_visibility.topic') }}</label>
                        <select id="doubao-topic" name="topic_id" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                            <option value="">{{ __('admin.analytics.ai_visibility.topic_uncategorized') }}</option>
                            @foreach ($filterOptions['visibilityTopics'] as $visibilityTopic)
                                <option value="{{ $visibilityTopic->id }}" @selected((string) old('topic_id', (string) $selectedRun?->ai_visibility_topic_id) === (string) $visibilityTopic->id)>{{ $visibilityTopic->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <fieldset>
                        <legend class="mb-2 text-sm font-semibold text-gray-700">搜索版本</legend>
                        <div class="grid grid-cols-2 gap-2" data-doubao-mode-tabs>
                            <label class="cursor-pointer rounded-md border border-violet-500 bg-violet-50 p-3"><input type="radio" name="mode" value="global" class="mr-2" checked data-doubao-mode>Global<span class="mt-1 block text-xs text-gray-500">10-20 条，摘要与图片数量</span></label>
                            <label class="cursor-pointer rounded-md border border-gray-200 p-3"><input type="radio" name="mode" value="custom" class="mr-2" data-doubao-mode>Custom<span class="mt-1 block text-xs text-gray-500">10-50 条，改写、时间与行业</span></label>
                        </div>
                    </fieldset>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label for="doubao-count" class="mb-1 block text-xs font-semibold text-gray-600">返回数量</label><input id="doubao-count" name="count" type="number" min="1" max="20" value="10" class="block min-h-10 w-full rounded-md border-gray-300 text-sm"></div>
                        <div><label for="doubao-auth" class="mb-1 block text-xs font-semibold text-gray-600">权威过滤</label><select id="doubao-auth" name="auth_info_level" class="block min-h-10 w-full rounded-md border-gray-300 text-sm"><option value="0">不限</option><option value="1">非常权威</option></select></div>
                    </div>
                    <div class="grid grid-cols-2 gap-3" data-doubao-global-only><div><label for="doubao-summary-length" class="mb-1 block text-xs font-semibold text-gray-600">摘要长度</label><input id="doubao-summary-length" name="summary_length" type="number" min="100" max="4000" value="800" class="block min-h-10 w-full rounded-md border-gray-300 text-sm"></div><div><label for="doubao-image-count" class="mb-1 block text-xs font-semibold text-gray-600">每条图片数量</label><input id="doubao-image-count" name="image_count" type="number" min="1" max="10" value="3" class="block min-h-10 w-full rounded-md border-gray-300 text-sm"></div></div>
                    <div class="flex flex-wrap gap-4 text-sm"><label class="inline-flex items-center gap-2"><input type="checkbox" name="query_rewrite" value="1" class="rounded border-gray-300 text-violet-600">开启 Query 改写</label><label class="inline-flex items-center gap-2"><input type="checkbox" name="need_summary" value="1" checked class="rounded border-gray-300 text-violet-600">返回摘要</label><label class="inline-flex items-center gap-2"><input type="checkbox" name="competitor_analysis" value="1" checked class="rounded border-gray-300 text-violet-600">搜索后用 DeepSeek 清洗竞品</label></div>
                    <div class="grid grid-cols-2 gap-3" data-doubao-custom-only>
                        <div><label for="doubao-time" class="mb-1 block text-xs font-semibold text-gray-600">时间范围</label><select id="doubao-time" name="time_range" class="block min-h-10 w-full rounded-md border-gray-300 text-sm"><option value="">不限</option><option value="OneDay">一天</option><option value="OneWeek">一周</option><option value="OneMonth">一个月</option><option value="OneYear">一年</option></select></div>
                        <div><label for="doubao-industry" class="mb-1 block text-xs font-semibold text-gray-600">垂直行业</label><select id="doubao-industry" name="industry" class="block min-h-10 w-full rounded-md border-gray-300 text-sm"><option value="">不限</option><option value="finance">金融</option><option value="game">游戏</option><option value="health">健康</option><option value="gov">政务</option></select></div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2" data-doubao-custom-only><div><label for="doubao-sites" class="mb-1 block text-xs font-semibold text-gray-600">信源白名单</label><textarea id="doubao-sites" name="sites" rows="2" class="block w-full rounded-md border-gray-300 text-sm" placeholder="每行或 | 分隔域名"></textarea></div><div><label for="doubao-block" class="mb-1 block text-xs font-semibold text-gray-600">站点黑名单</label><textarea id="doubao-block" name="block_hosts" rows="2" class="block w-full rounded-md border-gray-300 text-sm" placeholder="每行或 | 分隔域名"></textarea></div></div>
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-violet-600 px-5 text-sm font-semibold text-white hover:bg-violet-700"><i data-lucide="search" class="mr-2 h-4 w-4"></i>开始搜索</button>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-950 p-4 text-slate-100" data-doubao-results>
                    <div class="flex items-center justify-between gap-3"><div><h3 class="text-sm font-semibold">搜索结果</h3><p class="mt-1 text-xs text-slate-400">按信源渠道与权威等级筛选</p></div><div class="flex gap-2"><select id="result-site-filter" class="min-h-9 rounded-md border-slate-700 bg-slate-900 text-xs"><option value="">全部信源</option>@if($selectedRun) @foreach($selectedRun->sources->pluck('site_name')->filter()->unique() as $site)<option value="{{ $site }}">{{ $site }}</option>@endforeach @endif</select><select id="result-auth-filter" class="min-h-9 rounded-md border-slate-700 bg-slate-900 text-xs"><option value="">全部权威</option><option value="非常权威">非常权威</option><option value="正常权威">正常权威</option><option value="一般权威">一般权威</option></select></div></div>
                    <div class="mt-4 max-h-[28rem] space-y-3 overflow-y-auto pr-1" data-result-list>
                        @forelse (($selectedRun?->sources ?? collect()) as $source)
                            @php($authorityLabel = $source->metadata_json['authority_label'] ?? match((string) $source->authority_level){'1'=>'非常权威','2'=>'正常权威','3'=>'一般权威','4'=>'一般不权威',default=>'未知'})
                            <article class="rounded-md border border-slate-800 bg-slate-900 p-3" data-result-item data-site="{{ $source->site_name }}" data-authority="{{ $authorityLabel }}"><div class="flex items-start justify-between gap-3"><h4 class="text-sm font-semibold leading-5 text-white">{{ $source->title ?: '未命名结果' }}</h4><span class="shrink-0 rounded-full bg-slate-800 px-2 py-1 text-[11px] text-slate-300">{{ $authorityLabel }}</span></div><div class="mt-2 flex items-center justify-between gap-2 text-xs text-slate-400"><span>{{ $source->site_name ?: $source->domain }}</span><span>#{{ $source->rank }}</span></div><p class="mt-2 line-clamp-3 text-xs leading-5 text-slate-300">{{ $source->summary ?: $source->snippet }}</p>@if($source->url)<a href="{{ $source->url }}" target="_blank" rel="noreferrer" class="mt-2 inline-flex items-center text-xs font-semibold text-violet-300 hover:text-white"><i data-lucide="external-link" class="mr-1 h-3.5 w-3.5"></i>打开原文</a>@endif</article>
                        @empty
                            <p class="py-16 text-center text-sm text-slate-400">提交搜索后，这里会显示豆包返回的结果。</p>
                        @endforelse
                    </div>
                    @if ($selectedRun)
                        @php($resultJson = $selectedRun->raw_response_json ?: ['run_id' => $selectedRun->id, 'sources' => $selectedRun->sources->toArray()])
                        <div class="mt-4 flex flex-wrap gap-2"><button type="button" data-json-action="copy" class="inline-flex min-h-9 items-center rounded-md border border-slate-700 px-3 text-xs font-semibold hover:bg-slate-800"><i data-lucide="copy" class="mr-1.5 h-3.5 w-3.5"></i>复制 JSON</button><button type="button" data-json-action="download" class="inline-flex min-h-9 items-center rounded-md border border-slate-700 px-3 text-xs font-semibold hover:bg-slate-800"><i data-lucide="download" class="mr-1.5 h-3.5 w-3.5"></i>下载 JSON</button><button type="button" data-json-action="toggle" class="inline-flex min-h-9 items-center rounded-md border border-slate-700 px-3 text-xs font-semibold hover:bg-slate-800">预览 JSON</button></div><pre data-json-preview hidden class="mt-3 max-h-64 overflow-auto rounded-md bg-slate-900 p-3 text-[11px] leading-5 text-slate-300">{{ json_encode($resultJson, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif
                </div>
            </form>
            @if ($selectedRun)
                <div class="mt-4 border-t border-slate-200 pt-4" data-ai-visibility-topic-assignment>
                    @if ($selectedRun->ai_visibility_topic_id)
                        <p class="text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.topic') }}：<span class="inline-flex items-center rounded-full bg-violet-50 px-2 py-1 text-xs font-semibold text-violet-700">{{ $selectedRun->topic?->name }}</span></p>
                    @elseif ($filterOptions['visibilityTopics']->isNotEmpty())
                        <form method="POST" action="{{ route('admin.analytics.ai-visibility.assign-topic') }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <input type="hidden" name="run_id" value="{{ $selectedRun->id }}">
                            <div>
                                <label for="assign-topic" class="mb-1 block text-sm font-semibold text-gray-700">{{ __('admin.analytics.ai_visibility.topic') }}</label>
                                <select id="assign-topic" name="topic_id" class="block min-h-10 rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                                    @foreach ($filterOptions['visibilityTopics'] as $visibilityTopic)
                                        <option value="{{ $visibilityTopic->id }}">{{ $visibilityTopic->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white hover:bg-violet-700">{{ __('admin.analytics.ai_visibility.assign_topic') }}</button>
                        </form>
                    @endif
                </div>
            @endif
        </section>

        @if (! ($ai['configured'] ?? false) && auth('admin')->user()?->isSuperAdmin())
            <section class="rounded-lg border border-violet-200 bg-violet-50 p-6" data-ai-visibility-setup-entry>
                <h2 class="text-lg font-semibold text-violet-950">{{ __('admin.growth_center.ai_visibility.setup_entry_title') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-violet-800">{{ __('admin.growth_center.ai_visibility.setup_entry_desc') }}</p>
                <a href="{{ route('admin.ai-source-providers.index') }}" class="mt-4 inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white hover:bg-violet-700">{{ __('admin.growth_center.ai_visibility.setup_entry_action') }}</a>
            </section>
        @elseif (! ($ai['ready'] ?? false))
            <section class="rounded-lg border border-gray-200 bg-white p-10 text-center shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.not_ready_title') }}</h2>
                <p class="mt-2 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.not_ready_desc') }}</p>
            </section>
        @else
            <section>
                <div class="mb-5 rounded-lg border border-violet-100 bg-violet-50/60 p-5" data-ai-visibility-status>
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-700">{{ __('admin.growth_center.ai_visibility.status_title') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-3">
                                <span class="inline-flex items-center gap-2 text-sm font-semibold text-gray-900"><span class="h-2.5 w-2.5 rounded-full {{ ($polling['sampled_runs'] ?? 0) > 0 ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>{{ ($polling['sampled_runs'] ?? 0) > 0 ? __('admin.growth_center.ai_visibility.status_ready') : __('admin.growth_center.ai_visibility.status_empty') }}</span>
                                <span class="text-sm text-gray-600">{{ __('admin.growth_center.ai_visibility.period_label', ['start' => $ai['period']['start'] ?? '', 'end' => $ai['period']['end'] ?? '']) }}</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm text-gray-600">
                                <span><strong class="font-mono tabular-nums text-gray-900">{{ number_format((int) ($polling['sampled_runs'] ?? 0)) }}</strong> {{ __('admin.growth_center.ai_visibility.valid_samples') }}</span>
                                <span><strong class="font-mono tabular-nums text-gray-900">{{ number_format((int) ($polling['runs'] ?? 0)) }}</strong> {{ __('admin.growth_center.ai_visibility.raw_polls') }}</span>
                                <span>{{ __('admin.growth_center.ai_visibility.last_updated') }}：<strong class="font-mono tabular-nums text-gray-900">{{ $polling['latest_completed_at'] ? \Illuminate\Support\Carbon::parse($polling['latest_completed_at'])->format('Y-m-d H:i') : __('admin.growth_center.ai_visibility.never_updated') }}</strong></span>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <a href="{{ $sourceConfigRoute }}" class="inline-flex min-h-10 items-center rounded-md border border-violet-200 bg-white px-4 text-sm font-semibold text-violet-800 hover:border-violet-400 hover:bg-violet-50"><i data-lucide="settings-2" class="mr-2 h-4 w-4"></i>{{ __('admin.growth_center.ai_visibility.configure_action') }}</a>
                            <a href="{{ route('admin.articles.index') }}" class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white hover:bg-violet-700"><i data-lucide="file-plus-2" class="mr-2 h-4 w-4"></i>{{ __('admin.growth_center.ai_visibility.content_action') }}</a>
                        </div>
                    </div>
                </div>
                <div class="mb-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-600">{{ __('admin.growth_center.ai_visibility.eyebrow') }}</p>
                    <h2 class="mt-1 text-xl font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.title') }}</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.growth_center.ai_visibility.desc', ['count' => $ai['daily_sample_target'] ?? 5]) }}</p>
                </div>

                @if (($polling['sampled_runs'] ?? 0) === 0)
                    <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center" data-ai-visibility-empty>
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-violet-50 text-violet-700"><i data-lucide="radar" class="h-6 w-6"></i></div>
                        <h3 class="mt-4 text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.empty_title') }}</h3>
                        <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-gray-600">{{ __('admin.growth_center.ai_visibility.empty_desc') }}</p>
                        <a href="{{ $sourceConfigRoute }}" class="mt-5 inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white hover:bg-violet-700"><i data-lucide="settings-2" class="mr-2 h-4 w-4"></i>{{ __('admin.growth_center.ai_visibility.configure_action') }}</a>
                    </div>
                @else

                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    @foreach ([
                        ['key' => 'brand_visibility', 'label' => 'visibility', 'tone' => 'text-violet-700'],
                        ['key' => 'top1_rate', 'label' => 'top1', 'tone' => 'text-amber-700'],
                        ['key' => 'top3_rate', 'label' => 'top3', 'tone' => 'text-emerald-700'],
                        ['key' => 'sentiment_score', 'label' => 'sentiment', 'tone' => 'text-slate-700'],
                    ] as $card)
                        <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.kpi.'.$card['label']) }}</p>
                            <p class="mt-3 text-right font-mono text-3xl font-semibold tabular-nums {{ $card['tone'] }}">{{ number_format((float) ($kpis[$card['key']] ?? 0), 1) }}{{ $card['key'] === 'sentiment_score' ? '' : '%' }}</p>
                            <p class="mt-2 text-right text-xs text-gray-500">{{ __('admin.growth_center.ai_visibility.sample_basis', ['count' => (int) ($polling['sampled_runs'] ?? 0)]) }}</p>
                        </article>
                    @endforeach
                </div>

                <div class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.trend_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.trend_desc') }}</p>
                        </div>
                    </div>
                    <div class="mt-4">
                        @include('admin.analytics._interactive-trend', [
                            'series' => $trend,
                            'chartLabel' => __('admin.growth_center.ai_visibility.trend_title'),
                            'metrics' => [
                                ['key' => 'visibility', 'label' => __('admin.growth_center.ai_visibility.kpi.visibility'), 'color' => '#7c3aed', 'decimals' => 1, 'suffix' => '%'],
                                ['key' => 'top1', 'label' => __('admin.growth_center.ai_visibility.kpi.top1'), 'color' => '#d97706', 'decimals' => 1, 'suffix' => '%'],
                                ['key' => 'top3', 'label' => __('admin.growth_center.ai_visibility.kpi.top3'), 'color' => '#059669', 'decimals' => 1, 'suffix' => '%'],
                            ],
                        ])
                        @php($maxSamples = max(1, (int) collect($trend)->max('samples')))
                        <div class="mt-4 rounded-lg border border-gray-100 bg-gray-50 p-4" aria-label="{{ __('admin.growth_center.ai_visibility.sample_volume_title') }}">
                            <div class="flex items-center justify-between gap-3 text-xs text-gray-500"><span class="font-medium text-gray-700">{{ __('admin.growth_center.ai_visibility.sample_volume_title') }}</span><span class="font-mono tabular-nums">{{ __('admin.growth_center.ai_visibility.polling_summary', ['sampled' => $polling['sampled_runs'] ?? 0, 'runs' => $polling['runs'] ?? 0, 'rate' => number_format((float) ($polling['success_rate'] ?? 0), 1)]) }}</span></div>
                            <div class="mt-3 flex h-10 items-end gap-1" role="img" aria-label="{{ __('admin.growth_center.ai_visibility.sample_volume_title') }}">
                                @foreach (collect($trend)->take(30) as $point)
                                    <span class="min-w-1 flex-1 rounded-t-sm bg-slate-300" style="height: {{ max(4, (int) round(((int) ($point['samples'] ?? 0) / $maxSamples) * 100)) }}%" title="{{ $point['date'] }}：{{ $point['samples'] }}"></span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <details class="mt-6 rounded-lg border border-gray-200 bg-white shadow-sm" data-ai-visibility-metric-definitions>
                    <summary class="flex min-h-10 cursor-pointer items-center px-5 py-3 text-sm font-semibold text-gray-800" data-ai-visibility-metric-toggle>{{ __('admin.growth_center.ai_visibility.definition_toggle') }}</summary>
                    <div class="border-t border-gray-100 p-5">
                        <p class="text-sm leading-6 text-gray-600">{{ __('admin.growth_center.ai_visibility.definition_intro') }}</p>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            @foreach ($definitionKeys as $key)
                                <article class="rounded-lg bg-gray-50 p-4" data-ai-visibility-definition-item>
                                    <h4 class="text-sm font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.definition.'.$key.'_title') }}</h4>
                                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.growth_center.ai_visibility.definition.'.$key.'_body', ['count' => $ai['daily_sample_target'] ?? 5]) }}</p>
                                </article>
                            @endforeach
                        </div>
                    </div>
                </details>

                <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm" data-ai-visibility-section="topics">
                        <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.term_cloud_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.term_cloud_desc') }}</p>
                        <div class="mt-5 flex min-h-36 flex-wrap content-center items-center justify-center gap-x-4 gap-y-3">
                            @forelse (($ai['terms'] ?? []) as $term)
                                <span class="inline-flex items-center gap-2 rounded-md bg-violet-50 px-3 py-2 text-sm font-medium text-violet-800"><span>{{ $term['term'] }}</span><span class="font-mono text-xs tabular-nums text-violet-500">{{ $term['weight'] }}</span></span>
                            @empty
                                <p class="text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.no_terms') }}</p>
                            @endforelse
                        </div>
                    </section>
                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm" data-ai-visibility-section="attention">
                        <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.attention_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.attention_desc') }}</p>
                        <div class="mt-4 space-y-3">
                            @forelse (($ai['attention_sources'] ?? []) as $source)
                                <article class="rounded-lg border border-amber-100 bg-amber-50 p-4">
                                    <div class="flex items-center justify-between gap-3"><strong class="truncate text-sm text-gray-900">{{ $source['domain'] }}</strong><span class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-amber-700">{{ __('admin.growth_center.ai_visibility.action.'.$source['action']) }}</span></div>
                                    <p class="mt-2 text-sm leading-6 text-amber-900">{{ __('admin.growth_center.ai_visibility.recommendation.'.$source['action']) }}</p>
                                    @if (!empty($source['latest_url']))
                                        <a href="{{ $source['latest_url'] }}" target="_blank" rel="noreferrer" class="mt-3 inline-flex min-h-9 items-center text-sm font-semibold text-amber-800 underline-offset-4 hover:underline"><i data-lucide="external-link" class="mr-2 h-4 w-4"></i>{{ __('admin.growth_center.ai_visibility.view_evidence') }}</a>
                                    @endif
                                </article>
                            @empty
                                <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.no_attention_sources') }}</p>
                            @endforelse
                        </div>
                    </section>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm" data-ai-visibility-section="keywords">
                        <div class="p-5"><h3 class="text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.keyword_title') }}</h3><p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.keyword_desc') }}</p></div>
                        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50"><tr><th class="px-5 py-3 text-left">{{ __('admin.growth_center.ai_visibility.table.keyword') }}</th><th class="px-5 py-3 text-right">{{ __('admin.growth_center.ai_visibility.table.samples') }}</th><th class="px-5 py-3 text-right">{{ __('admin.growth_center.ai_visibility.table.visibility') }}</th><th class="px-5 py-3 text-right">{{ __('admin.growth_center.ai_visibility.table.top3') }}</th></tr></thead><tbody class="divide-y divide-gray-100">
                            @forelse (($ai['keywords'] ?? []) as $row)<tr><td class="px-5 py-3 font-medium text-gray-900"><a href="{{ request()->fullUrlWithQuery(['ai_preset' => 'custom', 'ai_keyword' => $row['keyword']]) }}" class="underline-offset-4 hover:text-violet-700 hover:underline">{{ $row['keyword'] }}</a></td><td class="px-5 py-3 text-right font-mono tabular-nums">{{ $row['samples'] }}</td><td class="px-5 py-3 text-right font-mono tabular-nums">{{ number_format($row['brand_visibility'], 1) }}%</td><td class="px-5 py-3 text-right font-mono tabular-nums">{{ number_format($row['top3_rate'], 1) }}%</td></tr>@empty<tr><td colspan="4" class="px-5 py-8 text-center text-gray-500">{{ __('admin.growth_center.ai_visibility.no_keywords') }}</td></tr>@endforelse
                        </tbody></table></div>
                    </section>
                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm" data-ai-visibility-section="sources">
                        <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.source_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.source_desc') }}</p>
                        <div class="mt-4 space-y-3">
                            @forelse (($ai['sources'] ?? []) as $source)
                                <article class="rounded-lg border border-gray-100 p-4"><div class="flex items-center justify-between gap-3"><strong class="truncate text-sm text-gray-900">{{ $source['domain'] }}</strong><span class="font-mono text-sm tabular-nums text-gray-600">{{ __('admin.growth_center.ai_visibility.source_mentions', ['count' => $source['mentions']]) }}</span></div><div class="mt-2 flex gap-4 text-xs text-gray-500"><span>{{ __('admin.growth_center.ai_visibility.source_avg_rank', ['rank' => $source['avg_rank']]) }}</span><span>{{ __('admin.growth_center.ai_visibility.source_brand_coverage', ['rate' => number_format($source['brand_coverage'], 1)]) }}</span></div></article>
                            @empty
                                <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.no_sources') }}</p>
                            @endforelse
                        </div>
                    </section>
                </div>

                <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm" data-ai-visibility-section="samples">
                    <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.ai_visibility.recent_samples') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.sample_evidence_desc') }}</p>
                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        @forelse (($ai['latest_runs'] ?? []) as $run)
                            <details class="rounded-lg border border-gray-100 p-4">
                                <summary class="cursor-pointer list-none">
                                <p class="truncate text-sm font-semibold text-gray-900">{{ $run['keyword'] }}</p>
                                <p class="mt-1 truncate text-xs text-gray-500">{{ __('admin.analytics.ai_visibility.providers.'.$run['provider_type']) }}</p>
                                <div class="mt-3 flex items-center justify-between gap-2"><time class="font-mono text-xs tabular-nums text-gray-400">{{ $run['date'] }}</time><span class="rounded-full px-2 py-1 text-xs font-medium {{ $run['brand_visible'] ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">{{ $run['brand_visible'] ? __('admin.analytics.ai_visibility.visible') : __('admin.analytics.ai_visibility.not_visible') }}</span></div>
                                </summary>
                                <div class="mt-3 border-t border-gray-100 pt-3 text-sm text-gray-600">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.growth_center.ai_visibility.answer_excerpt') }}</p>
                                    <p class="mt-1 leading-6">{{ $run['answer_excerpt'] ?: __('admin.growth_center.ai_visibility.no_brand_rank') }}</p>
                                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                        <span>{{ __('admin.growth_center.ai_visibility.source_count', ['count' => $run['source_count']]) }}</span>
                                        <span>{{ $run['best_brand_rank'] ? __('admin.growth_center.ai_visibility.best_rank', ['rank' => $run['best_brand_rank']]) : __('admin.growth_center.ai_visibility.no_brand_rank') }}</span>
                                    </div>
                                </div>
                            </details>
                        @empty
                            <p class="text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</p>
                        @endforelse
                    </div>
                </section>
                <section class="mt-6 grid gap-6 xl:grid-cols-2" data-ai-visibility-evidence>
                    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"><h3 class="text-lg font-semibold text-gray-950">信源分布</h3><p class="mt-1 text-sm text-gray-500">统计每个信源渠道被收录的结果数量。</p><div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead class="border-b border-gray-100 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">信源</th><th class="px-3 py-2 text-right">收录数量</th><th class="px-3 py-2">主要权威度</th><th class="px-3 py-2">{{ __('admin.analytics.ai_visibility.topic') }}</th></tr></thead><tbody class="divide-y divide-gray-100">@forelse(($ai['source_distribution'] ?? []) as $row)<tr><td class="px-3 py-3 font-medium text-gray-900">{{ $row['name'] }}</td><td class="px-3 py-3 text-right font-mono tabular-nums">{{ $row['count'] }}</td><td class="px-3 py-3 text-gray-600">{{ $row['top_authority'] ?: '未标注' }}</td><td class="px-3 py-3 text-gray-600">{{ $row['topic'] ?? __('admin.analytics.ai_visibility.uncategorized') }}</td></tr>@empty<tr><td colspan="4" class="px-3 py-8 text-center text-gray-500">暂无信源分布数据</td></tr>@endforelse</tbody></table></div></div>
                    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"><h3 class="text-lg font-semibold text-gray-950">竞品同行清洗</h3><p class="mt-1 text-sm text-gray-500">DeepSeek 仅展示可回溯到原始搜索结果的证据。</p><div class="mt-4 space-y-4">@forelse(($ai['competitors'] ?? []) as $competitor)<article class="rounded-md border border-gray-200 p-4"><div class="flex items-center justify-between gap-3"><h4 class="font-semibold text-gray-900">{{ $competitor['name'] }}</h4>@if(($competitor['mention_count'] ?? 0) > 0)<span class="rounded-full bg-violet-50 px-2 py-1 text-xs font-semibold text-violet-700">{{ $competitor['mention_count'] }} 条证据</span>@else<span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">待复核</span>@endif<span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-600">{{ $competitor['topic'] ?? __('admin.analytics.ai_visibility.uncategorized') }}</span></div><div class="mt-3 max-h-56 space-y-2 overflow-y-auto pr-1">@foreach($competitor['evidence'] as $evidence)<a href="{{ $evidence['url'] }}" target="_blank" rel="noreferrer" class="group block rounded-md border border-gray-100 bg-gray-50 p-3 hover:border-violet-200 hover:bg-violet-50"><div class="flex items-start justify-between gap-3"><span class="line-clamp-2 text-sm font-medium text-gray-900 group-hover:text-violet-800">{{ $evidence['title'] ?: $evidence['domain'] }}</span><i data-lucide="arrow-up-right" class="mt-0.5 h-4 w-4 shrink-0 text-gray-400 group-hover:text-violet-600"></i></div><div class="mt-1 flex flex-wrap gap-x-3 text-xs text-gray-500"><span>{{ $evidence['site_name'] ?: $evidence['domain'] }}</span><span>{{ $evidence['authority_label'] ?: '未标注权威' }}</span><span>排名 #{{ $evidence['rank'] ?: '-' }}</span></div></a>@endforeach @if(($competitor['mention_count'] ?? 0) === 0)<p class="rounded-md bg-amber-50 p-3 text-xs text-amber-700">DeepSeek 提到该竞品但未能回溯到原始搜索结果链接，请人工核实后再采信。</p>@endif</div></article>@empty<p class="rounded-md bg-gray-50 p-4 text-sm text-gray-500">暂无结构化竞品证据，完成一次 DeepSeek 清洗后显示。</p>@endforelse</div></div>
                </section>
                @endif
            </section>
        @endif
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modes = [...document.querySelectorAll('[data-doubao-mode]')]; const custom = [...document.querySelectorAll('[data-doubao-custom-only]')]; const global = [...document.querySelectorAll('[data-doubao-global-only]')]; const count = document.querySelector('#doubao-count');
  const sync = () => { const isCustom = document.querySelector('[data-doubao-mode]:checked')?.value === 'custom'; custom.forEach((el) => el.hidden = !isCustom); global.forEach((el) => el.hidden = isCustom); if (count) count.max = isCustom ? 50 : 20; if (count && Number(count.value) > Number(count.max)) count.value = count.max; };
  modes.forEach((mode) => mode.addEventListener('change', sync)); sync();
  const filter = () => { const site = document.querySelector('#result-site-filter')?.value || ''; const authority = document.querySelector('#result-auth-filter')?.value || ''; document.querySelectorAll('[data-result-item]').forEach((item) => { item.hidden = (site && item.dataset.site !== site) || (authority && item.dataset.authority !== authority); }); };
  document.querySelectorAll('#result-site-filter,#result-auth-filter').forEach((el) => el.addEventListener('change', filter));
  const preview = document.querySelector('[data-json-preview]'); document.querySelectorAll('[data-json-action]').forEach((button) => button.addEventListener('click', async () => { const action = button.dataset.jsonAction; const value = preview?.textContent || ''; if (action === 'toggle' && preview) preview.hidden = !preview.hidden; if (action === 'copy' && navigator.clipboard) await navigator.clipboard.writeText(value); if (action === 'download') { const blob = new Blob([value], {type: 'application/json'}); const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = 'doubao-search-result.json'; link.click(); URL.revokeObjectURL(link.href); } }));
});
</script>
@endpush
