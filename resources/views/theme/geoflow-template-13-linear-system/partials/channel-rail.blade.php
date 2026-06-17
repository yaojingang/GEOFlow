@php
    $activeCategoryId = $category->id ?? null;
@endphp
<aside class="ne-channel-rail" aria-label="{{ __('front.nav.categories') }}">
    <a href="{{ route('site.home') }}" class="ne-channel {{ empty($activeCategoryId) && (($activeNav ?? '') === 'home') ? 'is-active' : '' }}">
        <span>{{ __('front.nav.all_articles') }}</span>
    </a>
    @foreach($navCategories as $categoryItem)
        <a href="{{ route('site.category', $categoryItem->slug) }}" class="ne-channel {{ (int) $activeCategoryId === (int) $categoryItem->id ? 'is-active' : '' }}">
            <span>{{ $categoryItem->name }}</span>
            <small>{{ (int) ($categoryItem->published_count ?? 0) }}</small>
        </a>
    @endforeach
</aside>
