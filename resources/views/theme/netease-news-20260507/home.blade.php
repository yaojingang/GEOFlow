@php
    app()->setLocale('en');

    if (($search ?? '') !== '') {
        $viewTitle = 'Search: '.$search;
        $pageTitle = $viewTitle.' - '.$siteTitle;
        $pageDescription = $search.' - '.$siteDescription;
    } elseif (($categoryMissing ?? false) === true) {
        $viewTitle = 'Category not found';
        $pageTitle = $viewTitle.' - '.$siteTitle;
    } elseif (($category ?? null) !== null) {
        $viewTitle = $category->name;
    } else {
        $viewTitle = 'Latest Articles';
    }
@endphp

@extends('theme.netease-news-20260507.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaItems = [];
        foreach ((method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles))->take(10) as $schemaArticle) {
            $schemaItems[] = [
                $schemaAtType => 'ListItem',
                'position' => count($schemaItems) + 1,
                'url' => route('site.article', $schemaArticle->slug),
                'name' => $schemaArticle->title,
            ];
        }
        $collectionSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'CollectionPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.home'),
            'mainEntity' => [
                $schemaAtType => 'ItemList',
                'itemListElement' => $schemaItems,
            ],
        ];
    @endphp
    <script type="application/ld+json">
        {!! json_encode($collectionSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
@endpush

@section('content')
    @php
        $homeArticles = method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles);
        $homepageHotArticles = collect($hotArticles ?? []);
        $isDefaultHome = $search === '' && !$category && !$categoryMissing;
        $leadArticle = $isDefaultHome ? ($featuredArticles->first() ?: $homeArticles->first()) : null;
        $leadSummary = $leadArticle ? trim((string) ($cardSummaries[$leadArticle->id] ?? '')) : '';
        $headlineArticles = $isDefaultHome
            ? collect($featuredArticles)->concat($homeArticles)->filter()->unique(fn ($item) => $item->id)->reject(fn ($item) => $leadArticle && $item->id === $leadArticle->id)->take(4)
            : collect();
    @endphp
    <div class="ne-shell ne-layout">
        <section class="ne-feed">
            @if($search !== '')
                <div class="ne-page-head">
                    <div class="ne-page-kicker">{{ __('site.search_button') }}</div>
                    <h1 class="ne-page-title">{{ __('site.search_breadcrumb', ['term' => $search]) }}</h1>
                    <p class="ne-page-desc">{{ $pageDescription }}</p>
                </div>
            @elseif($categoryMissing)
                <div class="ne-page-head">
                    <div class="ne-page-kicker">{{ __('site.category_not_found') }}</div>
                    <h1 class="ne-page-title">{{ __('site.category_not_found') }}</h1>
                    <p class="ne-page-desc">{{ $pageDescription }}</p>
                </div>
            @else
                @if($leadArticle)
                    <section class="ne-home-lead">
                        <div class="ne-home-lead-main">
                            <div class="ne-page-kicker">{{ $siteSubtitle !== '' ? $siteSubtitle : $siteTitle }}</div>
                            <h1>
                                <a href="{{ route('site.article', $leadArticle->slug) }}">{{ $leadArticle->title }}</a>
                            </h1>
                            @if($leadSummary !== '')
                                <p>{{ $leadSummary }}</p>
                            @elseif($siteDescription !== '')
                                <p>{{ $siteDescription }}</p>
                            @endif
                            <a href="{{ route('site.article', $leadArticle->slug) }}" class="ne-card-action">{{ __('site.home_read_more') }}</a>
                        </div>
                        <div class="ne-home-headlines">
                            <div class="ne-mini-title">{{ __('site.home_featured') }}</div>
                            @forelse($headlineArticles as $headlineArticle)
                                <a href="{{ route('site.article', $headlineArticle->slug) }}">{{ $headlineArticle->title }}</a>
                            @empty
                                <span>{{ $siteDescription !== '' ? $siteDescription : __('site.home_hero_fallback') }}</span>
                            @endforelse
                        </div>
                    </section>
                @endif
                @if($homepageHotArticles->isNotEmpty())
                    <div class="ne-hot-carousel" data-hot-carousel>
                        @foreach($homepageHotArticles as $hotArticle)
                            <a href="{{ route('site.article', $hotArticle->slug) }}" class="ne-breaking {{ $loop->first ? 'is-active' : '' }}" data-hot-slide>
                                <strong>{{ __('site.home_hot_badge') }}</strong>
                                <span>{{ $hotArticle->title }}</span>
                            </a>
                        @endforeach
                        @if($homepageHotArticles->count() > 1)
                            <div class="ne-hot-dots" aria-hidden="true">
                                @foreach($homepageHotArticles as $hotArticle)
                                    <button type="button" class="{{ $loop->first ? 'is-active' : '' }}" data-hot-dot></button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            @endif

            @if($featuredArticles->isNotEmpty() && $search === '' && !$category)
                <section class="ne-feed-card">
                    <div class="ne-section-title">
                        <span class="ne-title-row">{{ __('site.home_featured') }}</span>
                    </div>
                    <div class="ne-feed">
                        @foreach($featuredArticles->take(5) as $article)
                            @include('theme.netease-news-20260507.partials.article-card', ['article' => $article, 'showFeaturedBadge' => true])
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="ne-feed-card">
                <div class="ne-section-title">
                    <span class="ne-title-row">{{ $viewTitle }}</span>
                </div>
                <div class="ne-feed">
                    @forelse($articles as $article)
                        @include('theme.netease-news-20260507.partials.article-card', ['article' => $article])
                    @empty
                        <div class="rounded-2xl border border-dashed border-gray-200 bg-white p-10 text-center text-gray-500">
                            {{ __('site.home_empty_title') }}
                        </div>
                    @endforelse
                </div>
            </section>

            <div class="mt-3">
                {{ $articles->links() }}
            </div>
        </section>

        @include('theme.netease-news-20260507.partials.sidebar', ['showFeedPanel' => $isDefaultHome])
    </div>
@endsection
