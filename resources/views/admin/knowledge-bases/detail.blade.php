@extends('admin.layouts.app')

@php
    $vditorLocaleMap = [
        'zh_CN' => 'zh_CN',
        'en' => 'en_US',
        'en_US' => 'en_US',
        'ja' => 'ja_JP',
        'ja_JP' => 'ja_JP',
        'ru' => 'ru_RU',
        'ru_RU' => 'ru_RU',
        'pt_BR' => 'pt_BR',
        'es' => 'es_ES',
        'es_ES' => 'es_ES',
    ];
    $vditorLang = $vditorLocaleMap[str_replace('-', '_', app()->getLocale())] ?? 'en_US';
    $systemReadOnly = (bool) ($isSystemKnowledge ?? false) && ! (bool) ($canEditSystemKnowledge ?? false);
    $systemMetadataReadOnly = (bool) ($isSystemKnowledge ?? false);
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/vditor/dist/index.css') }}">
    <style>
        .knowledge-markdown-editor .vditor {
            border: 0;
            border-radius: 0 0 0.75rem 0.75rem;
            min-height: 720px;
        }

        .knowledge-markdown-editor .vditor-toolbar {
            background: #f9fafb;
            border-bottom-color: #e5e7eb;
            padding: 8px 10px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .knowledge-markdown-editor .vditor-ir,
        .knowledge-markdown-editor .vditor-sv,
        .knowledge-markdown-editor .vditor-wysiwyg {
            min-height: 660px;
            padding: 30px 36px;
            font-size: 16px;
            line-height: 1.85;
        }

        .knowledge-markdown-editor .vditor-reset {
            color: #111827;
        }

        .knowledge-markdown-editor .vditor-reset h1,
        .knowledge-markdown-editor .vditor-reset h2,
        .knowledge-markdown-editor .vditor-reset h3 {
            color: #111827;
            letter-spacing: 0;
        }

        .knowledge-markdown-editor .vditor-preview {
            background: #fff;
        }

        .knowledge-markdown-editor .vditor-outline {
            width: 210px;
            flex: 0 0 210px;
            border-right-color: #e5e7eb;
            background: #f8fafc;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }

        .knowledge-markdown-editor .vditor-outline__title {
            position: sticky;
            top: 0;
            z-index: 1;
            border-bottom: 1px solid #e5e7eb;
            background: #f8fafc;
            padding: 12px 14px 10px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .knowledge-markdown-editor .vditor-outline__content {
            padding: 8px 7px 18px;
        }

        .knowledge-markdown-editor .vditor-outline__content:empty::before {
            display: block;
            padding: 18px 10px;
            color: #94a3b8;
            content: attr(data-empty-label);
            font-size: 12px;
            line-height: 1.6;
        }

        .knowledge-markdown-editor .vditor-outline ul {
            padding-left: 12px;
        }

        .knowledge-markdown-editor .vditor-outline__content > ul {
            padding-left: 0;
        }

        .knowledge-markdown-editor .vditor-outline li > span {
            min-width: 0;
            border-radius: 6px;
            padding: 6px 8px;
            color: #475569;
            font-size: 13px;
            line-height: 1.45;
            transition: background-color 120ms ease, color 120ms ease, transform 80ms ease;
        }

        .knowledge-markdown-editor .vditor-outline li > span:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .knowledge-markdown-editor .vditor-outline li > span:active {
            transform: scale(0.98);
        }

        .knowledge-markdown-editor .vditor-outline li > span:focus-visible {
            outline: 2px solid #fb923c;
            outline-offset: -2px;
        }

        .knowledge-markdown-editor .vditor-outline li > span[data-heading-level="1"] {
            color: #0f172a;
            font-weight: 700;
        }

        .knowledge-markdown-editor .vditor-outline li > span[data-heading-level="2"] {
            font-weight: 600;
        }

        .knowledge-markdown-editor .vditor-outline li > span[aria-current="location"] {
            background: #fff7ed;
            color: #c2410c;
            font-weight: 650;
        }

        .knowledge-markdown-editor .vditor-outline__action {
            transition: transform 150ms ease;
        }

        @media (max-width: 1023px) {
            .knowledge-markdown-editor .vditor-outline {
                display: none !important;
            }
        }
    </style>
@endpush

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-7">
            <x-admin.v3.knowledge-base-subnav :knowledge-base="$knowledgeBase" active="current" />
        </div>

        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.knowledge-bases.index') }}" aria-label="{{ __('admin.common.back') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="break-words text-2xl font-bold text-gray-900">{{ $knowledgeBase->name }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.knowledge_detail.heading') }}</p>
                </div>
            </div>
        </div>

        @if ($isSystemKnowledge ?? false)
            <section class="mb-6 overflow-hidden rounded-2xl border border-orange-200 bg-gradient-to-br from-orange-50 via-white to-amber-50 shadow-sm">
                <div class="flex flex-col gap-5 px-6 py-6 lg:flex-row lg:items-start lg:justify-between">
                    <div class="max-w-3xl">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-orange-600 px-3 py-1 text-xs font-semibold text-white">
                                <i data-lucide="sparkles" class="h-3.5 w-3.5"></i>
                                {{ __('admin.knowledge_detail.system_title') }}
                            </span>
                            <span class="inline-flex rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                                {{ __('admin.knowledge_bases.system_health.'.($systemKnowledgeHealth['status'] ?? 'fallback')) }}
                            </span>
                            <span class="text-xs font-medium text-slate-600">
                                {{ __('admin.knowledge_bases.system_version', ['version' => (string) ($knowledgeBase->systemBinding?->official_version ?? '-')]) }}
                            </span>
                        </div>
                        <p class="mt-4 text-sm leading-7 text-slate-700">{{ __('admin.knowledge_detail.system_purpose') }}</p>
                        <p class="mt-2 text-sm leading-7 text-slate-600">{{ __('admin.knowledge_detail.system_effect') }}</p>
                        <div class="mt-4 flex flex-wrap items-center gap-3 text-xs font-medium">
                            <span class="inline-flex items-center gap-1.5 text-orange-800">
                                <i data-lucide="shield-check" class="h-4 w-4"></i>
                                {{ __('admin.knowledge_detail.system_delete_notice') }}
                            </span>
                            <span class="text-slate-500">
                                {{ $canEditSystemKnowledge ? __('admin.knowledge_detail.system_edit_permission') : __('admin.knowledge_detail.system_read_only') }}
                            </span>
                        </div>
                    </div>
                    @if ($canEditSystemKnowledge && ($systemKnowledgeHealth['is_customized'] ?? false))
                        <form method="POST" action="{{ route('admin.knowledge-bases.official.adopt', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}" class="shrink-0" data-admin-confirm-form data-admin-confirm-tone="warning" data-admin-confirm-title="{{ __('admin.knowledge_detail.system_adopt_confirm') }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.knowledge_detail.system_adopt_official') }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-orange-200 bg-white px-4 py-2.5 text-sm font-semibold text-orange-700 shadow-sm hover:bg-orange-50" data-admin-confirm-submit disabled aria-disabled="true">
                                <i data-lucide="package-check" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.knowledge_detail.system_adopt_official') }}
                            </button>
                        </form>
                    @endif
                </div>
                @if ($canEditSystemKnowledge && is_string($systemOfficialContent ?? null))
                    <details class="border-t border-orange-200 bg-white/80 px-6 py-4">
                        <summary class="flex cursor-pointer items-center gap-2 text-sm font-semibold text-orange-800">
                            <i data-lucide="git-compare-arrows" class="h-4 w-4"></i>
                            {{ __('admin.knowledge_detail.system_diff_title') }}
                        </summary>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('admin.knowledge_detail.system_diff_desc') }}</p>
                        <div class="mt-4 grid gap-4 xl:grid-cols-2">
                            <div class="min-w-0">
                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('admin.knowledge_detail.system_diff_current') }}</div>
                                <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs leading-6 text-slate-100">{{ $knowledgeBase->content }}</pre>
                            </div>
                            <div class="min-w-0">
                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('admin.knowledge_detail.system_diff_official') }}</div>
                                <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-xl border border-orange-200 bg-orange-50 p-4 text-xs leading-6 text-slate-800">{{ $systemOfficialContent }}</pre>
                            </div>
                        </div>
                    </details>
                @endif
            </section>
        @endif

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_detail.content_title') }}</h3>
            </div>
            <form id="knowledge-detail-form" method="POST" action="{{ route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}" class="p-6 space-y-4">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_detail.field_name') }}</label>
                        <input type="text" name="name" value="{{ old('name', (string) $knowledgeBase->name) }}" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm disabled:bg-gray-50 disabled:text-gray-500 read-only:bg-gray-50 read-only:text-gray-500" required @readonly($systemMetadataReadOnly) @disabled($systemReadOnly)>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_bases.field_doc_type') }}</label>
                        @if ($systemMetadataReadOnly)
                            <input type="hidden" name="file_type" value="markdown">
                        @endif
                        <select name="file_type" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm disabled:bg-gray-50 disabled:text-gray-500" required @disabled($systemReadOnly || $systemMetadataReadOnly)>
                            <option value="markdown" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'markdown')>{{ __('admin.status.markdown') }}</option>
                            <option value="word" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'word')>{{ __('admin.status.word_document') }}</option>
                            <option value="text" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'text')>{{ __('admin.status.text') }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_detail.field_description') }}</label>
                    <textarea name="description" rows="3" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm disabled:bg-gray-50 disabled:text-gray-500 read-only:bg-gray-50 read-only:text-gray-500" @readonly($systemMetadataReadOnly) @disabled($systemReadOnly)>{{ old('description', (string) ($knowledgeBase->description ?? '')) }}</textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_detail.field_content') }}</label>
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <span class="inline-flex w-fit items-center rounded-full bg-orange-50 px-3 py-1 text-sm font-semibold text-orange-700">
                                <i data-lucide="file-pen-line" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.knowledge_detail.editor_badge') }}
                            </span>
                            <span class="text-sm text-gray-500">{{ __('admin.knowledge_detail.editor_hint') }}</span>
                        </div>
                        <textarea id="knowledge-content-textarea" name="content" rows="28" class="{{ $systemReadOnly ? 'block' : 'hidden' }} w-full resize-y border-0 px-6 py-5 font-mono text-sm leading-7 text-slate-700 focus:ring-0 disabled:bg-white disabled:text-slate-700" @disabled($systemReadOnly)>{{ old('content', (string) ($knowledgeBase->content ?? '')) }}</textarea>
                        @unless ($systemReadOnly)
                            <div id="knowledge-content-editor" class="knowledge-markdown-editor min-h-[720px]" data-knowledge-outline-levels="1,2,3,4"></div>
                        @endunless
                    </div>
                </div>
            </form>
            <div class="-mt-2 flex flex-col gap-3 px-6 pb-6 sm:flex-row sm:items-center sm:justify-end">
                @if (! ($isSystemKnowledge ?? false) || $canEditSystemKnowledge)
                    <form method="POST" action="{{ route('admin.knowledge-bases.chunks.refresh', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}">
                        @csrf
                        <input type="hidden" name="redirect_to" value="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id], false) }}">
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-orange-200 bg-orange-50 px-4 py-2 text-sm font-medium text-orange-700 hover:bg-orange-100 sm:w-auto" title="{{ __('admin.knowledge_detail.resubmit_chunks_help') }}">
                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.knowledge_detail.resubmit_chunks') }}
                        </button>
                    </form>
                @endif
                @unless ($systemReadOnly)
                    <button type="submit" form="knowledge-detail-form" class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md text-sm text-white bg-orange-600 hover:bg-orange-700">
                        <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.knowledge_detail.save_changes') }}
                    </button>
                @endunless
            </div>
        </div>

        @if ($isSystemKnowledge ?? false)
            <section class="mb-6 overflow-hidden rounded-lg bg-white shadow">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_detail.revision_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.knowledge_detail.revision_desc') }}</p>
                </div>
                @if ($knowledgeBase->revisions->isEmpty())
                    <div class="px-6 py-6 text-sm text-gray-500">{{ __('admin.knowledge_detail.revision_empty') }}</div>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach ($knowledgeBase->revisions->take(12) as $revision)
                            <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-gray-900">{{ __('admin.knowledge_detail.revision_number', ['number' => (int) $revision->revision_number]) }}</span>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ __('admin.knowledge_detail.revision_'.$revision->source) }}</span>
                                        <span class="font-mono text-xs text-gray-400">{{ substr((string) $revision->content_hash, 0, 12) }}</span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
                                        <span>{{ optional($revision->created_at)->format('Y-m-d H:i:s') }}</span>
                                        @if ($revision->creator)
                                            <span>{{ __('admin.knowledge_detail.revision_by', ['name' => $revision->creator->name]) }}</span>
                                        @endif
                                    </div>
                                </div>
                                @if ($canEditSystemKnowledge && hash('sha256', (string) $knowledgeBase->content) !== (string) $revision->content_hash)
                                    <form method="POST" action="{{ route('admin.knowledge-bases.revisions.restore', ['knowledgeBaseId' => (int) $knowledgeBase->id, 'revisionId' => (int) $revision->id]) }}" data-admin-confirm-form data-admin-confirm-tone="warning" data-admin-confirm-title="{{ __('admin.knowledge_detail.revision_restore_confirm') }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.knowledge_detail.revision_restore_action') }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50" data-admin-confirm-submit disabled aria-disabled="true">
                                            <i data-lucide="history" class="mr-1.5 h-4 w-4"></i>
                                            {{ __('admin.knowledge_detail.revision_restore_action') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        @if ($isSystemKnowledge ?? false)
            <section class="mb-6 overflow-hidden rounded-lg bg-white shadow" id="knowledge-media">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_detail.media_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.knowledge_detail.media_desc') }}</p>
                </div>
                @if ($canEditSystemKnowledge)
                    <details class="border-b border-gray-200 bg-orange-50/50">
                        <summary class="flex cursor-pointer items-center gap-2 px-6 py-4 text-sm font-semibold text-orange-800">
                            <i data-lucide="image-plus" class="h-4 w-4"></i>
                            {{ __('admin.knowledge_detail.media_add') }}
                        </summary>
                        <form method="POST" enctype="multipart/form-data" action="{{ route('admin.knowledge-bases.media.store', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}" class="grid gap-4 border-t border-orange-100 bg-white px-6 py-5 md:grid-cols-2 xl:grid-cols-3">
                            @csrf
                            <label class="text-sm text-gray-700">{{ __('admin.knowledge_detail.media_image') }}<input type="file" name="image" accept="image/png,image/webp" required class="mt-1 block w-full text-sm"></label>
                            <label class="text-sm text-gray-700">{{ __('admin.knowledge_detail.media_asset_key') }}<input name="asset_key" required placeholder="tasks.create.form" class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                            <label class="text-sm text-gray-700">{{ __('admin.knowledge_detail.media_section_key') }}<input name="section_key" required placeholder="任务创建" class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                            <label class="text-sm text-gray-700">{{ __('admin.knowledge_detail.media_route_name') }}<input name="route_name" required placeholder="admin.tasks.create" class="mt-1 w-full rounded-md border-gray-300 font-mono text-sm"></label>
                            <label class="text-sm text-gray-700">{{ __('admin.knowledge_detail.media_title_field') }}<input name="title" required class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                            <label class="text-sm text-gray-700">{{ __('admin.knowledge_detail.media_alt_text') }}<input name="alt_text" required class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                            <label class="text-sm text-gray-700 md:col-span-2">{{ __('admin.knowledge_detail.media_caption') }}<input name="caption" class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                            <label class="text-sm text-gray-700">{{ __('admin.knowledge_detail.media_keywords') }}<input name="keywords" placeholder="任务, 创建, 模型" class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                            <input type="hidden" name="locale" value="zh_CN">
                            <div class="md:col-span-2 xl:col-span-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs leading-5 text-gray-500">{{ __('admin.knowledge_detail.media_rules') }}</p>
                                <button type="submit" class="inline-flex shrink-0 items-center justify-center rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">
                                    <i data-lucide="upload" class="mr-2 h-4 w-4"></i>{{ __('admin.knowledge_detail.media_save') }}
                                </button>
                            </div>
                        </form>
                    </details>
                @endif

                @if (($knowledgeMediaAssets ?? collect())->isEmpty())
                    <div class="px-6 py-8 text-sm text-gray-500">{{ __('admin.knowledge_detail.media_empty') }}</div>
                @else
                    <div class="grid gap-5 p-6 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($knowledgeMediaAssets as $mediaAsset)
                            <article class="overflow-hidden rounded-xl border border-gray-200 bg-white"
                                data-knowledge-media
                                data-media-asset-key="{{ $mediaAsset->asset_key }}"
                                data-media-locale="{{ $mediaAsset->locale }}"
                                data-media-active="{{ $mediaAsset->is_active ? 'true' : 'false' }}">
                                <img src="{{ route('admin.ai-workspace.media.show', ['mediaAsset' => (int) $mediaAsset->id, 'variant' => 'thumbnail']) }}" alt="{{ $mediaAsset->alt_text }}" loading="lazy" class="aspect-[16/10] w-full bg-gray-100 object-cover">
                                <div class="space-y-3 p-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $mediaAsset->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
                                            {{ $mediaAsset->is_active ? __('admin.knowledge_detail.media_active') : __('admin.knowledge_detail.media_inactive') }}
                                        </span>
                                        <span class="rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700">{{ __('admin.knowledge_detail.media_version', ['version' => (int) $mediaAsset->asset_version]) }}</span>
                                        @if ($mediaAsset->needs_review)
                                            <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-800">{{ __('admin.knowledge_detail.media_needs_review') }}</span>
                                        @endif
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-900">{{ $mediaAsset->title }}</h4>
                                        <p class="mt-1 text-xs leading-5 text-gray-500">{{ $mediaAsset->caption }}</p>
                                    </div>
                                    <div class="space-y-1 text-xs text-gray-500">
                                        <div class="font-mono">{{ $mediaAsset->asset_key }}</div>
                                        <div>{{ $mediaAsset->section_key }} · {{ $mediaAsset->route_name }}</div>
                                    </div>

                                    @if ($canEditSystemKnowledge)
                                        <details class="rounded-lg border border-gray-200 bg-gray-50">
                                            <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-gray-700">{{ __('admin.knowledge_detail.media_manage') }}</summary>
                                            <form method="POST" action="{{ route('admin.knowledge-bases.media.update', ['knowledgeBaseId' => (int) $knowledgeBase->id, 'mediaAsset' => (int) $mediaAsset->id]) }}" class="space-y-3 border-t border-gray-200 bg-white p-3">
                                                @csrf
                                                @method('PUT')
                                                <input name="section_key" value="{{ $mediaAsset->section_key }}" required aria-label="{{ __('admin.knowledge_detail.media_section_key') }}" class="w-full rounded-md border-gray-300 text-xs">
                                                <input name="route_name" value="{{ $mediaAsset->route_name }}" required aria-label="{{ __('admin.knowledge_detail.media_route_name') }}" class="w-full rounded-md border-gray-300 font-mono text-xs">
                                                <input name="title" value="{{ $mediaAsset->title }}" required aria-label="{{ __('admin.knowledge_detail.media_title_field') }}" class="w-full rounded-md border-gray-300 text-xs">
                                                <input name="alt_text" value="{{ $mediaAsset->alt_text }}" required aria-label="{{ __('admin.knowledge_detail.media_alt_text') }}" class="w-full rounded-md border-gray-300 text-xs">
                                                <textarea name="caption" rows="2" aria-label="{{ __('admin.knowledge_detail.media_caption') }}" class="w-full rounded-md border-gray-300 text-xs">{{ $mediaAsset->caption }}</textarea>
                                                <input name="keywords" value="{{ implode(', ', (array) $mediaAsset->keywords_json) }}" aria-label="{{ __('admin.knowledge_detail.media_keywords') }}" class="w-full rounded-md border-gray-300 text-xs">
                                                <input type="number" name="sort_order" min="0" value="{{ (int) $mediaAsset->sort_order }}" aria-label="{{ __('admin.knowledge_detail.media_sort_order') }}" class="w-full rounded-md border-gray-300 text-xs">
                                                <label class="flex items-center gap-2 text-xs text-gray-600">
                                                    <input type="hidden" name="needs_review" value="0">
                                                    <input type="checkbox" name="needs_review" value="1" @checked($mediaAsset->needs_review) class="rounded border-gray-300 text-orange-600">
                                                    {{ __('admin.knowledge_detail.media_review_toggle') }}
                                                </label>
                                                <button class="inline-flex w-full items-center justify-center rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white" type="submit">{{ __('admin.knowledge_detail.media_save') }}</button>
                                            </form>
                                        </details>
                                        <form method="POST" enctype="multipart/form-data" action="{{ route('admin.knowledge-bases.media.replace', ['knowledgeBaseId' => (int) $knowledgeBase->id, 'mediaAsset' => (int) $mediaAsset->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="file" name="image" accept="image/png,image/webp" required class="min-w-0 flex-1 text-xs">
                                            <button type="submit" class="shrink-0 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-2 text-xs font-semibold text-blue-700">{{ __('admin.knowledge_detail.media_replace') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.knowledge-bases.media.toggle', ['knowledgeBaseId' => (int) $knowledgeBase->id, 'mediaAsset' => (int) $mediaAsset->id]) }}">
                                            @csrf
                                            <input type="hidden" name="active" value="{{ $mediaAsset->is_active ? '0' : '1' }}">
                                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700">
                                                {{ $mediaAsset->is_active ? __('admin.knowledge_detail.media_disable') : __('admin.knowledge_detail.media_enable') }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.knowledge_detail.chunk_count') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($chunkStats['chunk_count'] ?? 0)) }}</div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.knowledge_detail.vectorized_count') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($chunkStats['vectorized_count'] ?? 0)) }}</div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.knowledge_detail.updated_at') }}</div>
                <div class="mt-2 text-sm font-medium text-gray-900">{{ optional($knowledgeBase->updated_at)->format('Y-m-d H:i:s') ?? '-' }}</div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.common.related_tasks') }}</h3>
            </div>
            @if ($relatedTasks->isEmpty())
                <div class="px-6 py-5 text-sm text-gray-500">{{ __('admin.knowledge_detail.related_tasks_empty') }}</div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($relatedTasks as $task)
                        <div class="px-6 py-4 flex items-center justify-between">
                            <div class="text-sm text-gray-900">#{{ (int) $task->id }} {{ $task->name }}</div>
                            <div class="text-xs text-gray-500">{{ $task->status }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div id="chunk-preview" class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_detail.chunk_preview_title') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.knowledge_detail.chunk_preview_desc') }}</p>
            </div>
            @if ($chunkPreviewRows->isEmpty())
                <div class="px-6 py-8 text-sm text-gray-500">{{ __('admin.knowledge_detail.chunk_preview_empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_index') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_length') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_tokens') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_embedding') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_preview_column') }}</th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($chunkPreviewRows as $chunkRow)
                            @php
                                $isVectorized = $chunkRow['embedding_model_id'] !== null && (int) $chunkRow['embedding_dimensions'] > 0;
                            @endphp
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">#{{ (int) $chunkRow['chunk_index'] }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                    @if ($isVectorized)
                                        <span class="inline-flex px-2 py-0.5 rounded bg-green-100 text-green-700">{{ __('admin.knowledge_detail.chunk_status_vectorized') }}</span>
                                    @else
                                        <span class="inline-flex px-2 py-0.5 rounded bg-amber-100 text-amber-700">{{ __('admin.knowledge_detail.chunk_status_fallback') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ __('admin.knowledge_bases.text_unit', ['count' => (int) $chunkRow['content_length']]) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ number_format((int) $chunkRow['token_count']) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                    @if ($isVectorized)
                                        {{ __('admin.knowledge_detail.chunk_embedding_meta', ['model_id' => (int) $chunkRow['embedding_model_id'], 'dimensions' => (int) $chunkRow['embedding_dimensions']]) }}
                                    @else
                                        {{ __('admin.knowledge_detail.chunk_embedding_none') }}
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    <div class="mb-2 flex flex-wrap items-center gap-2">
                                        <span class="inline-flex rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                            {{ __('admin.knowledge_detail.chunk_strategy_label') }}:
                                            {{ __('admin.knowledge_detail.chunk_strategy_'.$chunkRow['chunk_strategy']) }}
                                        </span>
                                        @if ($chunkRow['chunk_title'] !== '')
                                            <span class="text-xs font-medium text-gray-700">{{ $chunkRow['chunk_title'] }}</span>
                                        @endif
                                    </div>
                                    @if ($chunkRow['section_path'] !== '')
                                        <div class="mb-2 text-xs text-gray-500">{{ $chunkRow['section_path'] }}</div>
                                    @endif
                                    <div class="max-w-xl break-words">{{ $chunkRow['content_preview'] }}</div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @include('admin.knowledge-bases.partials.atomic-facts-summary', ['factLibrary' => $knowledgeBase->factLibrary, 'factSummary' => $factSummary])
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/vditor/dist/index.min.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const textarea = document.getElementById('knowledge-content-textarea');
            const editorNode = document.getElementById('knowledge-content-editor');
            const form = document.getElementById('knowledge-detail-form');

            if (!textarea || !editorNode) {
                return;
            }

            if (typeof Vditor === 'undefined') {
                textarea.classList.remove('hidden');
                textarea.required = true;
                editorNode.classList.add('hidden');
                return;
            }

            let editor = null;
            let activeOutlineTargetId = '';
            let outlineObserver = null;

            const syncOutlineNavigation = () => {
                const outline = editorNode.querySelector('.vditor-outline');
                const outlineContent = outline?.querySelector('.vditor-outline__content');

                if (!outline || !outlineContent) {
                    return;
                }

                const allowedLevels = new Set(
                    (editorNode.dataset.knowledgeOutlineLevels || '1,2,3,4')
                        .split(',')
                        .map((level) => Number(level)),
                );
                const outlineTitle = outline.querySelector('.vditor-outline__title')?.textContent?.trim();

                outline.setAttribute('role', 'navigation');
                outline.setAttribute('aria-label', outlineTitle || 'H1-H4');
                outlineContent.dataset.emptyLabel = 'H1-H4';

                outlineContent.querySelectorAll('[data-target-id]').forEach((item) => {
                    const targetId = item.getAttribute('data-target-id') || '';
                    const heading = targetId ? document.getElementById(targetId) : null;
                    const level = Number(heading?.tagName?.match(/^H([1-6])$/)?.[1] || 0);

                    if (!allowedLevels.has(level)) {
                        item.closest('li')?.remove();
                        return;
                    }

                    item.setAttribute('role', 'link');
                    item.setAttribute('tabindex', '0');
                    item.setAttribute('data-heading-level', String(level));
                    item.setAttribute('aria-label', `H${level}: ${item.textContent.trim()}`);

                    if (targetId === activeOutlineTargetId) {
                        item.setAttribute('aria-current', 'location');
                    } else {
                        item.removeAttribute('aria-current');
                    }
                });

                Array.from(outlineContent.querySelectorAll('ul')).reverse().forEach((list) => {
                    if (!list.querySelector('li')) {
                        list.remove();
                    }
                });

                if (outline.dataset.knowledgeOutlineBound === 'true') {
                    return;
                }

                outline.dataset.knowledgeOutlineBound = 'true';
                outline.addEventListener('click', (event) => {
                    if (event.target.closest('.vditor-outline__action')) {
                        return;
                    }

                    const item = event.target.closest('[data-target-id]');

                    if (!item || !outline.contains(item)) {
                        return;
                    }

                    activeOutlineTargetId = item.getAttribute('data-target-id') || '';
                    syncOutlineNavigation();
                }, { capture: true });
                outline.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' && event.key !== ' ') {
                        return;
                    }

                    const item = event.target.closest('[data-target-id]');

                    if (!item || !outline.contains(item)) {
                        return;
                    }

                    event.preventDefault();
                    item.click();
                });

                outlineObserver = new MutationObserver(syncOutlineNavigation);
                outlineObserver.observe(outlineContent, { childList: true, subtree: true });
            };

            editor = new Vditor('knowledge-content-editor', {
                value: textarea.value || '',
                height: 720,
                mode: 'wysiwyg',
                cdn: @json(asset('vendor/vditor')),
                lang: @json($vditorLang),
                cache: { enable: false },
                outline: {
                    enable: true,
                    position: 'left',
                },
                preview: {
                    maxWidth: 1000,
                    markdown: { toc: true },
                    hljs: { lineNumber: false },
                },
                toolbar: [
                    'emoji',
                    'headings',
                    'bold',
                    'italic',
                    'strike',
                    '|',
                    'line',
                    'quote',
                    'list',
                    'ordered-list',
                    'check',
                    '|',
                    'code',
                    'inline-code',
                    'table',
                    'link',
                    '|',
                    'undo',
                    'redo',
                    'fullscreen',
                    'preview',
                ],
                input(value) {
                    textarea.value = value;
                },
                after() {
                    if (editor) {
                        textarea.value = editor.getValue();
                    }

                    syncOutlineNavigation();
                    window.GeoFlowAdminUi?.refreshIcons?.(editorNode);
                },
            });

            form?.addEventListener('submit', () => {
                if (editor) {
                    textarea.value = editor.getValue();
                }
            });
        });
    </script>
@endpush
