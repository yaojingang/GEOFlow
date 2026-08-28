@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.image-libraries.update', ['libraryId' => (int) $libraryId])
        : route('admin.image-libraries.store');
    $libraryName = is_string($libraryForm['name'] ?? null) || is_numeric($libraryForm['name'] ?? null)
        ? (string) $libraryForm['name']
        : '';
    $returnContext = $isEdit && ($editContext ?? null) === 'detail' ? 'detail' : 'index';
    $returnRoute = $returnContext === 'detail'
        ? route('admin.image-libraries.detail', ['libraryId' => (int) $libraryId])
        : route('admin.image-libraries.index');
@endphp

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-0">
        <header class="mb-6 flex items-start gap-4 sm:mb-8">
            <a href="{{ $returnRoute }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 [@media(hover:hover)]:hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-balance break-words text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.button.edit').' · '.$libraryName : __('admin.image_libraries.modal_create') }}</h1>
                <p class="mt-1 max-w-3xl text-pretty text-sm leading-6 text-gray-600">{{ __('admin.image_libraries.subtitle') }}</p>
            </div>
        </header>

        <form method="POST" action="{{ $formAction }}" class="overflow-hidden rounded-xl bg-white shadow">
            @csrf
            @if ($isEdit)
                @method('PUT')
                <input type="hidden" name="context" value="{{ $returnContext }}">
            @endif

            <div class="border-b border-gray-200 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                        <i data-lucide="{{ $isEdit ? 'folder-pen' : 'folder-plus' }}" class="h-4.5 w-4.5"></i>
                    </span>
                    <div class="min-w-0">
                        <h2 class="break-words text-lg font-semibold text-gray-900">{{ $isEdit ? $libraryName : __('admin.image_libraries.modal_create') }}</h2>
                        <p class="mt-1 text-pretty text-sm leading-6 text-gray-600">{{ __('admin.image_libraries.subtitle') }}</p>
                    </div>
                </div>
            </div>

            <div class="px-5 py-6 sm:px-6">
                <x-admin.image-library-form-fields :library-form="$libraryForm" />
            </div>

            <div class="flex flex-wrap items-center justify-start gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 sm:justify-end sm:px-6">
                <a href="{{ $returnRoute }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    {{ __('admin.button.cancel') }}
                </a>
                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.button.save') }}
                </button>
            </div>
        </form>
    </div>
@endsection
