@extends('admin.layouts.app')

@php
    $hasImageUploadError = $errors->has('images') || $errors->has('images.*');
    $imageUploadErrorMessage = (string) ($errors->first('images') ?: $errors->first('images.*'));
    $imageUploadDescriptionIds = $hasImageUploadError
        ? 'image-upload-help image-upload-status image-upload-error'
        : 'image-upload-help image-upload-status';
@endphp

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-0">
        <header class="mb-6 flex items-start gap-4 sm:mb-8">
            <a href="{{ route('admin.image-libraries.detail', ['libraryId' => (int) $library->id]) }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 [@media(hover:hover)]:hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-balance break-words text-2xl font-bold text-gray-900">{{ __('admin.image_detail.modal_upload', ['name' => (string) $library->name]) }}</h1>
                <p class="mt-1 max-w-3xl text-pretty text-sm leading-6 text-gray-600">{{ __('admin.image_detail.empty_desc') }}</p>
            </div>
        </header>

        <form
            method="POST"
            action="{{ route('admin.image-libraries.images.upload', ['libraryId' => (int) $library->id]) }}"
            enctype="multipart/form-data"
            class="overflow-hidden rounded-xl bg-white shadow"
            data-image-upload-form
            data-max-upload-bytes="{{ (int) $maxUploadBytes }}"
            data-allowed-types="{{ implode(',', $uploadMimeTypes) }}"
            data-allowed-extensions="{{ implode(',', $uploadExtensions) }}"
            data-select-error="{{ __('admin.image_detail.error.select_images') }}"
            data-invalid-error="{{ __('admin.image_detail.upload_formats') }} · ≤ {{ $maxUploadMegabytes }} MB"
            data-selected-label="{{ __('admin.image_detail.selected_count', ['count' => '{count}']) }}"
            data-uploading-label="{{ __('admin.image_detail.uploading') }}"
        >
            @csrf

            <div class="border-b border-gray-200 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-600">
                        <i data-lucide="images" class="h-4.5 w-4.5"></i>
                    </span>
                    <div class="min-w-0">
                        <h2 class="break-words text-lg font-semibold text-gray-900">{{ (string) $library->name }}</h2>
                        <p class="mt-1 text-pretty text-sm leading-6 text-gray-600">{{ __('admin.image_detail.upload_formats') }} · ≤ {{ $maxUploadMegabytes }} MB</p>
                    </div>
                </div>
            </div>

            <div class="space-y-6 px-5 py-6 sm:px-6">
                <div data-image-upload-dropzone @class([
                    'rounded-xl border border-dashed p-5 sm:p-6',
                    'border-red-300 bg-red-50' => $hasImageUploadError,
                    'border-gray-300 bg-gray-50' => ! $hasImageUploadError,
                ])>
                    <label for="images" class="block text-sm font-semibold text-gray-900">{{ __('admin.image_detail.select_images') }}</label>
                    <p id="image-upload-help" class="mt-1 text-pretty text-sm leading-6 text-gray-600">{{ __('admin.image_detail.upload_hint') }}。{{ __('admin.image_detail.upload_formats') }} · ≤ {{ $maxUploadMegabytes }} MB</p>
                    <input id="images" name="images[]" type="file" multiple accept="{{ $uploadAccept }}" aria-describedby="{{ $imageUploadDescriptionIds }}" @if($hasImageUploadError) aria-invalid="true" @endif class="mt-4 block w-full min-w-0 cursor-pointer rounded-lg border border-gray-300 bg-white text-sm text-gray-700 shadow-sm file:mr-3 file:min-h-10 file:border-0 file:bg-green-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white [@media(hover:hover)]:file:hover:bg-green-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                    @if ($hasImageUploadError)
                        <p id="image-upload-error" class="mt-2 text-sm text-red-600" data-image-upload-server-error>{{ $imageUploadErrorMessage }}</p>
                    @endif
                </div>

                <div class="hidden rounded-lg bg-gray-50 p-4 ring-1 ring-inset ring-gray-200" data-image-upload-files>
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.image_detail.selected_files') }}</h3>
                    <ul class="mt-3 space-y-2" data-image-upload-file-list></ul>
                </div>

                <p id="image-upload-status" class="min-h-5 text-sm text-gray-600" role="status" aria-live="polite" data-image-upload-status></p>
            </div>

            <div class="flex flex-wrap items-center justify-start gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 sm:justify-end sm:px-6">
                <a href="{{ route('admin.image-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                    {{ __('admin.button.cancel') }}
                </a>
                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-65 disabled:active:scale-100" data-image-upload-submit>
                    <i data-lucide="upload" class="mr-2 h-4 w-4"></i>
                    <span data-image-upload-submit-label>{{ __('admin.button.upload') }}</span>
                </button>
            </div>
        </form>
    </div>
@endsection
