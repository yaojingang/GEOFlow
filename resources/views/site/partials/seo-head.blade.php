@php
    $seoSiteName = trim((string) ($siteName ?? $siteTitle ?? config('geoflow.site_name', config('app.name'))));
    $seoTitle = trim((string) ($pageTitle ?? $seoSiteName));
    $seoDescription = trim((string) ($pageDescription ?? ($siteDescription ?? '')));
    $seoKeywords = trim((string) ($pageKeywords ?? ($siteKeywords ?? '')));
    $seoCanonical = trim((string) ($canonicalUrl ?? url()->current()));
    $seoOgType = trim((string) ($pageOgType ?? 'website'));
    $pwaCurrentSite = app(\App\Support\Site\CurrentSite::class);
    $pwaEnabled = $pwaCurrentSite->isResolved() && $pwaCurrentSite->isPrimary();

    if ($seoTitle === '') {
        $seoTitle = $seoSiteName;
    }

    if ($seoOgType === '') {
        $seoOgType = 'website';
    }
@endphp
@if($pwaEnabled)
    <x-pwa-head />
    @vite('resources/js/pwa.js')
@endif
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
@if((isset($siteIndexingAllowed) && !$siteIndexingAllowed) || ($pageNoindex??false))
    <meta name="robots" content="noindex, nofollow">
@endif
@if($seoKeywords !== '')
    <meta name="keywords" content="{{ $seoKeywords }}">
@endif
@if(!empty($siteFavicon))
    <link rel="icon" href="{{ $siteFavicon }}">
@endif
@if($seoCanonical !== '')
    <link rel="canonical" href="{{ $seoCanonical }}">
@endif
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:type" content="{{ $seoOgType }}">
@if($seoCanonical !== '')
    <meta property="og:url" content="{{ $seoCanonical }}">
@endif
@if($seoSiteName !== '')
    <meta property="og:site_name" content="{{ $seoSiteName }}">
@endif

@if(!empty($friendLinks))
    <link rel="stylesheet" href="{{ asset('assets/css/friend-links.css') }}">
@endif

@include('site.partials.topic-assets')
@if(!empty($topicStructuredData))
<script type="application/ld+json">{!! json_encode($topicStructuredData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) !!}</script>
@endif
