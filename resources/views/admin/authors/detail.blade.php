@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <header class="mb-6 flex items-start gap-3 sm:mb-8 sm:gap-4">
            <a href="{{ route('admin.authors.index') }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-gray-400 transition-[background-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-white [@media(hover:hover)]:hover:text-gray-700 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-gray-900">{{ $author->name }}</h1>
                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.authors.page_subtitle') }}</p>
            </div>
        </header>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.common.basic_info') }}</h2>
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
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.common.related_tasks') }}</h2>
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
