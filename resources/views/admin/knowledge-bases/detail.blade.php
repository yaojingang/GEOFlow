@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.knowledge-bases.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.knowledge_detail.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ $knowledgeBase->name }}</p>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_detail.content_title') }}</h3>
            </div>
            <form method="POST" action="{{ route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}" class="p-6 space-y-4">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_detail.field_name') }}</label>
                        <input type="text" name="name" value="{{ old('name', (string) $knowledgeBase->name) }}" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_bases.field_doc_type') }}</label>
                        <select name="file_type" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm" required>
                            <option value="markdown" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'markdown')>{{ __('admin.status.markdown') }}</option>
                            <option value="word" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'word')>{{ __('admin.status.word_document') }}</option>
                            <option value="text" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'text')>{{ __('admin.status.text') }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_detail.field_description') }}</label>
                    <textarea name="description" rows="3" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm">{{ old('description', (string) ($knowledgeBase->description ?? '')) }}</textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_detail.field_content') }}</label>
                    <textarea name="content" rows="18" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm" required>{{ old('content', (string) ($knowledgeBase->content ?? '')) }}</textarea>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md text-sm text-white bg-orange-600 hover:bg-orange-700">
                        <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.knowledge_detail.save_changes') }}
                    </button>
                </div>
            </form>
        </div>

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
                                    <div class="max-w-xl break-words">{{ $chunkRow['content_preview'] }}</div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
