@php
    /** @var \App\Models\Article $article */
    $summary = $cardSummaries[$article->id] ?? '';
    $pub = $article->published_at ?? $article->created_at;
    $variant = $variant ?? 'tile';
@endphp

@if($variant === 'support-row')
    <article class="as-support-row">
        <a href="{{ route('site.article', $article->slug) }}">
            <span>{{ $article->title }}</span>
            <small>{{ $pub?->format('Y-m-d') }}</small>
        </a>
        @if($summary !== '')
            <p>{{ $summary }}</p>
        @endif
    </article>
@else
    <article class="as-article-card as-article-card-{{ $variant }}">
        <div class="as-card-body">
            <div class="as-card-meta">
                @if(!empty($showFeaturedBadge))
                    <span>{{ __('site.home_featured_badge') }}</span>
                @endif
                @if($article->category)
                    <a href="{{ route('site.category', $article->category->slug) }}">{{ $article->category->name }}</a>
                @endif
            </div>
            <h3>
                <a href="{{ route('site.article', $article->slug) }}">{{ $article->title }}</a>
            </h3>
            @if($summary !== '')
                <p>{{ $summary }}</p>
            @endif
            <div class="as-card-footer">
                <time datetime="{{ $pub?->toAtomString() }}">{{ $pub?->format('Y-m-d') }}</time>
                <a href="{{ route('site.article', $article->slug) }}" class="as-link-arrow">
                    {{ __('site.home_read_more') }}
                </a>
            </div>
        </div>
    </article>
@endif
