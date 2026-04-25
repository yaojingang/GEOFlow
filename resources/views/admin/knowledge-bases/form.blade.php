@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.knowledge-bases.update', ['knowledgeBaseId' => (int) $knowledgeBaseId])
        : route('admin.knowledge-bases.store');
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.knowledge-bases.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.knowledge_detail.heading') : __('admin.knowledge_bases.modal_create') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.knowledge_bases.subtitle') }}</p>
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
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_name') }}</label>
                        <input type="text" name="name" required value="{{ old('name', (string) ($knowledgeForm['name'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500" placeholder="{{ __('admin.knowledge_bases.field_name') }}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_description') }}</label>
                        <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500" placeholder="{{ __('admin.knowledge_bases.placeholder_description') }}">{{ old('description', (string) ($knowledgeForm['description'] ?? '')) }}</textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_doc_type') }}</label>
                        <select name="file_type" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
                            <option value="markdown" @selected(old('file_type', (string) ($knowledgeForm['file_type'] ?? 'markdown')) === 'markdown')>{{ __('admin.status.markdown') }}</option>
                            <option value="word" @selected(old('file_type', (string) ($knowledgeForm['file_type'] ?? 'markdown')) === 'word')>{{ __('admin.status.word_document') }}</option>
                            <option value="text" @selected(old('file_type', (string) ($knowledgeForm['file_type'] ?? 'markdown')) === 'text')>{{ __('admin.status.text') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_content') }}</label>
                        <textarea name="content" rows="18" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500" placeholder="{{ __('admin.knowledge_bases.placeholder_content') }}">{{ old('content', (string) ($knowledgeForm['content'] ?? '')) }}</textarea>
                    </div>

                    @if ($isEdit)
                        <div class="text-xs text-gray-500">
                            {{ __('admin.knowledge_detail.chunk_count') }}: {{ (int) ($chunkCount ?? 0) }}
                        </div>
                    @endif

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.knowledge-bases.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                            {{ $isEdit ? __('admin.knowledge_detail.save_changes') : __('admin.button.create') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

