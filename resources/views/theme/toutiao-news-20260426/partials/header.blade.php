@php
    $path = request()->path();
    $isHome = $path === '' || $path === '/';
@endphp
<header class="tt-header">
    <div class="tt-shell">
        <div class="tt-header-row">
            <a href="{{ route('site.home') }}" class="tt-brand" aria-label="{{ $siteName }}">
                @if(!empty($siteLogo))
                    <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="h-9 w-auto max-w-48 object-contain">
                @else
                    <span>{{ $siteName }}</span>
                @endif
            </a>

            <nav class="tt-topnav" aria-label="Primary">
                <a href="{{ route('site.home') }}" class="{{ $isHome ? 'is-active' : '' }}">{{ __('front.nav.home') }}</a>
                @foreach($navCategories->take(5) as $categoryItem)
                    <a href="{{ route('site.category', $categoryItem->slug) }}">{{ $categoryItem->name }}</a>
                @endforeach
            </nav>

            <button type="button" class="tt-mobile-menu" onclick="document.getElementById('ttMobileNav')?.classList.toggle('hidden')" aria-label="{{ __('front.nav.categories') }}">
                <i data-lucide="menu" class="w-7 h-7"></i>
            </button>
        </div>
        <div id="ttMobileNav" class="hidden pb-4">
            <div class="tt-channel-rail !sticky !top-auto">
                <a href="{{ route('site.home') }}" class="tt-channel {{ $isHome ? 'is-active' : '' }}">{{ __('front.nav.home') }}</a>
                @foreach($navCategories as $categoryItem)
                    <a href="{{ route('site.category', $categoryItem->slug) }}" class="tt-channel">{{ $categoryItem->name }}</a>
                @endforeach
            </div>
        </div>
    </div>
</header>
