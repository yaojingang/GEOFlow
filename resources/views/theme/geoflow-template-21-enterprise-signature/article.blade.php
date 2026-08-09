@extends('theme.geoflow-template-21-enterprise-signature.layout')

@section('bodyClass', 'ent-body--article')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaAtId = chr(64).'id';
        $articleSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'Article',
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
    @if($article->category)
        <meta property="article:section" content="{{ $article->category->name }}">
    @endif
    <x-json-ld :data="$articleSchema" />
@endpush

@section('content')
    <article class="ent-article">
        <header class="ent-article-hero">
            <div class="ent-article-shell">
                <h1>{{ $article->title }}</h1>
                @if($excerptPlain !== '')
                    <p class="ent-article-hero__excerpt">{{ $excerptPlain }}</p>
                @endif
                <div class="ent-article-byline" aria-label="文章信息">
                    <span>{{ $article->author?->name ?? $siteTitle }}</span>
                    <time datetime="{{ optional($article->published_at ?? $article->created_at)->toDateString() }}">
                        {{ ($article->published_at ?? $article->created_at)?->format('Y.m.d') }}
                    </time>
                    <span>{{ (int) $article->view_count }} 次阅读</span>
                </div>
            </div>
        </header>

        <div class="ent-article-layout">
            <div id="ent-article-content" class="ent-prose" data-ent-article-content>
                {!! $contentHtml !!}

                @if(!empty($tags))
                    <div class="ent-tag-list">
                        @foreach($tags as $tag)
                            <span>{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif

                @if($stickyAd)
                    @php
                        $stickyAdTitle = is_array($stickyAd)
                            ? trim((string) ($stickyAd['title'] ?? ''))
                            : trim((string) ($stickyAd->title ?? ''));
                    @endphp
                    <section class="ent-article-cta">
                        @if(is_array($stickyAd) && trim((string) ($stickyAd['badge'] ?? '')) !== '')
                            <span class="ent-kicker">{{ $stickyAd['badge'] }}</span>
                        @endif
                        @if($stickyAdTitle !== '')
                            <h2>{{ $stickyAdTitle }}</h2>
                        @endif
                        @if(is_array($stickyAd))
                            <p>{{ $stickyAd['copy'] ?? '' }}</p>
                            @if(trim((string) ($stickyAd['button_text'] ?? '')) !== '')
                                <a href="{{ $stickyAd['button_url'] ?? '#' }}" class="ent-button ent-button--dark">
                                    {{ $stickyAd['button_text'] }}
                                </a>
                            @endif
                        @else
                            {!! $stickyAd->content_html !!}
                        @endif
                    </section>
                @endif
            </div>

            <aside class="ent-article-toc" data-ent-article-toc aria-labelledby="ent-article-toc-title" hidden>
                <h2 id="ent-article-toc-title">文章目录</h2>
                <nav aria-label="文章段落" data-ent-toc-list></nav>
            </aside>
        </div>
    </article>

    @if($relatedArticles->isNotEmpty())
        <section class="ent-related">
            <div class="ent-shell">
                <div class="ent-related__heading">
                    <h2>{{ __('site.article_related') }}</h2>
                </div>
                <div class="ent-related__grid">
                    @foreach($relatedArticles as $related)
                        <a href="{{ route('site.article', $related->slug) }}">
                            <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <strong>{{ $related->title }}</strong>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
