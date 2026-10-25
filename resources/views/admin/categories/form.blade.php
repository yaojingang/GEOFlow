@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.categories.update', ['categoryId' => (int) $categoryId])
        : route('admin.categories.store');
@endphp

@section('content')
    <div class="px-4 sm:px-0" data-url-separate-editor data-has-unsaved-input="{{ session()->hasOldInput('name') ? '1' : '0' }}">
        <header class="mb-6 flex flex-col gap-4 sm:mb-8 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.categories.edit_form') : __('admin.categories.add_form') }}</h1>
                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.categories.subtitle') }}</p>
            </div>
            <a href="{{ route('admin.articles.index') }}" class="inline-flex min-h-10 w-fit items-center gap-2 rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-gray-400 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                {{ __('admin.categories.back_to_articles') }}
            </a>
        </header>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-6">
                <form method="POST" action="{{ $formAction }}" class="space-y-6" data-url-source-form>
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.categories.field_name') }}</label>
                            <input type="text" name="name" required value="{{ old('name', (string) ($categoryForm['name'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.categories.placeholder_name') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.categories.field_slug') }}</label>
                            @if ($isEdit)
                                <code class="block min-h-10 break-all rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-800">{{ $categoryForm['slug'] ?? '' }}</code>
                                <p class="mt-2 text-xs leading-5 text-gray-600">{{ __('url_change.ui.stable') }}</p>
                            @else
                                <input type="text" name="slug" value="{{ old('slug', (string) ($categoryForm['slug'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.categories.placeholder_slug') }}">
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.categories.slug_help') }}</p>
                            @endif
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.categories.field_description') }}</label>
                        <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.categories.placeholder_description') }}">{{ old('description', (string) ($categoryForm['description'] ?? '')) }}</textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.categories.field_sort_order') }}</label>
                        <input type="number" name="sort_order" min="0" value="{{ old('sort_order', (int) ($categoryForm['sort_order'] ?? 0)) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.categories.placeholder_sort_order') }}">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.categories.sort_help') }}</p>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3">
                        <a href="{{ route('admin.categories.index') }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" class="inline-flex min-h-10 items-center gap-2 rounded-md border border-blue-600 bg-blue-600 px-4 text-sm font-medium text-white transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-blue-700 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                            <i data-lucide="save" class="h-4 w-4"></i>
                            {{ $isEdit ? __('admin.categories.save_edit') : __('admin.categories.save_add') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @if ($isEdit && auth('admin')->user()?->isSuperAdmin())
            <details class="mt-6 rounded-lg bg-white p-6 shadow-sm" @if($errors->has('value') || session('url_change_draft_restored')) open @endif>
                <summary class="min-h-10 cursor-pointer text-sm font-semibold text-blue-700">{{ __('url_change.ui.edit_slug') }}</summary>
                <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('url_change.ui.save_first') }}</p>
                <form method="POST" action="{{ route('admin.url-changes.store') }}" class="mt-4 space-y-3" data-url-separate-check>
                    @csrf<input type="hidden" name="operation" value="category"><input type="hidden" name="target_id" value="{{ (int) $categoryId }}">
                    <label for="category-new-url-slug" class="block text-sm font-medium text-gray-700">{{ __('url_change.ui.new_slug') }}</label>
                    <input id="category-new-url-slug" name="value" required maxlength="100" value="{{ old('value', $categoryForm['slug'] ?? '') }}" class="block min-h-11 w-full rounded-md border-gray-300 font-mono text-sm" aria-describedby="category-new-url-help @error('value') category-new-url-error @enderror" @error('value') aria-invalid="true" @enderror>
                    <p id="category-new-url-help" class="text-xs leading-5 text-gray-600">{{ __('url_change.errors.category_slug_invalid') }}</p>
                    @error('value')<p id="category-new-url-error" class="text-sm leading-6 text-red-700" role="alert">{{ $message }}</p>@enderror
                    <p class="text-sm leading-6 text-amber-900" data-url-unsaved-hint hidden>{{ __('url_change.ui.save_first') }}</p>
                    <button class="min-h-11 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white active:scale-[.96] disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600" data-url-separate-submit disabled>{{ __('url_change.ui.check') }}</button>
                    <p class="text-xs leading-5 text-amber-900" data-url-separate-nojs>{{ __('url_change.ui.no_js') }}</p>
                    <p class="text-xs leading-5 text-gray-600">{{ __('url_change.ui.check_hint') }}</p>
                </form>
            </details>
        @endif
    </div>
@endsection
