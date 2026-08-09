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
        $archiveMonthSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'CollectionPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.archive.month', ['year' => $year, 'month' => $month]),
            'mainEntity' => [
                $schemaAtType => 'ItemList',
                'itemListElement' => $schemaItems,
            ],
        ];
    @endphp
    <x-json-ld :data="$archiveMonthSchema" />
@endpush

@section('content')
    <section class="ent-page-hero">
        <div class="ent-shell ent-page-hero__grid">
            <div>
                <nav class="ent-breadcrumb" aria-label="面包屑">
                    <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                    <i data-lucide="chevron-right" aria-hidden="true"></i>
                    <a href="{{ route('site.archive') }}">{{ __('site.archive_title') }}</a>
                    <i data-lucide="chevron-right" aria-hidden="true"></i>
                    <span>{{ $periodLabel }}</span>
                </nav>
                <span class="ent-kicker">Monthly archive</span>
                <h1>{{ $periodLabel }}</h1>
                <p>本月发布的 GEOFlow 实践、产品进展和行业洞察。</p>
            </div>
            <div class="ent-page-index">
                <span>Month index</span>
                <strong>{{ $month }}</strong>
                <small>{{ $articles->total() }} published resources</small>
            </div>
        </div>
    </section>

    <section class="ent-category-body">
        <div class="ent-shell">
            <div class="ent-list-heading">
                <div><span class="ent-kicker">Archive entries</span><h2>{{ $periodLabel }}</h2></div>
                <a href="{{ route('site.archive') }}" class="ent-text-link">返回归档 <i data-lucide="arrow-right" aria-hidden="true"></i></a>
            </div>

            <div class="ent-resource-grid">
                @forelse($articles as $article)
                    @include('theme.geoflow-template-21-enterprise-signature.partials.article-card', ['article' => $article])
                @empty
                    <div class="ent-empty-state ent-empty-state--wide">
                        <span>0 resources</span>
                        <h2>{{ __('site.home_empty_title') }}</h2>
                        <p>这个月份没有已发布内容，可以返回完整归档继续浏览。</p>
                    </div>
                @endforelse
            </div>

            <div class="ent-pagination">{{ $articles->links() }}</div>
        </div>
    </section>
@endsection
