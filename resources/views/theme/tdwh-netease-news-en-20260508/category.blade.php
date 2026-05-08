@php
    app()->setLocale('en');
@endphp

@extends('theme.tdwh-netease-news-en-20260508.layout')

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
            'url' => $canonicalUrl ?? route('site.category', $category->slug),
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
    <div class="ne-shell ne-layout">
        <section class="ne-feed">
            <div class="ne-page-head ne-category-head">
                <div class="ne-page-kicker">{{ $siteTitle }} · {{ __('front.nav.categories') }}</div>
                <h1 class="ne-page-title">{{ $category->name }}</h1>
                @if(trim((string) $category->description) !== '')
                    <p class="ne-page-desc">{{ $category->description }}</p>
                @else
                    <p class="ne-page-desc">{{ $pageDescription }}</p>
                @endif
                <div class="ne-category-tabs" aria-label="{{ __('front.nav.categories') }}">
                    @foreach((isset($navCategories) ? collect($navCategories) : collect([$category])) as $categoryItem)
                        <a href="{{ route('site.category', $categoryItem->slug) }}" class="{{ $categoryItem->slug === $category->slug ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
                    @endforeach
                </div>
            </div>

            <section class="ne-feed-card">
                <div class="ne-section-title">
                    <span class="ne-title-row">{{ $category->name }}</span>
                </div>
                <div class="ne-feed">
                    @forelse($articles as $article)
                        @include('theme.tdwh-netease-news-en-20260508.partials.article-card', ['article' => $article])
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

        @include('theme.tdwh-netease-news-en-20260508.partials.sidebar')
    </div>
@endsection
