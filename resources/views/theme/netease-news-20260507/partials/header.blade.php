@php
    $path = request()->path();
    $isHome = $path === '' || $path === '/';
@endphp
<header class="ne-header">
    <div class="ne-globalbar">
        <div class="ne-shell ne-globalbar-row">
            <span>{{ $siteName }}</span>
        </div>
    </div>
    <div class="ne-shell">
        <div class="ne-header-row">
            <a href="{{ route('site.home') }}" class="ne-brand" aria-label="{{ $siteName }}">
                @if(!empty($siteLogo))
                    <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="h-9 w-auto max-w-48 object-contain">
                @else
                    <span class="ne-brand-mark">{{ mb_substr($siteName, 0, 1) }}</span>
                    <span>{{ $siteName }}</span>
                @endif
            </a>

            <nav class="ne-topnav" aria-label="Primary">
                <a href="{{ route('site.home') }}" class="{{ $isHome ? 'is-active' : '' }}">{{ __('front.nav.home') }}</a>
                @foreach($navCategories->take(5) as $categoryItem)
                    <a href="{{ route('site.category', $categoryItem->slug) }}" class="{{ request()->is('category/'.$categoryItem->slug) ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
                @endforeach
            </nav>

            <form method="get" action="{{ route('site.home') }}" class="ne-search" role="search">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('site.search_placeholder') }}">
                <button type="submit">{{ __('site.search_button') }}</button>
            </form>

            <button type="button" class="ne-mobile-menu" onclick="document.getElementById('neMobileNav')?.classList.toggle('hidden')" aria-label="{{ __('front.nav.categories') }}">
                <i data-lucide="menu" class="w-7 h-7"></i>
            </button>
        </div>
        <div id="neMobileNav" class="hidden pb-4">
            <div class="ne-channel-rail !sticky !top-auto">
                <a href="{{ route('site.home') }}" class="ne-channel {{ $isHome ? 'is-active' : '' }}">{{ __('front.nav.home') }}</a>
                @foreach($navCategories as $categoryItem)
                    <a href="{{ route('site.category', $categoryItem->slug) }}" class="ne-channel {{ request()->is('category/'.$categoryItem->slug) ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
                @endforeach
            </div>
        </div>
    </div>
</header>
