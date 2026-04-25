@extends('site.layout')

@section('content')
    <div class="site-container px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ $category->name }}</h1>
            @if(trim((string) $category->description) !== '')
                <p class="text-gray-500 max-w-3xl">{{ $category->description }}</p>
            @endif
        </div>

        <section class="py-4">
            @if($articles->isEmpty())
                <div class="article-shell p-12 text-center">
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">{{ __('site.home_empty_title') }}</h3>
                    <p class="text-gray-600 mb-6">{{ __('site.home_empty_desc') }}</p>
                    <a href="{{ route('site.home') }}" class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-lg">
                        {{ __('site.back_home') }}
                    </a>
                </div>
            @else
                <div class="space-y-8">
                    @foreach($articles as $article)
                        @include('site.partials.article-card', ['article' => $article, 'showFeaturedBadge' => false])
                    @endforeach
                </div>
                @if($articles->hasPages())
                    <div class="mt-12">
                        {{ $articles->onEachSide(1)->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
@endsection
