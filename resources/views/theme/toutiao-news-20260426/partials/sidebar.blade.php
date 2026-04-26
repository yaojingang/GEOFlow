@php
    $sidebarHotArticles = collect($hotArticles ?? [])->take(6);
    $latestArticles = method_exists($articles ?? null, 'getCollection')
        ? $articles->getCollection()->take(6)
        : collect($articles ?? [])->take(6);
    $sidebarArticles = $sidebarHotArticles->isNotEmpty() ? $sidebarHotArticles : $latestArticles;
    $feedTitle = trim((string) (($siteSubtitle ?? '') !== '' ? $siteSubtitle : ($siteTitle ?? 'GEOFlow')));
    $feedDescription = trim((string) ($siteDescription ?? ''));
@endphp
<aside class="tt-sidebar">
    @if(!empty($showFeedPanel))
        <section class="tt-panel tt-feed-panel">
            <div class="tt-page-kicker">GEOFlow Feed</div>
            <h2 class="tt-feed-panel-title">{{ $feedTitle }}</h2>
            @if($feedDescription !== '')
                <p class="tt-feed-panel-desc">{{ $feedDescription }}</p>
            @endif
        </section>
    @endif

    <section class="tt-panel">
        <div class="tt-section-title">
            <span class="tt-title-row">{{ $sidebarHotArticles->isNotEmpty() ? __('site.home_hot') : __('site.home_latest') }}</span>
        </div>
        <div class="tt-hot-list">
            @forelse($sidebarArticles as $hotArticle)
                <a href="{{ route('site.article', $hotArticle->slug) }}" class="tt-hot-item">
                    <span class="tt-hot-index">{{ $loop->iteration }}</span>
                    <span>{{ $hotArticle->title }}</span>
                </a>
            @empty
                <p class="text-sm text-gray-500">{{ __('site.home_empty_title') }}</p>
            @endforelse
        </div>
    </section>
</aside>
