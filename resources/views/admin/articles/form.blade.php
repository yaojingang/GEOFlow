@extends('admin.layouts.app')

@php
    $i18nRoot = $isEdit ? 'admin.article_edit' : 'admin.article_create';
    $formAction = $isEdit
        ? route('admin.articles.update', ['articleId' => (int) $articleId])
        : route('admin.articles.store');
    $articleImageUploadUrl = $isEdit
        ? route('admin.articles.editor.images.upload', ['articleId' => (int) $articleId], false)
        : '';
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

                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.content_title') }}</h3>
                                <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">{{ __($i18nRoot.'.help.markdown_supported') }}</span>
                            </div>
                            <p class="mt-2 text-sm text-gray-600">{{ __('admin.article_editor.editor_desc') }}</p>
                        </div>
                        <div class="px-6 py-4">
                            <textarea id="content-textarea" name="content" class="hidden">{{ $formData['content'] }}</textarea>
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
                                <div class="rounded-md bg-gray-50 px-3 py-2">{{ __('admin.article_editor.help.crop') }}</div>
                            </div>
                        </div>
                    </div>

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

                    @if($isEdit)
                        <div class="bg-white shadow rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.article_edit.section.info_title') }}</h3>
                            </div>
                            <div class="px-6 py-4 text-sm text-gray-600 space-y-2">
                                <div>{{ __('admin.article_edit.info.article_id') }}: #{{ (int) $articleId }}</div>
                                <div>{{ __('admin.article_edit.info.slug') }}: {{ $formData['slug'] }}</div>
                                <div>{{ __('admin.article_edit.info.source_task') }}: {{ $formData['task_name'] !== '' ? $formData['task_name'] : __('admin.article_edit.info.manual_source') }}</div>
                                <div>{{ __('admin.article_edit.info.published_at') }}: {{ $formData['published_at'] !== '' ? $formData['published_at'] : '-' }}</div>
                            </div>
                        </div>
                    @endif

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
    <link rel="stylesheet" href="{{ asset('vendor/cropperjs/cropper.min.css') }}">
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
        .article-image-modal[aria-hidden="true"] {
            display: none;
        }
        .article-image-modal__backdrop {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.52);
            padding: 24px;
        }
        .article-image-modal__panel {
            width: min(920px, 100%);
            max-height: min(760px, 92vh);
            overflow: hidden;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 24px 80px rgba(15, 23, 42, 0.24);
        }
        .article-image-crop-stage {
            display: flex;
            min-height: 320px;
            max-height: 430px;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: #f8fafc;
            overflow: hidden;
        }
        .article-image-crop-stage img {
            display: block;
            max-width: 100%;
            max-height: 430px;
        }
        .article-image-status[data-tone="error"] {
            color: #b91c1c;
        }
        .article-image-status[data-tone="success"] {
            color: #047857;
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
    <script src="{{ asset('vendor/cropperjs/cropper.min.js') }}"></script>
    <div id="article-image-modal" class="article-image-modal" aria-hidden="true">
        <div class="article-image-modal__backdrop" data-image-modal-close>
            <div class="article-image-modal__panel" role="dialog" aria-modal="true" aria-labelledby="article-image-modal-title" data-image-modal-panel>
                <div class="border-b border-gray-200 px-6 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 id="article-image-modal-title" class="text-lg font-semibold text-gray-900">{{ __('admin.article_editor.image_modal.title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.article_editor.image_modal.desc') }}</p>
                        </div>
                        <button type="button" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600" data-image-modal-close aria-label="{{ __('admin.button.cancel') }}">
                            <i data-lucide="x" class="h-5 w-5"></i>
                        </button>
                    </div>
                </div>
                <div class="grid gap-6 px-6 py-5 lg:grid-cols-[minmax(0,1fr)_280px]">
                    <div class="article-image-crop-stage">
                        <img id="article-image-crop-target" alt="">
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label for="article-image-alt" class="block text-sm font-medium text-gray-700">{{ __('admin.article_editor.image_modal.alt_label') }}</label>
                            <input id="article-image-alt" type="text" maxlength="120" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.article_editor.image_modal.alt_placeholder') }}">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_editor.image_modal.alt_help') }}</p>
                        </div>
                        <label class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
                            <input id="article-image-crop-enabled" type="checkbox" checked class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span>
                                <span class="block font-medium text-gray-900">{{ __('admin.article_editor.image_modal.crop_label') }}</span>
                                <span class="mt-1 block text-xs text-gray-500">{{ __('admin.article_editor.image_modal.crop_help') }}</span>
                            </span>
                        </label>
                        <div id="article-image-status" class="article-image-status min-h-[1.25rem] text-sm" data-tone=""></div>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-6 py-4">
                    <button type="button" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" data-image-modal-close>
                        {{ __('admin.button.cancel') }}
                    </button>
                    <button type="button" id="article-image-upload-original" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="image" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.article_editor.image_modal.upload_original') }}
                    </button>
                    <button type="button" id="article-image-upload-cropped" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="crop" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.article_editor.image_modal.upload_cropped') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const textarea = document.getElementById('content-textarea');
            const editorNode = document.getElementById('content-editor');
            const form = textarea ? textarea.closest('form') : null;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const uploadUrl = editorNode?.dataset.uploadUrl || '';
            const uploadEnabled = editorNode?.dataset.uploadEnabled === '1' && uploadUrl !== '';
            const cropperScriptUrl = @json(asset('vendor/cropperjs/cropper.min.js'));
            const modal = document.getElementById('article-image-modal');
            const cropTarget = document.getElementById('article-image-crop-target');
            const altInput = document.getElementById('article-image-alt');
            const cropEnabledInput = document.getElementById('article-image-crop-enabled');
            const statusNode = document.getElementById('article-image-status');
            const uploadOriginalButton = document.getElementById('article-image-upload-original');
            const uploadCroppedButton = document.getElementById('article-image-upload-cropped');
            const quickImageInput = document.getElementById('article-editor-quick-image-input');
            const contextMenu = document.getElementById('article-editor-context-menu');
            let editor = null;
            let currentFile = null;
            let currentObjectUrl = null;
            let cropper = null;
            let cropperLoadPromise = null;
            let modalSequence = 0;
            let uploading = false;
            let savedEditorRange = null;

            const messages = {
                uploadDisabled: @json(__('admin.article_editor.error.upload_disabled')),
                imageRequired: @json(__('admin.article_editor.error.image_required')),
                imageInvalid: @json(__('admin.article_editor.error.image_invalid')),
                uploadFailed: @json(__('admin.article_editor.error.upload_failed_generic')),
                cropUnavailable: @json(__('admin.article_editor.error.crop_unavailable')),
                uploading: @json(__('admin.article_editor.message.uploading')),
                uploadSuccess: @json(__('admin.article_editor.message.upload_success')),
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

            function setStatus(message, tone) {
                if (!statusNode) {
                    return;
                }
                statusNode.textContent = message || '';
                statusNode.dataset.tone = tone || '';
            }

            function getRangeContainer(range) {
                if (!range) {
                    return null;
                }

                return range.commonAncestorContainer.nodeType === Node.ELEMENT_NODE
                    ? range.commonAncestorContainer
                    : range.commonAncestorContainer.parentElement;
            }

            function isEditorRange(range) {
                const container = getRangeContainer(range);
                return Boolean(container && editorNode.contains(container));
            }

            function saveEditorRange() {
                const selection = window.getSelection();
                if (!selection || selection.rangeCount === 0) {
                    return;
                }

                const range = selection.getRangeAt(0);
                if (!isEditorRange(range)) {
                    return;
                }

                savedEditorRange = range.cloneRange();
            }

            function restoreEditorRange() {
                if (
                    !savedEditorRange
                    || !document.contains(savedEditorRange.startContainer)
                    || !document.contains(savedEditorRange.endContainer)
                    || !isEditorRange(savedEditorRange)
                ) {
                    editor.focus();
                    return false;
                }

                editor.focus();
                const selection = window.getSelection();
                if (!selection) {
                    return false;
                }

                selection.removeAllRanges();
                selection.addRange(savedEditorRange);

                return true;
            }

            function showEditorTip(message) {
                if (!message) {
                    return;
                }

                if (editor && typeof editor.tip === 'function') {
                    editor.tip(message, 2600);
                    return;
                }

                setStatus(message, 'error');
            }

            function hideContextMenu() {
                if (contextMenu) {
                    contextMenu.hidden = true;
                }
            }

            function showContextMenu(event) {
                if (!contextMenu) {
                    return;
                }

                saveEditorRange();
                const menuWidth = 220;
                const menuHeight = 260;
                const left = Math.min(event.clientX, window.innerWidth - menuWidth - 12);
                const top = Math.min(event.clientY, window.innerHeight - menuHeight - 12);
                contextMenu.style.left = Math.max(12, left) + 'px';
                contextMenu.style.top = Math.max(12, top) + 'px';
                contextMenu.hidden = false;

                if (window.lucide) {
                    window.lucide.createIcons();
                }
            }

            function destroyCropper() {
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
            }

            function ensureCropperLoaded() {
                if (typeof window.Cropper !== 'undefined') {
                    return Promise.resolve(window.Cropper);
                }
                if (cropperLoadPromise) {
                    return cropperLoadPromise;
                }

                cropperLoadPromise = new Promise(function (resolve, reject) {
                    const script = document.createElement('script');
                    script.src = cropperScriptUrl;
                    script.async = true;
                    script.onload = function () {
                        if (typeof window.Cropper !== 'undefined') {
                            resolve(window.Cropper);
                            return;
                        }
                        reject(new Error(messages.cropUnavailable));
                    };
                    script.onerror = function () {
                        reject(new Error(messages.cropUnavailable));
                    };
                    document.head.appendChild(script);
                }).catch(function (error) {
                    cropperLoadPromise = null;
                    throw error;
                });

                return cropperLoadPromise;
            }

            function closeModal() {
                modalSequence++;
                destroyCropper();
                if (currentObjectUrl) {
                    URL.revokeObjectURL(currentObjectUrl);
                    currentObjectUrl = null;
                }
                currentFile = null;
                if (cropTarget) {
                    cropTarget.onload = null;
                    cropTarget.removeAttribute('src');
                }
                if (modal) {
                    modal.setAttribute('aria-hidden', 'true');
                }
                setStatus('', '');
            }

            function openImageModal(file) {
                saveEditorRange();
                if (!uploadEnabled) {
                    return messages.uploadDisabled;
                }
                if (!file || !file.type || !file.type.startsWith('image/')) {
                    return messages.imageInvalid;
                }

                closeModal();
                const sequence = ++modalSequence;
                currentFile = file;
                currentObjectUrl = URL.createObjectURL(file);
                if (altInput) {
                    altInput.value = file.name ? file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ') : '';
                }
                if (cropTarget) {
                    cropTarget.src = currentObjectUrl;
                    cropTarget.onload = async function () {
                        destroyCropper();
                        try {
                            const CropperConstructor = await ensureCropperLoaded();
                            if (sequence !== modalSequence || !currentFile || cropTarget.src !== currentObjectUrl) {
                                return;
                            }
                            cropper = new CropperConstructor(cropTarget, {
                                autoCropArea: 0.88,
                                background: false,
                                viewMode: 1,
                            });
                        } catch (error) {
                            setStatus(error.message || messages.cropUnavailable, 'error');
                        }
                    };
                }
                if (modal) {
                    modal.setAttribute('aria-hidden', 'false');
                }
                setStatus('', '');

                return null;
            }

            function fileFromCanvas(canvas) {
                return new Promise(function (resolve) {
                    canvas.toBlob(function (blob) {
                        if (!blob) {
                            resolve(null);
                            return;
                        }
                        const extension = blob.type === 'image/png' ? 'png' : 'jpg';
                        const baseName = currentFile?.name ? currentFile.name.replace(/\.[^.]+$/, '') : 'article-image';
                        resolve(new File([blob], baseName + '-cropped.' + extension, { type: blob.type || 'image/jpeg' }));
                    }, 'image/jpeg', 0.9);
                });
            }

            function insertMarkdown(markdown) {
                if (!markdown) {
                    return;
                }
                restoreEditorRange();
                editor.insertValue('\n\n' + markdown + '\n\n');
                textarea.value = editor.getValue();
                window.requestAnimationFrame(saveEditorRange);
            }

            function triggerImagePicker() {
                saveEditorRange();
                if (!uploadEnabled) {
                    showEditorTip(messages.uploadDisabled);
                    return;
                }
                if (!quickImageInput) {
                    return;
                }

                quickImageInput.value = '';
                quickImageInput.click();
            }

            function runEditorAction(action) {
                hideContextMenu();

                if (action === 'image') {
                    triggerImagePicker();
                    return;
                }

                if (snippets[action]) {
                    insertMarkdown(snippets[action]);
                }
            }

            async function uploadImageFile(file) {
                if (!file) {
                    setStatus(messages.imageRequired, 'error');
                    return;
                }

                uploading = true;
                uploadOriginalButton.disabled = true;
                uploadCroppedButton.disabled = true;
                setStatus(messages.uploading, '');

                const formData = new FormData();
                formData.append('image', file);
                formData.append('alt', altInput?.value || '');
                formData.append('position', String((textarea.value || '').length));

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
                    const payload = await response.json().catch(function () {
                        return {};
                    });
                    if (!response.ok) {
                        throw new Error(payload.message || messages.uploadFailed);
                    }
                    insertMarkdown(payload.image?.markdown || '');
                    setStatus(payload.message || messages.uploadSuccess, 'success');
                    setTimeout(closeModal, 350);
                } catch (error) {
                    setStatus(error.message || messages.uploadFailed, 'error');
                } finally {
                    uploading = false;
                    uploadOriginalButton.disabled = false;
                    uploadCroppedButton.disabled = false;
                }
            }

            editor = new Vditor('content-editor', {
                value: textarea.value || '',
                height: 560,
                mode: 'ir',
                cdn: @json(asset('vendor/vditor')),
                lang: @json($vditorLang),
                cache: {
                    enable: false,
                },
                preview: {
                    markdown: {
                        toc: true,
                    },
                    hljs: {
                        lineNumber: false,
                    },
                },
                toolbar: [
                    'emoji', 'headings', 'bold', 'italic', 'strike', '|',
                    'line', 'quote', 'list', 'ordered-list', 'check', '|',
                    'code', 'inline-code', 'table', 'link', 'upload', '|',
                    'undo', 'redo', 'fullscreen', 'preview', 'both',
                ],
                upload: {
                    accept: 'image/*',
                    multiple: false,
                    max: 10 * 1024 * 1024,
                    handler: async function (files) {
                        saveEditorRange();
                        const file = files && files.length > 0 ? files[0] : null;
                        return openImageModal(file);
                    },
                },
                input: function (value) {
                    textarea.value = value;
                    window.requestAnimationFrame(saveEditorRange);
                },
                after: function () {
                    textarea.value = editor.getValue();
                    saveEditorRange();
                    if (window.lucide) {
                        window.lucide.createIcons();
                    }
                },
            });

            if (form) {
                form.addEventListener('submit', function () {
                    if (editor) {
                        textarea.value = editor.getValue();
                    }
                });
            }

            ['keyup', 'mouseup', 'focusin'].forEach(function (eventName) {
                editorNode.addEventListener(eventName, saveEditorRange);
            });

            document.addEventListener('selectionchange', function () {
                saveEditorRange();
            });

            editorNode.addEventListener('contextmenu', function (event) {
                if (!event.target.closest('.vditor-ir, .vditor-wysiwyg, .vditor-sv, .vditor-reset')) {
                    return;
                }

                event.preventDefault();
                showContextMenu(event);
            });

            document.querySelectorAll('[data-editor-action]').forEach(function (node) {
                node.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                });
                node.addEventListener('click', function () {
                    saveEditorRange();
                    runEditorAction(node.dataset.editorAction || '');
                });
            });

            document.addEventListener('click', function (event) {
                if (!contextMenu || contextMenu.hidden) {
                    return;
                }
                if (contextMenu.contains(event.target)) {
                    return;
                }
                hideContextMenu();
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    hideContextMenu();
                }
            });

            quickImageInput?.addEventListener('change', function (event) {
                const file = event.target.files && event.target.files.length > 0 ? event.target.files[0] : null;
                if (file) {
                    openImageModal(file);
                }
                quickImageInput.value = '';
            });

            document.querySelectorAll('[data-image-modal-close]').forEach(function (node) {
                node.addEventListener('click', function (event) {
                    if (uploading) {
                        return;
                    }
                    if (node.classList.contains('article-image-modal__backdrop') && event.target !== node) {
                        return;
                    }
                    closeModal();
                });
            });

            uploadOriginalButton?.addEventListener('click', function () {
                uploadImageFile(currentFile);
            });

            uploadCroppedButton?.addEventListener('click', async function () {
                if (!cropEnabledInput?.checked || !cropper) {
                    uploadImageFile(currentFile);
                    return;
                }
                const canvas = cropper.getCroppedCanvas({
                    maxWidth: 2400,
                    maxHeight: 2400,
                    imageSmoothingQuality: 'high',
                });
                const croppedFile = canvas ? await fileFromCanvas(canvas) : null;
                uploadImageFile(croppedFile || currentFile);
            });
        })();
    </script>
@endpush
