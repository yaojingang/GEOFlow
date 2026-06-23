@extends('admin.layouts.app')

@php
    $pageLabel = $pageOptions[$page] ?? $page;
    $previewUrl = route('admin.site-settings.theme-editor.preview', ['themeId' => $themeId, 'page' => $page], false);
    $draftUrl = route('admin.site-settings.theme-editor.draft', ['themeId' => $themeId, 'page' => $page], false);
    $publishUrl = route('admin.site-settings.theme-editor.publish', ['themeId' => $themeId, 'page' => $page], false);
    $discardUrl = route('admin.site-settings.theme-editor.discard', ['themeId' => $themeId, 'page' => $page], false);
    $themeEditorEndpoints = [
        'preview' => $previewUrl,
        'draft' => $draftUrl,
        'publish' => $publishUrl,
        'discard' => $discardUrl,
    ];
    $themeEditorMessages = [
        'saving' => __('admin.theme_editor.saving'),
        'saved' => __('admin.theme_editor.draft_saved'),
        'unsaved' => __('admin.theme_editor.unsaved'),
        'saveFailed' => __('admin.theme_editor.save_failed'),
        'publishConfirm' => __('admin.theme_editor.publish_confirm'),
        'discardConfirm' => __('admin.theme_editor.discard_confirm'),
        'publishSuccess' => __('admin.theme_editor.publish_success'),
        'discardSuccess' => __('admin.theme_editor.discard_success'),
    ];
@endphp

