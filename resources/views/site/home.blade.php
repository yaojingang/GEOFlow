@extends('site.layout')

@section('content')
    <div class="site-container px-4 sm:px-6 lg:px-8 py-8">
        @if($search === '' && ! $category && ! $categoryMissing && (int) request('page', 1) === 1)
            <section class="home-hero article-shell mb-10">
                <div class="px-6 py-7 sm:px-8">
                    <h1 class="home-hero-title text-gray-900 mb-3">{{ $siteTitle }}</h1>
                    <p class="home-hero-copy text-gray-600">
                        {{ $siteSubtitle !== '' ? $siteSubtitle : ($siteDescription !== '' ? $siteDescription : __('site.home_hero_fallback')) }}
                    </p>
                    <form method="get" action="{{ route('site.home') }}" class="mt-6 max-w-xl flex flex-wrap gap-2">
                        <input type="search" name="search" value="{{ $search }}" placeholder="{{ __('site.search_placeholder') }}" class="flex-1 min-w-[12rem] border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white">
                        <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm rounded-lg">{{ __('site.search_button') }}</button>
                    </form>
                </div>
            </section>
        @endif

        @if($search === '' && ! $category && ! $categoryMissing && (int) request('page', 1) === 1 && $featuredArticles->isNotEmpty())
            <div class="flex items-center mb-6">
                <div class="section-label mr-4">
                    <i data-lucide="star" class="w-4 h-4 text-amber-400"></i>
                    <span>{{ __('site.home_featured') }}</span>
                </div>
            </div>
            <section class="mb-8">
                <div class="space-y-6">
                    @foreach($featuredArticles as $article)
                        @include('site.partials.article-card', ['article' => $article, 'showFeaturedBadge' => true])
                    @endforeach
                </div>
            </section>
            <div class="flex items-center mt-10 mb-4">
                <div class="section-label mr-4">
                    <i data-lucide="list" class="w-4 h-4 text-gray-400"></i>
                    <span>{{ __('site.home_latest') }}</span>
                </div>
            </div>
        @endif

        @if($search !== '')
            <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-8">
                <a href="{{ route('site.home') }}" class="hover:text-gray-700">{{ __('front.nav.home') }}</a>
                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                <span class="text-gray-900">{{ __('site.search_breadcrumb', ['term' => $search]) }}</span>
            </nav>
        @elseif($category)
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ $category->name }}</h1>
                @if(trim((string) $category->description) !== '')
                    <p class="text-gray-500 max-w-3xl">{{ $category->description }}</p>
                @endif
            </div>
        @elseif($categoryMissing)
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ __('site.category_not_found') }}</h1>
            </div>
        @endif

        <section class="py-4">
            @if($articles->isEmpty())
                <div class="article-shell p-12 text-center">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="file-text" class="w-8 h-8 text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">{{ $search !== '' ? __('site.search_empty_title') : __('site.home_empty_title') }}</h3>
                    <p class="text-gray-600 mb-6">{{ $search !== '' ? __('site.search_empty_desc') : __('site.home_empty_desc') }}</p>
                    <a href="{{ route('site.home') }}" class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-lg">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
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

        @if($search !== '' || $category || $categoryMissing)
            <div class="mt-8">
                <form method="get" action="{{ route('site.home') }}" class="flex flex-wrap gap-2 max-w-xl">
                    <input type="search" name="search" value="{{ $search }}" placeholder="{{ __('site.search_placeholder') }}" class="flex-1 min-w-[12rem] border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm rounded-lg">{{ __('site.search_button') }}</button>
                </form>
            </div>
        @endif
    </div>
@endsection
