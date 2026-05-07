@extends('theme.netease-news-20260507.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaAtId = chr(64).'id';
        $articleSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'NewsArticle',
            'headline' => $article->title,
            'description' => $pageDescription,
            'datePublished' => optional($article->published_at ?? $article->created_at)->toAtomString(),
            'dateModified' => optional($article->updated_at ?? $article->published_at ?? $article->created_at)->toAtomString(),
            'mainEntityOfPage' => [
                $schemaAtType => 'WebPage',
                $schemaAtId => $canonicalUrl ?? route('site.article', $article->slug),
            ],
            'author' => [
                $schemaAtType => 'Person',
                'name' => $article->author?->name ?? $siteTitle,
            ],
            'publisher' => [
                $schemaAtType => 'Organization',
                'name' => $siteTitle,
            ],
            'articleSection' => $article->category?->name,
            'keywords' => $tags,
        ];
    @endphp
    <meta property="og:title" content="{{ $article->title }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:type" content="article">
    <meta property="og:url" content="{{ $canonicalUrl ?? route('site.article', $article->slug) }}">
    @if($article->category)
        <meta property="article:section" content="{{ $article->category->name }}">
    @endif
    <script type="application/ld+json">
        {!! json_encode($articleSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
@endpush

@section('content')
    <div class="ne-shell ne-article-layout">
        <main class="ne-post-column">
            <nav class="ne-breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                @if($article->category)
                    <span>/</span>
                    <a href="{{ route('site.category', $article->category->slug) }}">{{ $article->category->name }}</a>
                @endif
                <span>/</span>
                <span>{{ $article->title }}</span>
            </nav>

            <article class="ne-article-main">
                <h1 class="ne-article-h1">{{ $article->title }}</h1>

                <div class="ne-post-info">
                    @if($article->category)
                        <a href="{{ route('site.category', $article->category->slug) }}">{{ $article->category->name }}</a>
                    @endif
                    <time datetime="{{ ($article->published_at ?? $article->created_at)?->toAtomString() }}">
                        {{ ($article->published_at ?? $article->created_at)?->format('Y-m-d H:i') }}
                    </time>
                    @if($article->author)
                        <span>{{ $article->author->name }}</span>
                    @endif
                    <span>{{ (int) $article->view_count }} views</span>
                </div>

                @if($excerptPlain !== '')
                    <p class="ne-article-excerpt">{{ $excerptPlain }}</p>
                @endif

                <div class="ne-prose">
                    {!! $contentHtml !!}
                </div>

                @if(!empty($tags))
                    <div class="ne-tag-list">
                        @foreach($tags as $tag)
                            <span>{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif

                @if($stickyAd)
                    <section class="ne-ad-slot">
                        @if($stickyAd->title)
                            <h2>{{ $stickyAd->title }}</h2>
                        @endif
                        {!! $stickyAd->content_html !!}
                    </section>
                @endif
            </article>

            @if($relatedArticles->isNotEmpty())
                <section class="ne-related-block">
                    <div class="ne-section-title">
                        <span class="ne-title-row">{{ __('site.article_related') }}</span>
                    </div>
                    <div class="ne-related-grid">
                        @foreach($relatedArticles as $related)
                            <a href="{{ route('site.article', $related->slug) }}" class="ne-related-card">
                                <span class="ne-related-index">{{ $loop->iteration }}</span>
                                <span>{{ $related->title }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </main>

        <aside class="ne-post-aside">
            @if($relatedArticles->isNotEmpty())
                <section class="ne-panel">
                    <div class="ne-section-title">
                        <span class="ne-title-row">{{ __('site.article_related') }}</span>
                    </div>
                    <div class="ne-hot-list">
                        @foreach($relatedArticles as $related)
                            <a href="{{ route('site.article', $related->slug) }}" class="ne-hot-item">
                                <span class="ne-hot-index">{{ $loop->iteration }}</span>
                                <span>{{ $related->title }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="ne-panel">
                <div class="ne-section-title">
                    <span class="ne-title-row">{{ $siteTitle }}</span>
                </div>
                <p class="text-sm leading-7 text-gray-600">{{ $siteDescription }}</p>
                <a href="{{ route('site.home') }}" class="ne-card-action">{{ __('front.nav.home') }} <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
            </section>
        </aside>
    </div>
@endsection