@section('title', __('admin.theme_editor.page_title'))

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.site-settings.index', [], false) }}" class="mt-2 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.theme_editor.heading') }}</h1>
                        <span class="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700">{{ $pageLabel }}</span>
                    </div>
                    <p class="mt-2 text-sm text-gray-600">
                        {{ __('admin.theme_editor.subtitle', ['theme' => $themeId, 'page' => $pageLabel]) }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs text-gray-500">
                        <span class="rounded-full bg-gray-100 px-3 py-1">{{ $source['blade_path'] }}</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1">{{ $source['css_path'] }}</span>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach ($pageOptions as $optionPage => $optionLabel)
                    <a href="{{ route('admin.site-settings.theme-editor.edit', ['themeId' => $themeId, 'page' => $optionPage], false) }}"
                       class="inline-flex items-center rounded-lg border px-4 py-2 text-sm font-semibold {{ $optionPage === $page ? 'border-blue-600 bg-blue-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                        {{ $optionLabel }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            <div class="font-semibold">{{ __('admin.theme_editor.warning_title') }}</div>
            <div class="mt-1 leading-6">{{ __('admin.theme_editor.warning_desc') }}</div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_520px]">
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">{{ __('admin.theme_editor.preview_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.theme_editor.preview_help') }}</p>
                    </div>
                    <button type="button" id="theme-editor-refresh" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.theme_editor.refresh_preview') }}
                    </button>
                </div>
                <div class="bg-gray-100 p-3">
                    <iframe id="theme-editor-preview"
                            src="{{ $previewUrl }}"
                            class="h-[760px] w-full rounded-xl border border-gray-200 bg-white"
                            loading="lazy"
                            referrerpolicy="no-referrer"></iframe>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">{{ __('admin.theme_editor.source_label') }}</h2>
                            <p id="theme-editor-status" class="mt-1 text-sm text-gray-500">
                                {{ $source['draft_exists'] ? __('admin.theme_editor.draft_saved') : __('admin.theme_editor.ready') }}
                            </p>
                        </div>
                        <span id="theme-editor-save-indicator" class="hidden rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                            {{ __('admin.theme_editor.saving') }}
                        </span>
                    </div>
                </div>

                <div class="space-y-5 p-5">
                    <div>
                        <label for="theme-editor-blade" class="block text-sm font-semibold text-gray-800">{{ __('admin.theme_editor.blade_title') }}</label>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.theme_editor.blade_help') }}</p>
                        <textarea id="theme-editor-blade"
                                  class="mt-3 h-[360px] w-full rounded-xl border border-gray-300 bg-gray-950 px-4 py-3 font-mono text-xs leading-6 text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                  spellcheck="false">{{ $source['blade'] }}</textarea>
                    </div>

                    <div>
                        <label for="theme-editor-css" class="block text-sm font-semibold text-gray-800">{{ __('admin.theme_editor.css_title') }}</label>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.theme_editor.css_help') }}</p>
                        <textarea id="theme-editor-css"
                                  class="mt-3 h-[260px] w-full rounded-xl border border-gray-300 bg-gray-950 px-4 py-3 font-mono text-xs leading-6 text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                  spellcheck="false">{{ $source['css'] }}</textarea>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-gray-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <button type="button" id="theme-editor-discard" class="inline-flex items-center justify-center rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">
                            <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.theme_editor.discard') }}
                        </button>
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <button type="button" id="theme-editor-save" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.theme_editor.save_draft') }}
                            </button>
                            <button type="button" id="theme-editor-publish" class="inline-flex items-center justify-center rounded-lg border border-transparent bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                                <i data-lucide="upload-cloud" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.theme_editor.publish') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            const endpoints = @json($themeEditorEndpoints);
            const messages = @json($themeEditorMessages);

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const bladeInput = document.getElementById('theme-editor-blade');
            const cssInput = document.getElementById('theme-editor-css');
            const preview = document.getElementById('theme-editor-preview');
            const status = document.getElementById('theme-editor-status');
            const indicator = document.getElementById('theme-editor-save-indicator');
            const saveButton = document.getElementById('theme-editor-save');
            const refreshButton = document.getElementById('theme-editor-refresh');
            const publishButton = document.getElementById('theme-editor-publish');
            const discardButton = document.getElementById('theme-editor-discard');
            let saveTimer = null;
            let refreshTimer = null;
            let dirty = false;
            let saving = false;

            function setStatus(message, type = 'muted') {
                status.textContent = message;
                status.className = 'mt-1 text-sm ' + (type === 'error' ? 'text-red-600' : (type === 'success' ? 'text-green-600' : 'text-gray-500'));
            }

            function setSaving(active) {
                saving = active;
                indicator.classList.toggle('hidden', !active);
            }

            function payload() {
                return {
                    blade: bladeInput.value,
                    css: cssInput.value,
                };
            }

            async function postJson(url, body) {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(body || {}),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const message = data.message || Object.values(data.errors || {}).flat()[0] || messages.saveFailed;
                    throw new Error(message);
                }
                return data;
            }

            function refreshPreview() {
                preview.src = endpoints.preview + '?t=' + Date.now();
            }

            async function saveDraft({ refresh = true } = {}) {
                clearTimeout(saveTimer);
                setSaving(true);
                try {
                    const data = await postJson(endpoints.draft, payload());
                    dirty = false;
                    setStatus((data.message || messages.saved) + (data.updated_at ? ' · ' + data.updated_at : ''), 'success');
                    if (refresh) {
                        refreshPreview();
                    }
                } catch (error) {
                    setStatus(error.message || messages.saveFailed, 'error');
                } finally {
                    setSaving(false);
                }
            }

            function scheduleSave() {
                dirty = true;
                setStatus(messages.unsaved);
                clearTimeout(saveTimer);
                clearTimeout(refreshTimer);
                saveTimer = setTimeout(() => saveDraft({ refresh: false }), 900);
                refreshTimer = setTimeout(refreshPreview, 1300);
            }

            bladeInput.addEventListener('input', scheduleSave);
            cssInput.addEventListener('input', scheduleSave);
            saveButton.addEventListener('click', () => saveDraft());
            refreshButton.addEventListener('click', refreshPreview);
            publishButton.addEventListener('click', async () => {
                if (!window.confirm(messages.publishConfirm)) {
                    return;
                }
                setSaving(true);
                try {
                    const data = await postJson(endpoints.publish, payload());
                    dirty = false;
                    setStatus((data.message || messages.publishSuccess) + (data.updated_at ? ' · ' + data.updated_at : ''), 'success');
                    refreshPreview();
                } catch (error) {
                    setStatus(error.message || messages.saveFailed, 'error');
                } finally {
                    setSaving(false);
                }
            });
            discardButton.addEventListener('click', async () => {
                if (!window.confirm(messages.discardConfirm)) {
                    return;
                }
                setSaving(true);
                try {
                    const data = await postJson(endpoints.discard, {});
                    dirty = false;
                    setStatus(data.message || messages.discardSuccess, 'success');
                    window.location.reload();
                } catch (error) {
                    setStatus(error.message || messages.saveFailed, 'error');
                    setSaving(false);
                }
            });

            window.addEventListener('beforeunload', function (event) {
                if (dirty || saving) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
        });
    </script>
@endpush
