@php
    $pwaManifestPath = \App\Support\AdminWeb::appPath('/manifest.webmanifest');
    $pwaIconPath = \App\Support\AdminWeb::appPath('/icons/geoflow-app.svg');
    $pwaAppleTouchIconPath = \App\Support\AdminWeb::appPath('/icons/geoflow-app-192.png');
@endphp
<meta name="application-name" content="GEOFlow">
<meta name="theme-color" content="#ffffff">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="{{ $pwaManifestPath }}">
<link rel="icon" type="image/svg+xml" href="{{ $pwaIconPath }}">
<link rel="apple-touch-icon" sizes="192x192" href="{{ $pwaAppleTouchIconPath }}">
