<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('code') · GEOFlow</title>
    <link rel="stylesheet" href="{{ asset('css/admin-error-v3.css') }}">
</head>
<body class="gf-error-page">
    @php
        $adminPrefix = trim((string) config('geoflow.admin_base_path', '/admin'), '/');
        $isAdminError = $adminPrefix !== '' && request()->is($adminPrefix, $adminPrefix.'/*');
    @endphp
    <main class="gf-error-card">
        <div class="gf-error-card__mark" aria-hidden="true">@yield('code')</div>
        <span>@yield('code')</span>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="gf-error-card__actions">
            @if ($isAdminError)
                <a class="gf-button gf-button--primary" href="{{ \App\Support\AdminWeb::routePath('admin.entry') }}">{{ __('admin.ui_v3.return_admin') }}</a>
            @endif
            <a class="gf-button {{ $isAdminError ? '' : 'gf-button--primary' }}" href="{{ url('/') }}">{{ __('site.back_home') }}</a>
        </div>
    </main>
</body>
</html>
