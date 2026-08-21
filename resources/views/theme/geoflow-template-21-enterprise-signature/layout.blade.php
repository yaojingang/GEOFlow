<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('site.partials.seo-head')
    @stack('head')
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/custom.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/geoflow-template-21-enterprise-signature/theme.css') }}">
    <script src="{{ asset('js/lucide.min.js') }}" defer></script>
    @if(!empty($headAnalyticsCode))
        {!! $headAnalyticsCode !!}
    @endif
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $websiteSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'WebSite',
            'name' => $siteName,
            'url' => route('site.home'),
            'potentialAction' => [
                $schemaAtType => 'SearchAction',
                'target' => route('site.home').'?search={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
    @endphp
    <x-json-ld :data="$websiteSchema" />
</head>
<body class="ent-body @yield('bodyClass')">
    <a class="ent-skip-link" href="#main-content">跳到主要内容</a>
    @include('theme.geoflow-template-21-enterprise-signature.partials.header')
    <main id="main-content" class="ent-main">
        @yield('content')
    </main>
    @include('theme.geoflow-template-21-enterprise-signature.partials.footer')
    @stack('scripts')
    <script src="{{ asset('assets/js/main.js') }}"></script>
    <script src="{{ asset('themes/geoflow-template-21-enterprise-signature/theme.js') }}" defer></script>
</body>
</html>
