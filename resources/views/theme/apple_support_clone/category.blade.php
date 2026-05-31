@extends('theme.apple_support_clone.layout')

@section('theme_content')
    <div class="as-category-page">
        <section class="as-support-hero" aria-labelledby="categoryTitle">
            <div class="as-container as-narrow">
                <nav class="as-breadcrumb" aria-label="breadcrumb">
                    <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                    <span aria-hidden="true">/</span>
                    <span>{{ $category->name }}</span>
                </nav>
                <div class="as-support-icon" aria-hidden="true">
                    <i data-lucide="monitor" class="w-12 h-12"></i>
                </div>
                <h1 id="categoryTitle">{{ $category->name }}</h1>
                @if(trim((string) $category->description) !== '')
                    <p>{{ $category->description }}</p>
                @else
                    <p>{{ $siteDescription }}</p>
                @endif
            </div>
        </section>

        <section class="as-topic-strip" aria-label="support topics">
            <div class="as-container">
                <a href="{{ route('site.home') }}">
                    <i data-lucide="layout-grid" class="w-6 h-6"></i>
                    <span>{{ __('front.nav.all_articles') }}</span>
                </a>
                <a href="{{ route('site.archive') }}">
                    <i data-lucide="archive" class="w-6 h-6"></i>
                    <span>{{ __('site.archive_title') }}</span>
                </a>
                <a href="{{ route('site.home', ['search' => $category->name]) }}">
                    <i data-lucide="search" class="w-6 h-6"></i>
                    <span>{{ __('site.search_button') }}</span>
                </a>
            </div>
        </section>

        <section class="as-support-list-section" aria-labelledby="categoryArticlesTitle">
            <div class="as-container as-narrow">
                <div class="as-section-head as-section-head-center">
                    <p class="as-eyebrow">{{ $category->name }}</p>
                    <h2 id="categoryArticlesTitle">Get help by topic.</h2>
                </div>

                @if($articles->isEmpty())
                    <div class="as-empty-state">
                        <h2>{{ __('site.home_empty_title') }}</h2>
                        <p>{{ __('site.home_empty_desc') }}</p>
                        <a href="{{ route('site.home') }}" class="as-button-dark">{{ __('site.back_home') }}</a>
                    </div>
                @else
                    <div class="as-support-link-list">
                        @foreach($articles as $article)
                            @include('theme.apple_support_clone.partials.article-card', [
                                'article' => $article,
                                'showFeaturedBadge' => false,
                                'variant' => 'support-row',
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
