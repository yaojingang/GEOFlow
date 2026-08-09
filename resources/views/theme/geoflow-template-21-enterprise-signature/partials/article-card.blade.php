@php
    /** @var \App\Models\Article $article */
    $summaryRaw = (string) ($cardSummaries[$article->id] ?? '');
    $summary = trim(preg_replace([
        '/!\[[^\]]*]\([^)]+\)/u',
        '/\[[^\]]+]\([^)]+\)/u',
        '/[`*_>#|~-]+/u',
        '/\s+/u',
    ], [' ', ' ', ' ', ' '], strip_tags($summaryRaw)) ?? '');
    $summary = trim(preg_replace(
        '/^'.preg_quote($article->title, '/').'\s*(?:核心摘要[：:]?\s*)?/u',
        '',
        $summary,
    ) ?? $summary);
    $publishedAt = $article->published_at ?? $article->created_at;
    $categoryName = $article->category?->name ?? __('front.nav.all_articles');
    $categoryInitial = mb_strtoupper(mb_substr($categoryName, 0, 1));
    $balanceCardTitle = mb_strlen($article->title) <= 18;
    $cardVariant = $cardVariant ?? 'grid';
@endphp

@if($cardVariant === 'category-card')
    <article class="ent-article-card ent-article-card--category">
        <a
            href="{{ route('site.article', $article->slug) }}"
            class="ent-article-card__category-link"
            aria-label="阅读《{{ $article->title }}》"
        >
            <div class="ent-card-meta">
                @if(!empty($showFeaturedBadge))
                    <span class="ent-badge ent-badge--blue">{{ __('site.home_featured_badge') }}</span>
                @endif
                <time datetime="{{ $publishedAt?->toAtomString() }}">{{ $publishedAt?->format('Y.m.d') }}</time>
            </div>
            <h2 @class(['ent-balanced-card-title' => $balanceCardTitle])>
                {{ $article->title }}
            </h2>
            @if($summary !== '')
                <p>{{ $summary }}</p>
            @endif
            <span class="ent-card-action ent-card-action--icon" aria-hidden="true">
                <i data-lucide="arrow-up-right"></i>
            </span>
        </a>
    </article>
@else
    <article class="ent-article-card">
        <a href="{{ route('site.article', $article->slug) }}" class="ent-article-card__visual" aria-hidden="true" tabindex="-1">
            <span>{{ $categoryInitial }}</span>
            <small>GEOFlow Insight</small>
        </a>
        <div class="ent-article-card__body">
            <div class="ent-card-meta">
                @if(!empty($showFeaturedBadge))
                    <span class="ent-badge ent-badge--blue">{{ __('site.home_featured_badge') }}</span>
                @endif
                @if($article->category)
                    <a href="{{ route('site.category', $article->category->slug) }}">{{ $article->category->name }}</a>
                @endif
                <time datetime="{{ $publishedAt?->toAtomString() }}">{{ $publishedAt?->format('Y.m.d') }}</time>
            </div>
            <h2 @class(['ent-balanced-card-title' => $balanceCardTitle])>
                <a href="{{ route('site.article', $article->slug) }}">{{ $article->title }}</a>
            </h2>
            @if($summary !== '')
                <p>{{ $summary }}</p>
            @endif
            <a href="{{ route('site.article', $article->slug) }}" class="ent-card-action">
                {{ __('site.home_read_more') }}
                <i data-lucide="arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </article>
@endif
