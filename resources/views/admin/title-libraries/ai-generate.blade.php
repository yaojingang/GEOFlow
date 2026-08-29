@extends('admin.layouts.app')

@php
    $normalizeScalar = static fn (mixed $value, string $fallback = ''): string => is_string($value) || is_int($value) || is_float($value)
        ? (string) $value
        : $fallback;
    $keywordLibraryValue = $normalizeScalar(old('keyword_library_id'));
    $aiModelValue = $normalizeScalar(old('ai_model_id'));
    $oldTitleCount = old('title_count', 10);
    $titleCountValue = $normalizeScalar($oldTitleCount, '10');
    $styleValue = $normalizeScalar(old('title_style', 'professional'), 'professional');
    $styleValue = in_array($styleValue, ['professional', 'attractive', 'seo', 'creative', 'question'], true)
        ? $styleValue
        : 'professional';
    $customPromptValue = $normalizeScalar(old('custom_prompt'));
    $confirmedLargeRun = in_array(old('confirmed_large_run'), [1, '1'], true);
@endphp

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-0">
        <div class="mb-6 flex items-start gap-4 sm:mb-8">
            <a href="{{ route('admin.title-libraries.detail', ['libraryId' => (int) $library->id]) }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 [@media(hover:hover)]:hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-balance break-words text-2xl font-bold text-gray-900">{{ __('admin.title_ai_generate.page_heading') }}</h1>
                <p class="mt-1 max-w-3xl text-pretty text-sm leading-6 text-gray-600">{{ __('admin.title_ai_generate.page_subtitle', ['name' => $library->name]) }}</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl bg-white shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.title_ai_generate.section.config') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.title_ai_generate.section.config_desc') }}</p>
            </div>
            <form method="POST" action="{{ route('admin.title-libraries.ai-generate.submit', ['libraryId' => (int) $library->id]) }}" class="p-6 space-y-6" data-title-generation-form>
                @csrf
                <input type="hidden" name="confirmed_keyword_reuse" value="0" data-keyword-reuse-confirmed>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="title-generation-keyword-library" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.title_ai_generate.field.keyword_library') }}</label>
                        <select id="title-generation-keyword-library" name="keyword_library_id" class="block min-h-10 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-green-500 focus:ring-green-500" required>
                            <option value="">{{ __('admin.title_ai_generate.option.select_keyword_library') }}</option>
                            @foreach ($keywordLibraries as $keywordLibrary)
                                <option value="{{ (int) $keywordLibrary->id }}" data-keyword-count="{{ (int) ($keywordLibrary->keyword_count ?? 0) }}" @selected($keywordLibraryValue === (string) $keywordLibrary->id)>
                                    {{ $keywordLibrary->name }} ({{ (int) ($keywordLibrary->keyword_count ?? 0) }} {{ __('admin.title_ai_generate.option.keyword_count_suffix') }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="title-generation-ai-model" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.title_ai_generate.field.ai_model') }}</label>
                        <select id="title-generation-ai-model" name="ai_model_id" class="block min-h-10 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-green-500 focus:ring-green-500" required>
                            <option value="">{{ __('admin.title_ai_generate.option.select_ai_model') }}</option>
                            @foreach ($aiModels as $aiModel)
                                <option value="{{ (int) $aiModel->id }}" @selected($aiModelValue === (string) $aiModel->id)>
                                    {{ $aiModel->name }} ({{ $aiModel->model_id }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="title-generation-count" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.title_ai_generate.field.count') }}</label>
                        <input id="title-generation-count" type="number" name="title_count" value="{{ $titleCountValue }}" min="1" max="{{ $maxTitleCount }}" class="block min-h-10 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-green-500 focus:ring-green-500" required>
                        <p class="mt-2 text-xs text-gray-500">{{ __('admin.title_ai_generate.help.count', ['max' => number_format($maxTitleCount)]) }}</p>
                        <label class="mt-3 flex items-start gap-2 text-xs text-gray-600">
                            <input type="checkbox" name="confirmed_large_run" value="1" @checked($confirmedLargeRun) class="mt-0.5 rounded border-gray-300 text-green-600 focus:ring-green-500">
                            <span>{{ __('admin.title_ai_generate.help.large_run_confirmation', ['count' => number_format((int) config('geoflow.title_ai_confirmation_threshold', 1000))]) }}</span>
                        </label>
                    </div>
                    <div>
                        <label for="title-generation-style" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.title_ai_generate.field.style') }}</label>
                        <select id="title-generation-style" name="title_style" class="block min-h-10 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-green-500 focus:ring-green-500" required>
                            @foreach (['professional', 'attractive', 'seo', 'creative', 'question'] as $style)
                                <option value="{{ $style }}" @selected($styleValue === $style)>
                                    {{ __('admin.title_ai_generate.style.'.$style) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label for="title-generation-custom-prompt" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.title_ai_generate.field.custom_prompt') }}</label>
                    <textarea id="title-generation-custom-prompt" name="custom_prompt" rows="4" class="block w-full resize-y rounded-lg border-gray-300 text-sm leading-6 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="{{ __('admin.title_ai_generate.placeholder.custom_prompt') }}">{{ $customPromptValue }}</textarea>
                </div>
                <div class="flex justify-start sm:justify-end">
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-green-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2" data-title-generation-submit>
                        <i data-lucide="sparkles" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.title_ai_generate.button.async') }}
                    </button>
                </div>
            </form>
        </div>

        <dialog
            class="fixed inset-0 m-auto w-[min(560px,calc(100vw-2rem))] max-w-none overflow-hidden overscroll-contain rounded-2xl border-0 bg-white p-0 text-left text-gray-900 shadow-[0_24px_72px_rgba(15,23,42,0.28)] backdrop:bg-gray-950/45"
            data-keyword-reuse-dialog
            data-summary-template="{{ __('admin.title_ai_generate.keyword_reuse_dialog.summary', ['title_count' => '__TITLE_COUNT__', 'keyword_count' => '__KEYWORD_COUNT__']) }}"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="keyword-reuse-dialog-title"
            aria-describedby="keyword-reuse-dialog-summary keyword-reuse-dialog-risk"
        >
            <div class="flex max-h-[min(720px,calc(100vh-2rem))] flex-col">
                <div class="flex items-start gap-4 px-5 pb-4 pt-5 sm:px-6 sm:pt-6">
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                        <i data-lucide="repeat-2" class="h-5 w-5"></i>
                    </span>
                    <div class="min-w-0">
                        <h2 id="keyword-reuse-dialog-title" class="text-lg font-semibold text-gray-950">
                            {{ __('admin.title_ai_generate.keyword_reuse_dialog.title') }}
                        </h2>
                        <p id="keyword-reuse-dialog-summary" class="mt-1.5 text-sm leading-6 text-gray-600" data-keyword-reuse-summary></p>
                    </div>
                </div>

                <div class="overflow-y-auto px-5 pb-5 sm:px-6">
                    <div class="rounded-xl bg-amber-50 px-4 py-3.5 text-amber-950">
                        <p id="keyword-reuse-dialog-risk" class="text-sm font-medium leading-6">
                            {{ __('admin.title_ai_generate.keyword_reuse_dialog.risk') }}
                        </p>
                        <p class="mt-1 text-xs leading-5 text-amber-900/80">
                            {{ __('admin.title_ai_generate.keyword_reuse_dialog.polling') }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                    <button type="button" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[color,background-color,transform] duration-150 hover:bg-gray-100 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 focus-visible:ring-offset-2" data-keyword-reuse-cancel>
                        {{ __('admin.title_ai_generate.keyword_reuse_dialog.cancel') }}
                    </button>
                    <button type="button" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2" data-keyword-reuse-confirm>
                        {{ __('admin.title_ai_generate.keyword_reuse_dialog.confirm') }}
                    </button>
                </div>
            </div>
        </dialog>

        <div class="mt-6 rounded-xl border border-blue-200 bg-blue-50 p-5">
            <h3 class="text-sm font-semibold text-blue-900">{{ __('admin.title_ai_generate.section.instructions') }}</h3>
            <ul class="mt-3 space-y-1 text-sm leading-6 text-blue-800">
                <li>{{ __('admin.title_ai_generate.instructions.keyword_library') }}</li>
                <li>{{ __('admin.title_ai_generate.instructions.ai_model') }}</li>
                <li>{{ __('admin.title_ai_generate.instructions.count', ['max' => number_format($maxTitleCount)]) }}</li>
                <li>{{ __('admin.title_ai_generate.instructions.style') }}</li>
                <li>{{ __('admin.title_ai_generate.instructions.custom_prompt') }}</li>
                <li>{{ __('admin.title_ai_generate.instructions.saved_titles') }}</li>
            </ul>
        </div>
    </div>
@endsection
