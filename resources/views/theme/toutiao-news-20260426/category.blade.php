@extends('theme.toutiao-news-20260426.layout')

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
    <div class="tt-shell tt-layout">
        <section class="tt-feed">
            <div class="tt-page-head">
                <div class="tt-page-kicker">{{ __('front.nav.categories') }}</div>
                <h1 class="tt-page-title">{{ $category->name }}</h1>
                @if(trim((string) $category->description) !== '')
                    <p class="tt-page-desc">{{ $category->description }}</p>
                @else
                    <p class="tt-page-desc">{{ $pageDescription }}</p>
                @endif
            </div>

            <section class="tt-feed-card">
                <div class="tt-section-title">
                    <span class="tt-title-row">{{ $category->name }}</span>
                </div>
                <div class="tt-feed">
                    @forelse($articles as $article)
                        @include('theme.toutiao-news-20260426.partials.article-card', ['article' => $article])
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

        @include('theme.toutiao-news-20260426.partials.sidebar')
    </div>
@endsection
