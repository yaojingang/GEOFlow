@php
    $seoSiteName = trim((string) ($siteName ?? $siteTitle ?? config('geoflow.site_name', config('app.name'))));
    $seoTitle = trim((string) ($pageTitle ?? $seoSiteName));
    $seoDescription = trim((string) ($pageDescription ?? ($siteDescription ?? '')));
    $seoKeywords = trim((string) ($pageKeywords ?? ($siteKeywords ?? '')));
    $seoCanonical = trim((string) ($canonicalUrl ?? url()->current()));
    $seoOgType = trim((string) ($pageOgType ?? 'website'));

    if ($seoTitle === '') {
        $seoTitle = $seoSiteName;
    }

    if ($seoOgType === '') {
        $seoOgType = 'website';
    }
@endphp
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
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
