@extends('theme.geoflow-template-21-enterprise-signature.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $archiveSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'CollectionPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.archive'),
        ];
    @endphp
    <x-json-ld :data="$archiveSchema" />
@endpush

@section('content')
    @php
        $archiveGroups = collect($archives ?? [])->groupBy('year');
        $archiveTotal = collect($archives ?? [])->sum('count');
    @endphp

    <section class="ent-page-hero ent-page-hero--archive">
        <div class="ent-shell ent-page-hero__grid">
            <div>
                <nav class="ent-breadcrumb" aria-label="面包屑">
                    <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                    <i data-lucide="chevron-right" aria-hidden="true"></i>
                    <span>{{ __('site.archive_title') }}</span>
                </nav>
                <span class="ent-kicker">Ideas and evidence</span>
                <h1>{{ __('site.archive_title') }}</h1>
                <p>按时间浏览 GEOFlow 的产品实践、技术进展和 GEO 洞察。</p>
            </div>
            <div class="ent-page-index">
                <span>Archive ledger</span>
                <strong>{{ $archiveGroups->count() }}</strong>
                <small>{{ $archiveTotal }} published resources</small>
            </div>
        </div>
    </section>

    <section class="ent-archive-body">
        <div class="ent-shell">
            <div class="ent-archive-ledger">
                @forelse($archiveGroups as $year => $yearArchives)
                    <article>
                        <div class="ent-archive-year">
                            <span>Year</span>
                            <strong>{{ $year }}</strong>
                            <small>{{ collect($yearArchives)->sum('count') }} resources</small>
                        </div>
                        <div class="ent-archive-months">
                            @foreach($yearArchives as $archive)
                                <a href="{{ route('site.archive.month', ['year' => $archive['year'], 'month' => $archive['month']]) }}">
                                    <span>{{ $archive['month'] }}</span>
                                    <strong>{{ $archive['count'] }} 篇内容</strong>
                                    <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                                </a>
                            @endforeach
                        </div>
                    </article>
                @empty
                    <div class="ent-empty-state ent-empty-state--wide">
                        <span>Archive runway</span>
                        <h2>{{ __('site.home_empty_title') }}</h2>
                        <p>发布文章后，归档会按年份和月份自动组织。</p>
                    </div>
                @endforelse
            </div>
        </div>
    </section>
@endsection
