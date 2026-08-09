@extends('theme.geoflow-template-21-enterprise-signature.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaItems = [];
        foreach ((is_object($articles ?? null) && method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles ?? []))->take(10) as $schemaArticle) {
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
    <x-json-ld :data="$collectionSchema" />
@endpush

@section('content')
    @php
        $categoryNavItems = isset($navCategories) ? collect($navCategories) : collect([$category]);
    @endphp

    <section class="ent-page-hero ent-category-hero">
        <div class="ent-shell ent-page-hero__grid ent-category-hero__grid">
            <div class="ent-category-hero__intro">
                <h1>{{ $category->name }}</h1>
                <p>{{ trim((string) $category->description) !== '' ? $category->description : $pageDescription }}</p>
            </div>
            <div class="ent-category-hero__count" aria-label="共 {{ $articles->total() }} 篇文章">
                <strong>{{ $articles->total() }}</strong>
                <span>篇文章</span>
            </div>
        </div>
    </section>

    <section class="ent-category-body">
        <div class="ent-shell">
            @if($categoryNavItems->count() > 1)
                <nav class="ent-category-nav" aria-label="{{ __('front.nav.categories') }}">
                    <span>浏览分类</span>
                    @foreach($categoryNavItems as $categoryItem)
                        <a href="{{ route('site.category', $categoryItem->slug) }}" class="{{ $categoryItem->slug === $category->slug ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
                    @endforeach
                </nav>
            @endif

            @if(collect($hotArticles ?? [])->isNotEmpty())
                <div class="ent-category-featured">
                    <div><span>精选</span><strong>重点洞察</strong></div>
                    @foreach(collect($hotArticles)->take(3) as $hotArticle)
                        <a href="{{ route('site.article', $hotArticle->slug) }}">
                            <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <strong>{{ $hotArticle->title }}</strong>
                            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                        </a>
                    @endforeach
                </div>
            @endif

            <div class="ent-list-heading">
                <h2>全部文章</h2>
            </div>

            <div class="ent-resource-grid ent-resource-grid--category">
                @forelse($articles as $article)
                    @include('theme.geoflow-template-21-enterprise-signature.partials.article-card', [
                        'article' => $article,
                        'cardVariant' => 'category-card',
                    ])
                @empty
                    <div class="ent-empty-state ent-empty-state--wide">
                        <h2>{{ __('site.home_empty_title') }}</h2>
                        <p>发布内容后，文章会显示在这里。</p>
                    </div>
                @endforelse
            </div>

            @if($articles->hasPages())
                <div class="ent-pagination">
                    {{ $articles->links('theme.geoflow-template-21-enterprise-signature.partials.pagination') }}
                </div>
            @endif
        </div>
    </section>
@endsection
