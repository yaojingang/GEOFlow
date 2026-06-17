@php
    $sidebarHotArticles = collect($hotArticles ?? [])->take(6);
    $latestArticles = method_exists($articles ?? null, 'getCollection')
        ? $articles->getCollection()->take(6)
        : collect($articles ?? [])->take(6);
    $sidebarArticles = $sidebarHotArticles->isNotEmpty() ? $sidebarHotArticles : $latestArticles;
    $feedTitle = trim((string) (($siteSubtitle ?? '') !== '' ? $siteSubtitle : ($siteTitle ?? 'GEOFlow')));
    $feedDescription = trim((string) ($siteDescription ?? ''));
@endphp
<aside class="ne-sidebar">
    @if(!empty($showFeedPanel))
        <section class="ne-panel ne-feed-panel">
            <div class="ne-page-kicker">{{ $siteTitle }}</div>
            <h2 class="ne-feed-panel-title">{{ $feedTitle }}</h2>
            @if($feedDescription !== '')
                <p class="ne-feed-panel-desc">{{ $feedDescription }}</p>
            @endif
        </section>
    @endif

    <section class="ne-panel">
        <div class="ne-section-title">
            <span class="ne-title-row">{{ $sidebarHotArticles->isNotEmpty() ? __('site.home_hot') : __('site.home_latest') }}</span>
        </div>
        <div class="ne-hot-list">
            @forelse($sidebarArticles as $hotArticle)
                <a href="{{ route('site.article', $hotArticle->slug) }}" class="ne-hot-item">
                    <span class="ne-hot-index">{{ $loop->iteration }}</span>
                    <span>{{ $hotArticle->title }}</span>
                </a>
            @empty
                <p class="text-sm text-gray-500">{{ __('site.home_empty_title') }}</p>
            @endforelse
        </div>
    </section>
</aside>
