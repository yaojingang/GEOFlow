@php
    /** @var \App\Models\Article $article */
    $summaryRaw = (string) ($cardSummaries[$article->id] ?? '');
    $summary = trim(preg_replace([
        '/!\[[^\]]*]\([^)]+\)/u',
        '/\[[^\]]+]\([^)]+\)/u',
        '/[`*_>#|~-]+/u',
        '/\s+/u',
    ], [' ', ' ', ' ', ' '], strip_tags($summaryRaw)) ?? '');
    $pub = $article->published_at ?? $article->created_at;
    $categoryName = $article->category?->name ?? __('front.nav.all_articles');
    $initial = mb_substr($categoryName, 0, 1);
@endphp
<article class="tt-article-card">
    <div>
        <div class="tt-card-meta">
            @if(!empty($showFeaturedBadge))
                <span class="tt-pill">{{ __('site.home_featured_badge') }}</span>
            @endif
            @if($article->category)
                <a href="{{ route('site.category', $article->category->slug) }}" class="tt-pill">{{ $article->category->name }}</a>
            @endif
            <time datetime="{{ $pub?->toAtomString() }}">{{ $pub?->format('Y-m-d') }}</time>
        </div>
        <h2 class="tt-article-title">
            <a href="{{ route('site.article', $article->slug) }}">{{ $article->title }}</a>
        </h2>
        @if($summary !== '')
            <p class="tt-article-summary">{{ $summary }}</p>
        @endif
        <a href="{{ route('site.article', $article->slug) }}" class="tt-card-action">
            {{ __('site.home_read_more') }}
            <i data-lucide="arrow-right" class="w-4 h-4"></i>
        </a>
    </div>
    <a href="{{ route('site.article', $article->slug) }}" class="tt-thumb" aria-hidden="true">
        {{ $initial }}
    </a>
</article>
