@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.authors.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $author->name }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.authors.page_title') }}</p>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.authors.page_title') }}</h3>
            </div>
            <div class="px-6 py-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <div class="text-gray-500">{{ __('admin.authors.field_name') }}</div>
                    <div class="mt-1 text-gray-900">{{ $author->name }}</div>
                </div>
                <div>
                    <div class="text-gray-500">{{ __('admin.authors.field_email') }}</div>
                    <div class="mt-1 text-gray-900">{{ $author->email ?: '-' }}</div>
                </div>
                <div class="md:col-span-2">
                    <div class="text-gray-500">{{ __('admin.authors.field_bio') }}</div>
                    <div class="mt-1 text-gray-900 whitespace-pre-wrap">{{ $author->bio ?: '-' }}</div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.common.related_tasks') }}</h3>
            </div>
            @if (empty($articles))
                <div class="px-6 py-5 text-sm text-gray-500">{{ __('admin.authors.empty_desc') }}</div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($articles as $article)
                        <div class="px-6 py-4 flex items-center justify-between">
                            <div class="text-sm text-gray-900 truncate pr-4">#{{ (int) $article->id }} {{ $article->title }}</div>
                            <div class="text-xs text-gray-500">{{ $article->status }} / {{ $article->review_status }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
