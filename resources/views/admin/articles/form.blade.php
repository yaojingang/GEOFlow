@extends('admin.layouts.app')

@php
    $i18nRoot = $isEdit ? 'admin.article_edit' : 'admin.article_create';
    $formAction = $isEdit
        ? route('admin.articles.update', ['articleId' => (int) $articleId])
        : route('admin.articles.store');
    $articleImageUploadUrl = $isEdit
        ? route('admin.articles.editor.images.upload', ['articleId' => (int) $articleId], false)
        : '';
    $articleWechatHtmlUrl = route('admin.articles.editor.wechat-html', [], false);
    $vditorLocaleMap = [
        'zh_CN' => 'zh_CN',
        'zh-CN' => 'zh_CN',
        'en' => 'en_US',
        'en_US' => 'en_US',
    ];
    $vditorLang = $vditorLocaleMap[str_replace('-', '_', app()->getLocale())] ?? 'en_US';
    $editorQuickActions = [
        ['key' => 'image', 'icon' => 'image', 'label' => __('admin.article_editor.quick_actions.image')],
        ['key' => 'heading', 'icon' => 'heading-2', 'label' => __('admin.article_editor.quick_actions.heading')],
        ['key' => 'quote', 'icon' => 'quote', 'label' => __('admin.article_editor.quick_actions.quote')],
        ['key' => 'list', 'icon' => 'list', 'label' => __('admin.article_editor.quick_actions.list')],
        ['key' => 'divider', 'icon' => 'minus', 'label' => __('admin.article_editor.quick_actions.divider')],
    ];

    $formData = [
        'title' => old('title', (string) ($articleForm['title'] ?? '')),
        'excerpt' => old('excerpt', (string) ($articleForm['excerpt'] ?? '')),
        'content' => old('content', (string) ($articleForm['content'] ?? '')),
        'keywords' => old('keywords', (string) ($articleForm['keywords'] ?? '')),
        'meta_description' => old('meta_description', (string) ($articleForm['meta_description'] ?? '')),
        'status' => old('status', (string) ($articleForm['status'] ?? 'draft')),
        'review_status' => old('review_status', (string) ($articleForm['review_status'] ?? 'pending')),
        'category_id' => old('category_id', (string) ($articleForm['category_id'] ?? '')),
        'author_id' => old('author_id', (string) ($articleForm['author_id'] ?? '')),
        'slug' => (string) ($articleForm['slug'] ?? ''),
        'published_at' => (string) ($articleForm['published_at'] ?? ''),
        'task_name' => (string) ($articleForm['task_name'] ?? ''),
        'is_hot' => old('is_hot', !empty($articleForm['is_hot']) ? '1' : '0'),
        'is_featured' => old('is_featured', !empty($articleForm['is_featured']) ? '1' : '0'),
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center space-x-4 mb-6">
            <a href="{{ route('admin.articles.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __($i18nRoot.'.page_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">
                    @if($isEdit)
                        {{ $formData['title'] }}
                    @else
                        {{ __($i18nRoot.'.page_subtitle') }}
                    @endif
                </p>
            </div>
        </div>

        <form method="POST" action="{{ $formAction }}" class="space-y-8">
            @csrf
            @if($isEdit)
                @method('PUT')
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <div class="lg:col-span-3 space-y-6">
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.basic_title') }}</h3>
                        </div>
                        <div class="px-6 py-4 space-y-6">
                            <div>
                                <label for="title" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.title') }} *</label>
                                <input id="title" type="text" name="title" required value="{{ $formData['title'] }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __($i18nRoot.'.placeholder.title') }}">
                            </div>
                            <div>
                                <label for="excerpt" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.excerpt') }}</label>
                                <textarea id="excerpt" name="excerpt" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __($i18nRoot.'.placeholder.excerpt') }}">{{ $formData['excerpt'] }}</textarea>
                            </div>
                        </div>
                    </div>

                    @if($isEdit)
                        @php
                            $quality = is_array($qualityReport ?? null) ? $qualityReport : [];
                            $qualityScore = (int) ($quality['score'] ?? 0);
                            $qualityStatus = (string) ($quality['status'] ?? 'poor');
                            $qualityTone = match($qualityStatus) {
                                'good' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                                'warning' => 'bg-amber-50 text-amber-700 ring-amber-100',
                                default => 'bg-red-50 text-red-700 ring-red-100',
                            };
                            $qualityItems = collect($quality['items'] ?? [])->filter(fn ($item) => is_array($item))->values();
                            $qualityIssues = collect($quality['issues'] ?? [])->filter(fn ($issue) => is_array($issue))->values()->groupBy(fn ($issue) => (string) ($issue['key'] ?? 'structure'));
                        @endphp
                        @if($quality !== [])
                            <div class="bg-white shadow rounded-lg">
                                <div class="px-6 py-4 border-b border-gray-200">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-lg font-medium text-gray-900">{{ __('admin.article_edit.quality.title') }}</h3>
                                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_edit.quality.subtitle') }}</p>
                                        </div>
                                        <span class="inline-flex shrink-0 items-center rounded-full px-3 py-1 text-sm font-semibold ring-1 {{ $qualityTone }}">
                                            {{ __('admin.articles.quality.score_label', ['score' => $qualityScore, 'grade' => (string) ($quality['grade'] ?? '-')]) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="px-6 py-4 space-y-5 text-sm">
                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                                        <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                                            <div class="text-xs font-medium text-gray-400">{{ __('admin.article_edit.info.article_id') }}</div>
                                            <div class="mt-1 font-semibold text-gray-900">#{{ (int) $articleId }}</div>
                                        </div>
                                        <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                                            <div class="text-xs font-medium text-gray-400">{{ __('admin.article_edit.info.slug') }}</div>
                                            <div class="mt-1 truncate font-semibold text-gray-900">{{ $formData['slug'] !== '' ? $formData['slug'] : '-' }}</div>
                                        </div>
                                        <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                                            <div class="text-xs font-medium text-gray-400">{{ __('admin.article_edit.info.published_at') }}</div>
                                            <div class="mt-1 font-semibold text-gray-900">{{ $formData['published_at'] !== '' ? $formData['published_at'] : '-' }}</div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                        @foreach($qualityItems as $item)
                                            @php
                                                $itemKey = (string) ($item['key'] ?? 'structure');
                                                $itemStatus = (string) ($item['status'] ?? 'warning');
                                                $itemTone = match($itemStatus) {
                                                    'passed' => 'bg-emerald-50 text-emerald-700',
                                                    'warning' => 'bg-amber-50 text-amber-700',
                                                    default => 'bg-red-50 text-red-700',
                                                };
                                                $itemIssues = $qualityIssues->get($itemKey, collect());
                                            @endphp
                                            <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-3">
                                                <div class="flex items-center justify-between gap-3">
                                                    <div class="font-medium text-gray-800">{{ __('admin.articles.quality.items.'.$itemKey) }}</div>
                                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $itemTone }}">
                                                        {{ (int) ($item['score'] ?? 0) }}/{{ (int) ($item['max'] ?? 0) }}
                                                    </span>
                                                </div>
                                                <div class="mt-1 text-xs text-gray-500">{{ __('admin.articles.quality.status.'.(string) ($item['status'] ?? 'warning')) }} · {{ (string) ($item['detail'] ?? '') }}</div>
                                                @if($itemIssues->isNotEmpty())
                                                    <div class="mt-3 space-y-2">
                                                        @foreach($itemIssues as $issue)
                                                            <div class="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800">
                                                                {{ __((string) ($issue['message_key'] ?? 'admin.articles.quality.suggestions.structure')) }}
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                    @if($qualityIssues->flatten(1)->isEmpty())
                                        <div class="rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                                            {{ __('admin.article_edit.quality.no_issues') }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endif

                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.content_title') }}</h3>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">{{ __($i18nRoot.'.help.markdown_supported') }}</span>
                                    <button
                                        type="button"
                                        id="article-editor-copy-markdown"
                                        class="inline-flex items-center rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                    >
                                        <i data-lucide="copy" class="mr-1.5 h-4 w-4"></i>
                                        {{ __('admin.article_editor.copy.button') }}
                                    </button>
                                    <button
                                        type="button"
                                        id="article-editor-copy-wechat-html"
                                        class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 shadow-sm hover:border-emerald-300 hover:bg-emerald-100 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <i data-lucide="copy-check" class="mr-1.5 h-4 w-4"></i>
                                        {{ __('admin.article_editor.wechat.button') }}
                                    </button>
                                </div>
                            </div>
                            <p class="mt-2 text-sm text-gray-600">{{ __('admin.article_editor.editor_desc') }}</p>
                        </div>
                        <div class="px-6 py-4">
                            <textarea id="content-textarea" name="content" required class="hidden">{{ $formData['content'] }}</textarea>
                            <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                                <span class="mr-1 text-xs font-medium text-gray-500">{{ __('admin.article_editor.quick_actions.title') }}</span>
                                @foreach($editorQuickActions as $quickAction)
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-md border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-700 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                                        data-editor-action="{{ $quickAction['key'] }}"
                                    >
                                        <i data-lucide="{{ $quickAction['icon'] }}" class="mr-1.5 h-4 w-4"></i>
                                        {{ $quickAction['label'] }}
                                    </button>
                                @endforeach
                                <span class="ml-auto text-xs text-gray-500">{{ __('admin.article_editor.message.context_tip') }}</span>
                            </div>
                            <div
                                id="content-editor"
                                class="article-markdown-editor"
                                data-upload-url="{{ $articleImageUploadUrl }}"
                                data-upload-enabled="{{ $isEdit ? '1' : '0' }}"
                                data-wechat-html-url="{{ $articleWechatHtmlUrl }}"
                                data-vditor-lang="{{ $vditorLang }}"
                            ></div>
                            <input id="article-editor-quick-image-input" type="file" accept="image/*" class="hidden">
                            <div id="article-editor-context-menu" class="article-editor-context-menu" hidden>
                                <div class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.article_editor.quick_actions.context_title') }}</div>
                                @foreach($editorQuickActions as $quickAction)
                                    <button type="button" data-editor-action="{{ $quickAction['key'] }}">
                                        <i data-lucide="{{ $quickAction['icon'] }}" class="h-4 w-4"></i>
                                        <span>{{ $quickAction['label'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                            <div class="mt-3 grid gap-2 text-xs text-gray-500 sm:grid-cols-3">
                                <div class="rounded-md bg-gray-50 px-3 py-2">{{ __('admin.article_editor.help.markdown') }}</div>
                                <div class="rounded-md bg-gray-50 px-3 py-2">{{ __('admin.article_editor.help.image') }}</div>
                                <div class="rounded-md bg-gray-50 px-3 py-2">{{ __('admin.article_editor.help.copy') }}</div>
                            </div>
                        </div>
                    </div>

                    @if($isEdit)
                        @php
                            $linkSuggestions = collect($internalLinkSuggestions ?? [])->filter(fn ($item) => is_array($item))->values();
                            $linkRecords = collect($internalLinkRecords ?? []);
                        @endphp
                        <div class="bg-white shadow rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.article_edit.internal_links.title') }}</h3>
                                        <p class="mt-1 text-xs leading-5 text-gray-500">{{ __('admin.article_edit.internal_links.subtitle') }}</p>
                                    </div>
                                    <form method="POST" action="{{ route('admin.articles.internal-links.refresh', ['articleId' => (int) $articleId]) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            <i data-lucide="refresh-cw" class="mr-1.5 h-3.5 w-3.5"></i>
                                            {{ __('admin.article_edit.internal_links.refresh') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="px-6 py-4 text-sm">
                                @if($linkSuggestions->isEmpty())
                                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-xs leading-5 text-gray-600">
                                        {{ __('admin.article_edit.internal_links.empty') }}
                                    </div>
                                @else
                                    <form method="POST" action="{{ route('admin.articles.internal-links.apply', ['articleId' => (int) $articleId]) }}" class="space-y-4">
                                        @csrf
                                        <div class="flex flex-wrap items-center gap-2">
                                            <button type="button" data-internal-link-select-all class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                {{ __('admin.article_edit.internal_links.select_all') }}
                                            </button>
                                            <button type="button" data-internal-link-clear class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                {{ __('admin.article_edit.internal_links.clear') }}
                                            </button>
                                            <span class="text-xs text-gray-500">{{ __('admin.article_edit.internal_links.apply_hint') }}</span>
                                        </div>
                                        <div class="space-y-3">
                                            @foreach($linkSuggestions as $suggestion)
                                                <label class="block rounded-lg border border-gray-200 bg-gray-50 p-3 hover:border-blue-200 hover:bg-blue-50/50">
                                                    <div class="flex items-start gap-3">
                                                        <input type="checkbox" name="entity_ids[]" value="{{ (int) ($suggestion['entity_id'] ?? 0) }}" class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500" data-internal-link-checkbox>
                                                        <div class="min-w-0 flex-1">
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <span class="font-semibold text-gray-900">{{ (string) ($suggestion['entity_name'] ?? '-') }}</span>
                                                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">{{ (string) ($suggestion['entity_type'] ?? '-') }}</span>
                                                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">{{ (string) ($suggestion['anchor_text'] ?? '-') }}</span>
                                                            </div>
                                                            <div class="mt-1 truncate text-xs text-blue-700">{{ (string) ($suggestion['canonical_url'] ?? '') }}</div>
                                                            <div class="mt-2 text-xs leading-5 text-gray-600">{{ (string) ($suggestion['snippet'] ?? '') }}</div>
                                                        </div>
                                                    </div>
                                                </label>
                                            @endforeach
                                        </div>
                                        <div class="flex justify-end">
                                            <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                                <i data-lucide="link" class="mr-2 h-4 w-4"></i>
                                                {{ __('admin.article_edit.internal_links.apply_selected') }}
                                            </button>
                                        </div>
                                    </form>
                                @endif

                                @if($linkRecords->isNotEmpty())
                                    <div class="mt-5 border-t border-gray-100 pt-4">
                                        <div class="text-xs font-medium text-gray-500">{{ __('admin.article_edit.internal_links.applied_title') }}</div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach($linkRecords as $record)
                                                <span class="inline-flex max-w-full items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
                                                    <span class="truncate">{{ (string) ($record->anchor_text ?? '') }}</span>
                                                    <span class="ml-1 text-gray-400">→</span>
                                                    <span class="ml-1 max-w-[220px] truncate text-blue-700">{{ (string) ($record->canonical_url ?? '') }}</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if($isEdit)
                        @php
                            $trace = is_array($generationTrace ?? null) ? $generationTrace : [];
                            $traceRun = is_array($trace['_run'] ?? null) ? $trace['_run'] : [];
                            $traceTask = is_array($trace['task'] ?? null) ? $trace['task'] : [];
                            $traceTitle = is_array($trace['title'] ?? null) ? $trace['title'] : [];
                            $traceModel = is_array($trace['model'] ?? null) ? $trace['model'] : [];
                            $traceKnowledge = is_array($trace['knowledge'] ?? null) ? $trace['knowledge'] : [];
                            $tracePrompt = is_array($trace['prompt'] ?? null) ? $trace['prompt'] : null;
                            $traceAuthor = is_array($trace['author'] ?? null) ? $trace['author'] : null;
                            $traceCategory = is_array($trace['category'] ?? null) ? $trace['category'] : null;
                            $traceImages = collect($trace['images'] ?? [])->filter(fn ($image) => is_array($image))->values();
                            $traceChunks = collect($traceKnowledge['chunks'] ?? [])->filter(fn ($chunk) => is_array($chunk))->values();
                            $traceEvidenceSummary = is_array($traceKnowledge['evidence_summary'] ?? null) ? $traceKnowledge['evidence_summary'] : [];
                            $traceEntities = collect($traceKnowledge['entities'] ?? [])->filter(fn ($entity) => is_array($entity))->values();
                            $traceCases = collect($traceKnowledge['cases'] ?? [])->filter(fn ($case) => is_array($case))->values();
                            $traceTags = collect($traceKnowledge['tag_filters'] ?? [])->map(fn ($tag) => trim((string) $tag))->filter()->values();
                            $tracePipeline = collect($trace['pipeline'] ?? [])->filter(fn ($step) => is_array($step))->values();
                        @endphp
                        <div class="bg-white shadow rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.article_edit.generation_trace.title') }}</h3>
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_edit.generation_trace.subtitle') }}</p>
                            </div>
                            <div class="px-6 py-4 text-sm text-gray-600 space-y-4">
                                @if($trace === [])
                                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-xs leading-5 text-gray-600">
                                        {{ __('admin.article_edit.generation_trace.empty') }}
                                    </div>
                                @else
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <div class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('admin.article_edit.generation_trace.run') }}</div>
                                            <div class="mt-1 text-gray-900">#{{ (int) ($traceRun['id'] ?? 0) }}</div>
                                        </div>
                                        <div>
                                            <div class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('admin.article_edit.generation_trace.duration') }}</div>
                                            <div class="mt-1 text-gray-900">{{ (int) ($traceRun['duration_ms'] ?? 0) }} ms</div>
                                        </div>
                                    </div>
                                @endif

                                <div class="space-y-1">
                                    <div><span class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.task') }}:</span> {{ (string) ($traceTask['name'] ?? $formData['task_name'] ?: __('admin.article_edit.info.manual_source')) }}</div>
                                    <div><span class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.title_source') }}:</span> {{ (string) ($traceTitle['text'] ?? '-') }}</div>
                                    <div><span class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.keyword') }}:</span> {{ (string) ($traceTitle['keyword'] ?? '-') }}</div>
                                    <div><span class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.model') }}:</span> {{ (string) ($traceModel['name'] ?? '-') }}</div>
                                    @if($tracePrompt)
                                        <div><span class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.prompt') }}:</span> {{ (string) ($tracePrompt['name'] ?? '-') }}</div>
                                    @endif
                                    @if($traceAuthor)
                                        <div><span class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.author') }}:</span> {{ (string) ($traceAuthor['name'] ?? '-') }}</div>
                                    @endif
                                    @if($traceCategory)
                                        <div><span class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.category') }}:</span> {{ (string) ($traceCategory['name'] ?? '-') }}</div>
                                    @endif
                                </div>

                                    @if($tracePipeline->isNotEmpty())
                                        <div class="rounded-lg border border-blue-100 bg-blue-50/50 p-3">
                                            <div class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.pipeline') }}</div>
                                            <div class="mt-3 space-y-3">
                                                @foreach($tracePipeline as $index => $step)
                                                    @php
                                                        $stepName = (string) ($step['name'] ?? '');
                                                        $stepStatus = (string) ($step['status'] ?? 'completed');
                                                        $stepMeta = is_array($step['meta'] ?? null) ? $step['meta'] : [];
                                                        $stepLabelKey = 'admin.article_edit.generation_trace.pipeline_steps.'.$stepName;
                                                        $stepLabel = __($stepLabelKey);
                                                        if ($stepLabel === $stepLabelKey) {
                                                            $stepLabel = str($stepName)->replace('_', ' ')->title()->toString();
                                                        }
                                                        $statusKey = 'admin.article_edit.generation_trace.pipeline_status.'.$stepStatus;
                                                        $statusLabel = __($statusKey);
                                                        if ($statusLabel === $statusKey) {
                                                            $statusLabel = str($stepStatus)->replace('_', ' ')->title()->toString();
                                                        }
                                                        $summaryParts = [];
                                                        foreach (['title', 'keyword', 'knowledge_strategy', 'context_length', 'image_count', 'word_count', 'attempts'] as $metaKey) {
                                                            if (array_key_exists($metaKey, $stepMeta) && $stepMeta[$metaKey] !== null && $stepMeta[$metaKey] !== '') {
                                                                $summaryKey = 'admin.article_edit.generation_trace.pipeline_meta.'.$metaKey;
                                                                $summaryLabel = __($summaryKey);
                                                                if ($summaryLabel === $summaryKey) {
                                                                    $summaryLabel = str($metaKey)->replace('_', ' ')->title()->toString();
                                                                }
                                                                $summaryParts[] = $summaryLabel.': '.(string) $stepMeta[$metaKey];
                                                            }
                                                        }
                                                    @endphp
                                                    <div class="flex gap-3">
                                                        <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $stepStatus === 'completed' ? 'bg-blue-600 text-white' : 'bg-amber-100 text-amber-700' }} text-xs font-semibold">{{ $index + 1 }}</div>
                                                        <div class="min-w-0 flex-1">
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <span class="font-medium text-gray-900">{{ $stepLabel }}</span>
                                                                <span class="rounded-full {{ $stepStatus === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }} px-2 py-0.5 text-[11px] font-semibold">{{ $statusLabel }}</span>
                                                            </div>
                                                            @if($summaryParts !== [])
                                                                <div class="mt-1 text-xs leading-5 text-gray-500">{{ implode(' · ', $summaryParts) }}</div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if($traceTags->isNotEmpty())
                                        <div>
                                            <div class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.tag_filters') }}</div>
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                @foreach($traceTags as $tag)
                                                    <span class="inline-flex rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">{{ $tag }}</span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if($traceKnowledge !== [] || $traceChunks->isNotEmpty())
                                    <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.knowledge') }}</span>
                                            <span class="text-xs text-gray-500">{{ __('admin.article_edit.generation_trace.context_length', ['count' => (int) ($traceKnowledge['context_length'] ?? 0)]) }}</span>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500">{{ __('admin.article_edit.generation_trace.strategy') }}: {{ (string) ($traceKnowledge['strategy'] ?? 'none') }}</div>
                                        @if($traceEvidenceSummary !== [])
                                            <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                                <span class="rounded-full bg-white px-2 py-1 ring-1 ring-gray-200">{{ __('admin.article_edit.generation_trace.evidence_average') }}: {{ (int) ($traceEvidenceSummary['average_evidence_score'] ?? 0) }}</span>
                                                <span class="rounded-full bg-white px-2 py-1 ring-1 ring-gray-200">{{ __('admin.article_edit.generation_trace.evidence_chunks') }}: {{ (int) ($traceEvidenceSummary['chunk_count'] ?? 0) }}</span>
                                            </div>
                                        @endif
                                        @if($traceChunks->isNotEmpty())
                                            <div class="mt-3 space-y-2">
                                                @foreach($traceChunks->take(5) as $chunk)
                                                    <div class="rounded border border-gray-200 bg-white p-2">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <div class="font-medium text-gray-800">{{ (string) ($chunk['knowledge_base_name'] ?? '-') }} #{{ (int) ($chunk['chunk_index'] ?? 0) }}</div>
                                                            @if(isset($chunk['evidence_score']))
                                                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">{{ __('admin.article_edit.generation_trace.evidence_score') }} {{ (int) $chunk['evidence_score'] }}</span>
                                                            @endif
                                                            @if((string) ($chunk['retrieval_source'] ?? '') !== '')
                                                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">{{ (string) $chunk['retrieval_source'] }}</span>
                                                            @endif
                                                        </div>
                                                        @if(!empty($chunk['match_reasons']) && is_array($chunk['match_reasons']))
                                                            <div class="mt-1 text-[11px] text-gray-400">{{ __('admin.article_edit.generation_trace.match_reasons') }}: {{ implode(' · ', array_map('strval', $chunk['match_reasons'])) }}</div>
                                                        @endif
                                                        <div class="mt-1 line-clamp-2 text-xs text-gray-500">{{ (string) ($chunk['preview'] ?? '') }}</div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    @endif

                                    @if($traceEntities->isNotEmpty() || $traceCases->isNotEmpty())
                                        <div class="space-y-2">
                                            <div class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.entity_case') }}</div>
                                            @foreach($traceEntities->take(4) as $entity)
                                                <div class="text-xs text-gray-600">Entity: {{ (string) ($entity['name'] ?? '-') }}</div>
                                            @endforeach
                                            @foreach($traceCases->take(4) as $case)
                                                <div class="text-xs text-gray-600">Case: {{ (string) ($case['title'] ?? '-') }}</div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if($traceImages->isNotEmpty())
                                        <div>
                                            <div class="font-medium text-gray-700">{{ __('admin.article_edit.generation_trace.images') }}</div>
                                            <div class="mt-2 space-y-1">
                                                @foreach($traceImages as $image)
                                                    <div class="truncate text-xs text-gray-600">#{{ (int) ($image['id'] ?? 0) }} {{ (string) ($image['original_name'] ?? $image['filename'] ?? '') }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                            </div>
                        </div>
                    @endif

                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.seo_title') }}</h3>
                        </div>
                        <div class="px-6 py-4 space-y-6">
                            <div>
                                <label for="keywords" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.keywords') }}</label>
                                <input id="keywords" type="text" name="keywords" value="{{ $formData['keywords'] }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __($i18nRoot.'.placeholder.keywords') }}">
                            </div>
                            <div>
                                <label for="meta_description" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.meta_description') }}</label>
                                <textarea id="meta_description" name="meta_description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __($i18nRoot.'.placeholder.meta_description') }}">{{ $formData['meta_description'] }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.publish_title') }}</h3>
                        </div>
                        <div class="px-6 py-4 space-y-4">
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.publish_status') }}</label>
                                <select id="status" name="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="draft" @selected($formData['status'] === 'draft')>{{ __('admin.articles.status.draft') }}</option>
                                    <option value="published" @selected($formData['status'] === 'published')>{{ __('admin.articles.status.published') }}</option>
                                    <option value="private" @selected($formData['status'] === 'private')>{{ __('admin.articles.status.private') }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="review_status" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.review_status') }}</label>
                                <select id="review_status" name="review_status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="pending" @selected($formData['review_status'] === 'pending')>{{ __('admin.articles.review.pending') }}</option>
                                    <option value="approved" @selected($formData['review_status'] === 'approved')>{{ __('admin.articles.review.approved') }}</option>
                                    <option value="rejected" @selected($formData['review_status'] === 'rejected')>{{ __('admin.articles.review.rejected') }}</option>
                                    <option value="auto_approved" @selected($formData['review_status'] === 'auto_approved')>{{ __('admin.articles.review.auto_approved') }}</option>
                                </select>
                                <p class="mt-2 text-xs text-gray-500">{{ __($i18nRoot.'.help.review_status') }}</p>
                            </div>
                            <div class="rounded-lg border border-blue-100 bg-blue-50/70 p-4">
                                <div class="text-sm font-medium text-gray-900">{{ __($i18nRoot.'.section.recommendation_title') }}</div>
                                <p class="mt-1 text-xs text-gray-600">{{ __($i18nRoot.'.help.recommendation') }}</p>
                                <div class="mt-3 space-y-3">
                                    <label class="flex items-start gap-3 text-sm text-gray-700">
                                        <input type="checkbox" name="is_hot" value="1" @checked((string) $formData['is_hot'] === '1') class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                        <span>
                                            <span class="font-medium text-gray-900">{{ __($i18nRoot.'.field.is_hot') }}</span>
                                            <span class="block text-xs text-gray-500">{{ __($i18nRoot.'.help.is_hot') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3 text-sm text-gray-700">
                                        <input type="checkbox" name="is_featured" value="1" @checked((string) $formData['is_featured'] === '1') class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                        <span>
                                            <span class="font-medium text-gray-900">{{ __($i18nRoot.'.field.is_featured') }}</span>
                                            <span class="block text-xs text-gray-500">{{ __($i18nRoot.'.help.is_featured') }}</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.category_author_title') }}</h3>
                        </div>
                        <div class="px-6 py-4 space-y-4">
                            <div>
                                <label for="category_id" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.category') }} *</label>
                                <select id="category_id" name="category_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="">{{ __($i18nRoot.'.option.select_category') }}</option>
                                    @foreach(($formOptions['categories'] ?? []) as $category)
                                        <option value="{{ (int) $category['id'] }}" @selected($formData['category_id'] === (string) $category['id'])>{{ $category['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="author_id" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.author') }} *</label>
                                <select id="author_id" name="author_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="">{{ __($i18nRoot.'.option.select_author') }}</option>
                                    @foreach(($formOptions['authors'] ?? []) as $author)
                                        <option value="{{ (int) $author['id'] }}" @selected($formData['author_id'] === (string) $author['id'])>{{ $author['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end space-x-3">
                        <a href="{{ route('admin.articles.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            {{ $isEdit ? __('admin.article_edit.button.save_changes') : __('admin.button.create_article') }}
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/vditor/dist/index.css') }}">
    <style>
        .article-markdown-editor .vditor {
            border-color: #d1d5db;
            border-radius: 0.5rem;
            overflow: hidden;
        }
        .article-markdown-editor .vditor-toolbar {
            border-bottom-color: #e5e7eb;
            background: #f9fafb;
        }
        .article-markdown-editor .vditor-reset,
        .article-markdown-editor .vditor-ir pre.vditor-reset,
        .article-markdown-editor .vditor-sv .vditor-reset {
            font-size: 15px;
            line-height: 1.8;
        }
        .article-editor-context-menu {
            position: fixed;
            z-index: 70;
            width: 220px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 18px 48px rgba(15, 23, 42, 0.18);
        }
        .article-editor-context-menu[hidden] {
            display: none;
        }
        .article-editor-context-menu button {
            display: flex;
            width: 100%;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            color: #374151;
            font-size: 14px;
            text-align: left;
        }
        .article-editor-context-menu button:hover {
            background: #eff6ff;
            color: #1d4ed8;
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('vendor/vditor/dist/index.min.js') }}"></script>
    <script>
        (function () {
            const textarea = document.getElementById('content-textarea');
            const editorNode = document.getElementById('content-editor');
            const form = textarea ? textarea.closest('form') : null;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const imageInput = document.getElementById('article-editor-quick-image-input');
            const contextMenu = document.getElementById('article-editor-context-menu');
            const copyMarkdownButton = document.getElementById('article-editor-copy-markdown');
            const copyWechatHtmlButton = document.getElementById('article-editor-copy-wechat-html');
            const uploadUrl = editorNode?.dataset.uploadUrl || '';
            const uploadEnabled = editorNode?.dataset.uploadEnabled === '1' && uploadUrl !== '';
            const wechatHtmlUrl = editorNode?.dataset.wechatHtmlUrl || '';
            const vditorLang = editorNode?.dataset.vditorLang || 'en_US';
            let editor = null;

            const messages = {
                uploadDisabled: @json(__('admin.article_editor.error.upload_disabled')),
                imageRequired: @json(__('admin.article_editor.error.image_required')),
                uploadFailed: @json(__('admin.article_editor.error.upload_failed_generic')),
                uploading: @json(__('admin.article_editor.message.uploading')),
                uploadSuccess: @json(__('admin.article_editor.message.upload_success')),
                copyEmpty: @json(__('admin.article_editor.copy.empty')),
                copySuccess: @json(__('admin.article_editor.copy.success')),
                copyFailed: @json(__('admin.article_editor.copy.failed')),
                wechatCopying: @json(__('admin.article_editor.wechat.copying')),
                wechatSuccess: @json(__('admin.article_editor.wechat.success')),
                wechatFailed: @json(__('admin.article_editor.wechat.failed')),
            };
            const snippets = {
                heading: @json(__('admin.article_editor.snippets.heading')),
                quote: @json(__('admin.article_editor.snippets.quote')),
                list: @json(__('admin.article_editor.snippets.list')),
                divider: @json(__('admin.article_editor.snippets.divider')),
            };

            if (!textarea || !editorNode || typeof Vditor === 'undefined') {
                return;
            }

            function currentMarkdown() {
                return editor && typeof editor.getValue === 'function'
                    ? (editor.getValue() || '')
                    : (textarea.value || '');
            }

            function syncTextarea() {
                textarea.value = currentMarkdown();
            }

            function tip(message) {
                if (!message) {
                    return;
                }
                if (editor && typeof editor.tip === 'function') {
                    editor.tip(message, 2400);
                } else {
                    window.alert(message);
                }
            }

            function copyTextFallback(value) {
                const helper = document.createElement('textarea');
                helper.value = value;
                helper.setAttribute('readonly', 'readonly');
                helper.style.position = 'fixed';
                helper.style.left = '-9999px';
                document.body.appendChild(helper);
                helper.select();
                helper.setSelectionRange(0, helper.value.length);
                try {
                    return document.execCommand('copy');
                } finally {
                    helper.remove();
                }
            }

            function copyHtmlFallback(html) {
                const helper = document.createElement('div');
                helper.setAttribute('contenteditable', 'true');
                helper.style.position = 'fixed';
                helper.style.left = '-9999px';
                helper.style.width = '720px';
                helper.innerHTML = html;
                document.body.appendChild(helper);
                helper.focus();
                const selection = window.getSelection();
                const range = document.createRange();
                range.selectNodeContents(helper);
                selection?.removeAllRanges();
                selection?.addRange(range);
                try {
                    return document.execCommand('copy');
                } finally {
                    selection?.removeAllRanges();
                    helper.remove();
                }
            }

            async function copyMarkdown() {
                const markdown = currentMarkdown();
                textarea.value = markdown;
                if (!markdown.trim()) {
                    tip(messages.copyEmpty);
                    return;
                }
                try {
                    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext) {
                        await navigator.clipboard.writeText(markdown);
                    } else if (!copyTextFallback(markdown)) {
                        throw new Error(messages.copyFailed);
                    }
                    tip(messages.copySuccess);
                } catch (error) {
                    tip(error.message || messages.copyFailed);
                }
            }

            async function copyRichHtml(html, plainText) {
                if (
                    navigator.clipboard
                    && typeof navigator.clipboard.write === 'function'
                    && typeof window.ClipboardItem !== 'undefined'
                    && window.isSecureContext
                ) {
                    await navigator.clipboard.write([
                        new ClipboardItem({
                            'text/html': new Blob([html], { type: 'text/html' }),
                            'text/plain': new Blob([plainText || html], { type: 'text/plain' }),
                        }),
                    ]);
                    return;
                }
                if (!copyHtmlFallback(html)) {
                    throw new Error(messages.wechatFailed);
                }
            }

            async function copyWeChatHtml() {
                const markdown = currentMarkdown();
                textarea.value = markdown;
                if (!markdown.trim()) {
                    tip(messages.copyEmpty);
                    return;
                }
                if (!wechatHtmlUrl) {
                    tip(messages.wechatFailed);
                    return;
                }

                const originalHtml = copyWechatHtmlButton?.innerHTML || '';
                if (copyWechatHtmlButton) {
                    copyWechatHtmlButton.disabled = true;
                    copyWechatHtmlButton.innerHTML = '<i data-lucide="loader-2" class="mr-1.5 h-4 w-4 animate-spin"></i>' + messages.wechatCopying;
                    if (window.lucide) {
                        window.lucide.createIcons();
                    }
                }

                try {
                    const response = await fetch(wechatHtmlUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ content: markdown }),
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || !payload.html) {
                        throw new Error(payload.message || messages.wechatFailed);
                    }
                    await copyRichHtml(String(payload.html), String(payload.plain || markdown));
                    tip(payload.message || messages.wechatSuccess);
                } catch (error) {
                    tip(error.message || messages.wechatFailed);
                } finally {
                    if (copyWechatHtmlButton) {
                        copyWechatHtmlButton.disabled = false;
                        copyWechatHtmlButton.innerHTML = originalHtml;
                        if (window.lucide) {
                            window.lucide.createIcons();
                        }
                    }
                }
            }

            async function uploadImage(file) {
                if (!uploadEnabled) {
                    tip(messages.uploadDisabled);
                    return;
                }
                if (!file) {
                    tip(messages.imageRequired);
                    return;
                }

                tip(messages.uploading);
                const formData = new FormData();
                formData.append('image', file);
                formData.append('alt', file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' '));

                try {
                    const response = await fetch(uploadUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || !payload.image?.markdown) {
                        throw new Error(payload.message || messages.uploadFailed);
                    }
                    editor.insertValue("\n\n" + payload.image.markdown + "\n\n");
                    syncTextarea();
                    tip(payload.message || messages.uploadSuccess);
                } catch (error) {
                    tip(error.message || messages.uploadFailed);
                }
            }

            function insertSnippet(action) {
                if (action === 'image') {
                    imageInput?.click();
                    return;
                }
                const snippet = snippets[action] || '';
                if (snippet !== '') {
                    editor.insertValue("\n\n" + snippet + "\n\n");
                    syncTextarea();
                }
            }

            function showContextMenu(event) {
                if (!contextMenu || !editorNode.contains(event.target)) {
                    return;
                }
                event.preventDefault();
                contextMenu.style.left = Math.min(event.clientX, window.innerWidth - 240) + 'px';
                contextMenu.style.top = Math.min(event.clientY, window.innerHeight - 260) + 'px';
                contextMenu.hidden = false;
                if (window.lucide) {
                    window.lucide.createIcons();
                }
            }

            function hideContextMenu() {
                if (contextMenu) {
                    contextMenu.hidden = true;
                }
            }

            editor = new Vditor('content-editor', {
                value: textarea.value || '',
                height: 520,
                mode: 'ir',
                lang: vditorLang,
                cache: { enable: false },
                placeholder: @json(__($i18nRoot.'.placeholder.content')),
                toolbar: [
                    'emoji', 'headings', 'bold', 'italic', 'strike', '|',
                    'list', 'ordered-list', 'check', 'outdent', 'indent', '|',
                    'quote', 'line', 'code', 'inline-code', '|',
                    'upload', 'link', 'table', '|',
                    'undo', 'redo', 'fullscreen', 'edit-mode', 'preview',
                ],
                input: function (value) {
                    textarea.value = value || '';
                },
                upload: {
                    accept: 'image/*',
                    multiple: false,
                    handler: function (files) {
                        uploadImage(files && files[0] ? files[0] : null);
                        return null;
                    },
                },
            });

            form?.addEventListener('submit', syncTextarea);
            copyMarkdownButton?.addEventListener('click', copyMarkdown);
            copyWechatHtmlButton?.addEventListener('click', copyWeChatHtml);
            imageInput?.addEventListener('change', function () {
                uploadImage(this.files && this.files[0] ? this.files[0] : null);
                this.value = '';
            });
            editorNode.addEventListener('contextmenu', showContextMenu);
            document.addEventListener('click', (event) => {
                const actionButton = event.target.closest('[data-editor-action]');
                if (actionButton) {
                    hideContextMenu();
                    insertSnippet(actionButton.dataset.editorAction || '');
                    return;
                }
                if (!event.target.closest('#article-editor-context-menu')) {
                    hideContextMenu();
                }
                if (event.target.closest('[data-internal-link-select-all]')) {
                    document.querySelectorAll('[data-internal-link-checkbox]').forEach((checkbox) => {
                        checkbox.checked = true;
                    });
                }
                if (event.target.closest('[data-internal-link-clear]')) {
                    document.querySelectorAll('[data-internal-link-checkbox]').forEach((checkbox) => {
                        checkbox.checked = false;
                    });
                }
            });
        })();
    </script>
@endpush
