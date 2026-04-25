@php
    /** @var \App\Models\Article $article */
    $summary = $cardSummaries[$article->id] ?? '';
    $pub = $article->published_at ?? $article->created_at;
@endphp
<article class="article-shell entry-card">
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center space-x-2">
                @if(!empty($showFeaturedBadge))
                    <span class="pill-tag">
                        <i data-lucide="star" class="w-3 h-3 mr-1"></i>
                        {{ __('site.home_featured_badge') }}
                    </span>
                @endif
                @if($article->category)
                    <a href="{{ route('site.category', $article->category->slug) }}" class="pill-tag">
                        {{ $article->category->name }}
                    </a>
                @endif
            </div>
            <time class="text-sm text-gray-500" datetime="{{ $pub?->toAtomString() }}">
                {{ $pub?->format('Y-m-d') }}
            </time>
        </div>

        <h2 class="entry-title font-semibold text-gray-900 mb-3">
            <a href="{{ route('site.article', $article->slug) }}" class="hover:text-blue-600">
                {{ $article->title }}
            </a>
        </h2>

        <p class="entry-summary mb-4 leading-relaxed text-gray-600">
            {{ $summary }}
        </p>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-end gap-3">
            <a href="{{ route('site.article', $article->slug) }}" class="read-more-btn self-start sm:self-center">
                {{ __('site.home_read_more') }}
                <i data-lucide="arrow-right" class="w-4 h-4 ml-1"></i>
            </a>
        </div>
    </div>
</article>
