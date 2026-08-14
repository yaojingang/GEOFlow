<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ?? '正负零' }}</title>
    <meta name="description" content="{{ $pageDescription ?? '' }}">
    <meta name="robots" content="{{ $robotsDirective ?? 'index,follow' }}">
    <link rel="canonical" href="{{ $canonicalUrl ?? route('site.home') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $pageTitle ?? '正负零' }}">
    <meta property="og:description" content="{{ $pageDescription ?? '' }}">
    <meta property="og:url" content="{{ $canonicalUrl ?? route('site.home') }}">
    <meta name="theme-color" content="#f7f1e7">
    <link rel="icon" href="{{ asset('themes/zeropoint/logo-symbol.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('themes/zeropoint/theme.css') }}">
    @if(is_array($schemaData ?? null))
        <x-json-ld :data="$schemaData" />
    @endif
</head>
<body>
    <a class="skip-link" href="#main-content">跳到正文</a>
    @if($isPlaceholder ?? false)
        <div class="prototype-bar" role="status">本地内容原型 · 事实与责任人确认前不进入正式索引</div>
    @endif
    <header class="site-header" data-site-header>
        <div class="shell header-inner">
            <a class="brand" href="{{ route('site.home') }}" aria-label="正负零首页">
                <img src="{{ asset('themes/zeropoint/logo.svg') }}" alt="正负零 Zero Point">
            </a>
            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
                <span></span><span></span><span></span><span class="sr-only">展开导航</span>
            </button>
            <nav id="site-nav" class="site-nav" aria-label="主导航" data-site-nav>
                <a href="{{ route('site.home') }}">首页</a>
                <a href="{{ route('site.zeropoint.first-visit') }}">首次到店</a>
                <a href="{{ route('site.zeropoint.credentials') }}">资质核验</a>
                <a href="{{ route('site.zeropoint.health.index') }}">健康知识</a>
                <a href="{{ route('site.zeropoint.rights') }}">权益与纠错</a>
                <a href="{{ route('site.zeropoint.search') }}">搜索</a>
                @if($bookingAvailable ?? false)
                    <a class="nav-cta" href="{{ route('site.zeropoint.booking') }}">预约到店</a>
                @endif
            </nav>
        </div>
    </header>

    <main id="main-content">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="shell footer-grid">
            <div>
                <img class="footer-logo" src="{{ asset('themes/zeropoint/logo-reverse.svg') }}" alt="正负零 Zero Point">
                <p>归零，回到真实问题；溯源，回到可信证据；共生，回到长期关系。</p>
            </div>
            <div>
                <h2>信息核验</h2>
                <a href="{{ route('site.zeropoint.credentials') }}">主体与资质</a>
                <a href="{{ route('site.zeropoint.contact') }}">地址与联系</a>
                <a href="{{ route('site.zeropoint.rights') }}">隐私与纠错</a>
            </div>
            <div>
                <h2>重要说明</h2>
                <p>网站内容用于一般信息与健康教育，不构成在线诊断，也不能替代有资质医师的面诊判断。</p>
            </div>
        </div>
        <div class="shell footer-bottom">© {{ now()->year }} 正负零 · 信息以当前审核版本为准</div>
    </footer>
    <script src="{{ asset('themes/zeropoint/site.js') }}" defer></script>
</body>
</html>
