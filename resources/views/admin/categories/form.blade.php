@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.categories.update', ['categoryId' => (int) $categoryId])
        : route('admin.categories.store');
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.categories.edit_form') : __('admin.categories.add_form') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.categories.subtitle') }}</p>
                </div>
                <div class="flex space-x-3">
                    <a href="{{ route('admin.articles.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.categories.back_to_articles') }}
                    </a>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ $isEdit ? __('admin.categories.edit_form') : __('admin.categories.add_form') }}</h3>
            </div>
            <div class="px-6 py-6">
                <form method="POST" action="{{ $formAction }}" class="space-y-6">
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
                            <input type="text" name="slug" value="{{ old('slug', (string) ($categoryForm['slug'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.categories.placeholder_slug') }}">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.categories.slug_help') }}</p>
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

                    <div class="flex justify-end space-x-3">
                        <a href="{{ route('admin.categories.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                            {{ $isEdit ? __('admin.categories.save_edit') : __('admin.categories.save_add') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

