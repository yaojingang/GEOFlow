@extends('theme.apple_support_clone.layout')

@push('head')
@endpush

@section('theme_content')
    @php
        $pub = $article->published_at ?? $article->created_at;
    @endphp

    <div class="as-article-page">
        <article class="as-article-shell">
            <nav class="as-breadcrumb" aria-label="breadcrumb">
                <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                <span aria-hidden="true">/</span>
                @if($article->category)
                    <a href="{{ route('site.category', $article->category->slug) }}">{{ $article->category->name }}</a>
                    <span aria-hidden="true">/</span>
                @endif
                <span>{{ $article->title }}</span>
            </nav>

            <header class="as-article-header">
                @if($article->category)
                    <a href="{{ route('site.category', $article->category->slug) }}" class="as-section-chip">
                        {{ $article->category->name }}
                    </a>
                @endif
                <h1>{{ $article->title }}</h1>
                @if($excerptPlain !== '')
                    <p class="as-article-summary">{{ $excerptPlain }}</p>
                @endif
                <div class="as-article-date">
                    {{ __('site.article_published_on', ['date' => $pub?->format('Y-m-d') ?? '']) }}
                </div>
            </header>

            <div class="as-article-body">
                {!! $contentHtml !!}
            </div>

            @if(count($tags) > 0)
                <div class="as-tag-list" aria-label="article tags">
                    @foreach($tags as $tag)
                        <span>{{ $tag }}</span>
                    @endforeach
                </div>
            @endif
        </article>

        <section class="as-help-panel" aria-labelledby="helpPanelTitle">
            <div>
                <h2 id="helpPanelTitle">{{ __('site.article_related') }}</h2>
                <p>Find related articles and continue reading.</p>
            </div>
            @if($relatedArticles->isNotEmpty())
                <ol class="as-related-list">
                    @foreach($relatedArticles as $related)
                        <li>
                            <a href="{{ route('site.article', $related->slug) }}">{{ $related->title }}</a>
                        </li>
                    @endforeach
                </ol>
            @else
                <a href="{{ route('site.home') }}" class="as-link-arrow">{{ __('front.nav.all_articles') }}</a>
            @endif
        </section>

        <section class="as-feedback-panel" aria-labelledby="feedbackTitle">
            <h2 id="feedbackTitle">Helpful?</h2>
            <div class="as-feedback-actions" aria-label="feedback actions">
                <button type="button">Yes</button>
                <button type="button">No</button>
            </div>
        </section>

        @if($stickyAd)
            <aside id="articleStickyAd" class="as-sticky-ad" data-ad-id="{{ $stickyAd['id'] }}">
                <div class="as-sticky-ad-inner">
                    <button type="button" class="as-sticky-ad-close" id="articleStickyAdClose" aria-label="{{ __('site.article_ad_close') }}">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                    @if($stickyAd['badge'] !== '')
                        <div class="as-sticky-ad-badge">{{ $stickyAd['badge'] }}</div>
                    @endif
                    @if($stickyAd['title'] !== '')
                        <h3>{{ $stickyAd['title'] }}</h3>
                    @endif
                    <p>{{ $stickyAd['copy'] }}</p>
                    <a href="{{ $stickyAd['button_url'] }}">
                        {{ $stickyAd['button_text'] }}
                        <i data-lucide="arrow-up-right" class="w-4 h-4"></i>
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
