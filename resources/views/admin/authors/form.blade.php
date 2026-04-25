@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.authors.update', ['authorId' => (int) $authorId])
        : route('admin.authors.store');
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.authors.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.authors.modal_edit') : __('admin.authors.modal_create') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.authors.page_subtitle') }}</p>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-6">
                <form method="POST" action="{{ $formAction }}" class="space-y-6">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.authors.field_name') }}</label>
                        <input type="text" name="name" required value="{{ old('name', (string) ($authorForm['name'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.authors.placeholder_name') }}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.authors.field_email') }}</label>
                        <input type="text" name="email" value="{{ old('email', (string) ($authorForm['email'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.authors.placeholder_email') }}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.authors.field_bio') }}</label>
                        <textarea name="bio" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.authors.placeholder_bio') }}">{{ old('bio', (string) ($authorForm['bio'] ?? '')) }}</textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.authors.field_website') }}</label>
                        <input type="text" name="website" value="{{ old('website', (string) ($authorForm['website'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://example.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.authors.field_social') }}</label>
                        <textarea name="social_links" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.authors.placeholder_social') }}">{{ old('social_links', (string) ($authorForm['social_links'] ?? '')) }}</textarea>
                    </div>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.authors.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                            {{ $isEdit ? __('admin.authors.save_edit') : __('admin.authors.save_create') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

