@php
    $currentPath = request()->path();
    $isHome = $currentPath === '' || $currentPath === '/';
    $repositoryUrl = 'https://github.com/yaojingang/GEOFlow';
@endphp

<header class="ent-header" data-ent-header>
    <div class="ent-demo-bar">
        <div class="ent-shell ent-demo-bar__inner">
            <span class="ent-demo-dot" aria-hidden="true"></span>
            <span>企业官网模板预览</span>
            <span>客户、案例、覆盖与指标均为演示信息</span>
        </div>
    </div>

    <div class="ent-shell ent-header__row">
        <a href="{{ route('site.home') }}" class="ent-brand" aria-label="{{ $siteName }}">
            @if(!empty($siteLogo))
                <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="ent-brand__logo">
            @else
                <span class="ent-brand__mark" aria-hidden="true">
                    <span>G</span>
                </span>
                <span class="ent-brand__name">{{ $siteName }}</span>
            @endif
        </a>

        <nav class="ent-nav" aria-label="主要导航">
            <a href="{{ route('site.home') }}" data-nav-item="home" class="{{ $isHome ? 'is-active' : '' }}">{{ __('front.nav.home') }}</a>
            <a href="{{ route('site.home').'#platform' }}">平台</a>
            <a href="{{ route('site.home').'#ecosystem' }}">开源生态</a>
            @foreach($navCategories->take(2) as $categoryItem)
                <a href="{{ route('site.category', $categoryItem->slug) }}" class="{{ request()->is('category/'.$categoryItem->slug) ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
            @endforeach
            <a href="{{ route('site.archive') }}" class="{{ request()->routeIs('site.archive*') ? 'is-active' : '' }}">洞察</a>
            <a href="{{ route('site.about') }}" class="{{ request()->routeIs('site.about') ? 'is-active' : '' }}">关于</a>
        </nav>

        <div class="ent-header__actions">
            <button
                type="button"
                class="ent-search-trigger"
                data-ent-search-open
                aria-label="打开搜索"
                aria-expanded="false"
                aria-controls="ent-search-panel"
            >
                <i data-lucide="search" aria-hidden="true"></i>
            </button>
            <a href="{{ $repositoryUrl }}" class="ent-header__repository" target="_blank" rel="noopener noreferrer">
                <i data-lucide="code-2" aria-hidden="true"></i>
                <span>开源仓库</span>
            </a>
            <button
                type="button"
                class="ent-menu-trigger"
                data-ent-menu-trigger
                aria-expanded="false"
                aria-controls="ent-mobile-nav"
                aria-label="打开导航"
            >
                <i data-lucide="menu" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div id="ent-search-panel" class="ent-search-panel" data-ent-search-panel hidden>
        <div class="ent-shell ent-search-panel__inner">
            <form method="get" action="{{ route('site.home') }}" class="ent-search-form" role="search">
                <i data-lucide="search" aria-hidden="true"></i>
                <label class="sr-only" for="ent-site-search">{{ __('site.search_placeholder') }}</label>
                <input id="ent-site-search" type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('site.search_placeholder') }}" data-ent-search-input>
                <button type="submit">{{ __('site.search_button') }}</button>
            </form>
            <button type="button" class="ent-search-close" data-ent-search-close aria-label="关闭搜索">
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <nav id="ent-mobile-nav" class="ent-mobile-nav" data-ent-mobile-nav aria-label="移动导航" hidden>
        <div class="ent-shell ent-mobile-nav__inner">
            <a href="{{ route('site.home') }}" data-nav-item="home" class="{{ $isHome ? 'is-active' : '' }}">{{ __('front.nav.home') }}</a>
            <a href="{{ route('site.home').'#platform' }}">平台</a>
            <a href="{{ route('site.home').'#ecosystem' }}">开源生态</a>
            @foreach($navCategories as $categoryItem)
                <a href="{{ route('site.category', $categoryItem->slug) }}" class="{{ request()->is('category/'.$categoryItem->slug) ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
            @endforeach
            <a href="{{ route('site.archive') }}">洞察归档</a>
            <a href="{{ route('site.about') }}" class="{{ request()->routeIs('site.about') ? 'is-active' : '' }}">关于 GEOFlow</a>
            <a href="{{ $repositoryUrl }}" target="_blank" rel="noopener noreferrer">GitHub 开源仓库</a>
        </div>
    </nav>
</header>
