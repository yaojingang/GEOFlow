@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.distribution.show', ['channelId' => (int) $channel->id]) }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.distribution.remote_article.edit_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.remote_article.edit_desc') }}</p>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="px-6 py-6">
                <form method="POST" action="{{ route('admin.distribution.article.update', ['distributionId' => (int) $distribution->id]) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.remote_article.title') }}</label>
                        <input id="title" name="title" type="text" required value="{{ old('title', (string) $article->title) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label for="excerpt" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.remote_article.excerpt') }}</label>
                        <textarea id="excerpt" name="excerpt" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('excerpt', (string) ($article->excerpt ?? '')) }}</textarea>
                    </div>

                    <div>
                        <label for="content" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.remote_article.content') }}</label>
                        <textarea id="content" name="content" rows="18" required class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('content', (string) $article->content) }}</textarea>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="keywords" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.remote_article.keywords') }}</label>
                            <input id="keywords" name="keywords" type="text" value="{{ old('keywords', (string) ($article->keywords ?? '')) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="meta_description" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.remote_article.meta_description') }}</label>
                            <input id="meta_description" name="meta_description" type="text" value="{{ old('meta_description', (string) ($article->meta_description ?? '')) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.distribution.show', ['channelId' => (int) $channel->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.cancel') }}</a>
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.button.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
