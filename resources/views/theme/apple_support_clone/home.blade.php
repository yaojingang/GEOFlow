@extends('theme.apple_support_clone.layout')

@section('theme_content')
        @include("site.partials.homepage-modules", ["homepageModules" => $homepageModules ?? [], "homepageStyle" => $homepageStyle ?? [], "showHomepageModules" => $showHomepageModules ?? false, "articles" => $articles ?? collect(), "featuredArticles" => $featuredArticles ?? collect(), "hotArticles" => $hotArticles ?? collect()])

@php
        $isLanding = $search === '' && ! $category && ! $categoryMissing && (int) request('page', 1) === 1;
    @endphp

    <div class="as-home">
        @if($isLanding)
            <section class="as-hero as-hero-home" aria-labelledby="homeHeroTitle">
                <div class="as-container as-hero-inner">
                    <p class="as-eyebrow">{{ __('front.nav.home') }}</p>
                    <h1 id="homeHeroTitle" class="as-display-title">{{ $siteTitle }}</h1>
                    <p class="as-hero-copy">
                        {{ $siteSubtitle !== '' ? $siteSubtitle : ($siteDescription !== '' ? $siteDescription : __('site.home_hero_fallback')) }}
                    </p>
                    <form method="get" action="{{ route('site.home') }}" class="as-search-form as-hero-search">
                        <label class="sr-only" for="home-search">{{ __('site.search_placeholder') }}</label>
                        <span class="as-search-icon" aria-hidden="true">
                            <i data-lucide="search" class="w-4 h-4"></i>
                        </span>
                        <input id="home-search" type="search" name="search" value="{{ $search }}" placeholder="{{ __('site.search_placeholder') }}">
                        <button type="submit">{{ __('site.search_button') }}</button>
                    </form>
                    <div class="as-action-row" aria-label="primary actions">
                        <a href="#latest-articles">{{ __('site.home_latest') }}</a>
                        <a href="{{ route('site.archive') }}">{{ __('site.archive_title') }}</a>
                    </div>
                </div>
            </section>

            @if($featuredArticles->isNotEmpty())
                <section class="as-section as-featured-section" aria-labelledby="featuredTitle">
                    <div class="as-container">
                        <div class="as-section-head as-section-head-center">
                            <p class="as-eyebrow">{{ __('site.home_featured') }}</p>
                            <h2 id="featuredTitle">Featured support reads.</h2>
                        </div>
                        <div class="as-featured-grid">
                            @foreach($featuredArticles->take(2) as $article)
                                @include('theme.apple_support_clone.partials.article-card', [
                                    'article' => $article,
                                    'showFeaturedBadge' => true,
                                    'variant' => $loop->first ? 'feature-large' : 'feature-dark',
                                ])
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif
        @endif

        @if($search !== '')
            <section class="as-compact-hero" aria-labelledby="searchTitle">
                <div class="as-container as-narrow">
                    <nav class="as-breadcrumb" aria-label="breadcrumb">
                        <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                        <span aria-hidden="true">/</span>
                        <span>{{ __('site.search_breadcrumb', ['term' => $search]) }}</span>
                    </nav>
                    <h1 id="searchTitle">{{ __('site.search_breadcrumb', ['term' => $search]) }}</h1>
                    <form method="get" action="{{ route('site.home') }}" class="as-search-form">
                        <label class="sr-only" for="search-results-input">{{ __('site.search_placeholder') }}</label>
                        <span class="as-search-icon" aria-hidden="true">
                            <i data-lucide="search" class="w-4 h-4"></i>
                        </span>
                        <input id="search-results-input" type="search" name="search" value="{{ $search }}" placeholder="{{ __('site.search_placeholder') }}">
                        <button type="submit">{{ __('site.search_button') }}</button>
                    </form>
                </div>
            </section>
        @elseif($category)
            <section class="as-compact-hero" aria-labelledby="filteredCategoryTitle">
                <div class="as-container as-narrow">
                    <nav class="as-breadcrumb" aria-label="breadcrumb">
                        <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                        <span aria-hidden="true">/</span>
                        <span>{{ $category->name }}</span>
                    </nav>
                    <h1 id="filteredCategoryTitle">{{ $category->name }}</h1>
                    @if(trim((string) $category->description) !== '')
                        <p>{{ $category->description }}</p>
                    @endif
                </div>
            </section>
        @elseif($categoryMissing)
            <section class="as-compact-hero" aria-labelledby="missingCategoryTitle">
                <div class="as-container as-narrow">
                    <h1 id="missingCategoryTitle">{{ __('site.category_not_found') }}</h1>
                </div>
            </section>
        @endif

        <section id="latest-articles" class="as-section as-latest-section" aria-labelledby="latestTitle">
            <div class="as-container">
                @if($isLanding)
                    <div class="as-section-head">
                        <p class="as-eyebrow">{{ __('site.home_latest') }}</p>
                        <h2 id="latestTitle">Latest articles.</h2>
                    </div>
                @endif

                @if($articles->isEmpty())
                    <div class="as-empty-state">
                        <div class="as-empty-icon" aria-hidden="true">
                            <i data-lucide="file-text" class="w-8 h-8"></i>
                        </div>
                        <h2>{{ $search !== '' ? __('site.search_empty_title') : __('site.home_empty_title') }}</h2>
                        <p>{{ $search !== '' ? __('site.search_empty_desc') : __('site.home_empty_desc') }}</p>
                        <a href="{{ route('site.home') }}" class="as-button-dark">{{ __('site.back_home') }}</a>
                    </div>
                @else
                    <div class="as-card-grid">
                        @foreach($articles as $article)
                            @include('theme.apple_support_clone.partials.article-card', [
                                'article' => $article,
                                'showFeaturedBadge' => false,
                                'variant' => 'tile',
                            ])
                        @endforeach
                    </div>
                    @if($articles->hasPages())
                        <div class="as-pagination">
                            {{ $articles->onEachSide(1)->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </section>
    </div>
@endsection
