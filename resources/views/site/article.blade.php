@extends('site.layout')

@push('head')
    <meta property="og:title" content="{{ $article->title }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:type" content="article">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:site_name" content="{{ $siteTitle }}">
@endpush

@section('content')
    @php
        $pub = $article->published_at ?? $article->created_at;
    @endphp
    <div class="site-container article-page px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
        <nav class="article-rail article-breadcrumb flex items-center flex-wrap gap-2 text-sm text-gray-500 mb-8" aria-label="breadcrumb">
            <a href="{{ route('site.home') }}" class="hover:text-gray-700">{{ __('front.nav.home') }}</a>
            <span class="article-breadcrumb-separator" aria-hidden="true">/</span>
            @if($article->category)
                <a href="{{ route('site.category', $article->category->slug) }}" class="hover:text-gray-700">{{ $article->category->name }}</a>
                <span class="article-breadcrumb-separator" aria-hidden="true">/</span>
            @endif
            <span class="text-gray-900 article-breadcrumb-current">{{ $article->title }}</span>
        </nav>

        <article class="article-shell article-detail-shell mb-8">
            <div class="article-detail-pad">
                <header class="article-rail mb-10">
                    @if($article->category)
                        <div class="mb-4">
                            <a href="{{ route('site.category', $article->category->slug) }}" class="article-section-chip">
                                <i data-lucide="folder" class="w-3.5 h-3.5"></i>
                                {{ $article->category->name }}
                            </a>
                        </div>
                    @endif

                    <h1 class="article-hero-title font-semibold text-gray-900 mb-4 leading-tight">
                        {{ $article->title }}
                    </h1>

                    <div class="entry-meta article-meta-row flex flex-wrap items-center gap-3 mb-6">
                        <span class="article-meta-chip flex items-center">
                            <i data-lucide="calendar" class="w-4 h-4 mr-1"></i>
                            {{ __('site.article_published_on', ['date' => $pub?->format('Y-m-d') ?? '']) }}
                        </span>
                    </div>

                    @if($excerptPlain !== '')
                        <div class="article-summary-box p-5 mb-6 rounded-xl bg-gray-50">
                            <p class="article-kicker m-0 text-gray-700">{{ $excerptPlain }}</p>
                        </div>
                    @endif
                </header>

                <div class="article-prose article-rail max-w-none">
                    {!! $contentHtml !!}
                </div>

                @if(count($tags) > 0)
                    <div class="article-rail mt-8 pt-6 border-t border-gray-100">
                        <div class="flex flex-wrap gap-2">
                            @foreach($tags as $tag)
                                <span class="pill-tag">
                                    <i data-lucide="tag" class="w-3 h-3 mr-1"></i>
                                    {{ $tag }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </article>

        @if($relatedArticles->isNotEmpty())
            <div class="article-shell article-detail-shell p-6">
                <div class="article-rail max-w-none">
                    <div class="related-articles-header flex items-center mb-5">
                        <span class="related-articles-header__icon mr-2" aria-hidden="true">
                            <i data-lucide="bookmark" class="w-4 h-4 text-gray-500 flex-shrink-0"></i>
                        </span>
                        <h3 class="text-base font-medium text-gray-700 leading-none">{{ __('site.article_related') }}</h3>
                    </div>
                    <ul class="related-articles-list space-y-4">
                        @foreach($relatedArticles as $index => $related)
                            <li class="related-article-item flex items-start group">
                                <span class="related-article-rank inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-gray-600 text-xs font-medium mr-4 mt-0.5 flex-shrink-0">
                                    {{ $index + 1 }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    <a href="{{ route('site.article', $related->slug) }}" class="related-article-link block text-gray-900 hover:text-blue-600 transition-colors duration-200 font-medium leading-relaxed text-base mb-1">
                                        {{ $related->title }}
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @if($stickyAd)
            <aside id="articleStickyAd" class="article-sticky-ad" data-ad-id="{{ $stickyAd['id'] }}">
                <div class="article-sticky-ad__inner">
                    <button type="button" class="article-sticky-ad__close" id="articleStickyAdClose" aria-label="{{ __('site.article_ad_close') }}">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                    <div class="article-sticky-ad__content">
                        @if($stickyAd['badge'] !== '')
                            <div class="article-sticky-ad__badge">{{ $stickyAd['badge'] }}</div>
                        @endif
                        @if($stickyAd['title'] !== '')
                            <h3 class="article-sticky-ad__title">{{ $stickyAd['title'] }}</h3>
                        @endif
                        <p class="article-sticky-ad__copy">{{ $stickyAd['copy'] }}</p>
                    </div>
                    <a href="{{ $stickyAd['button_url'] }}" class="article-sticky-ad__button">
                        {{ $stickyAd['button_text'] }}
                        <i data-lucide="arrow-up-right" class="w-4 h-4 ml-2"></i>
                    </a>
                </div>
            </aside>
        @endif
    </div>
@endsection

@if($stickyAd)
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const stickyAd = document.getElementById('articleStickyAd');
                const closeButton = document.getElementById('articleStickyAdClose');
                if (!stickyAd || !closeButton) {
                    return;
                }
                const storageKey = 'articleStickyAdDismissed:' + (stickyAd.dataset.adId || 'default');
                if (window.localStorage && localStorage.getItem(storageKey) === '1') {
                    stickyAd.remove();
                    return;
                }
                closeButton.addEventListener('click', function () {
                    if (window.localStorage) {
                        localStorage.setItem(storageKey, '1');
                    }
                    stickyAd.remove();
                });
            });
        </script>
    @endpush
@endif
