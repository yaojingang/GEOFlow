@php
    $adminBrandName = \App\Support\AdminWeb::siteName();
    $adminUiV3Enabled = (bool) config('geoflow.admin_ui_v3_enabled', false);
    $currentAdmin = auth('admin')->user();
    $uiV3 = is_array($adminUiV3 ?? null) ? $adminUiV3 : [];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (is_array($anonymousUsageTelemetryPayload ?? null))
        <meta name="geoflow-telemetry-endpoint" content="{{ $anonymousUsageTelemetryPayload['endpoint'] }}">
        <meta name="geoflow-telemetry-event" content="{{ $anonymousUsageTelemetryPayload['event'] }}">
        <meta name="geoflow-telemetry-instance" content="{{ $anonymousUsageTelemetryPayload['instance_id'] }}">
        <meta name="geoflow-telemetry-user" content="{{ $anonymousUsageTelemetryPayload['user_hash'] }}">
        <meta name="geoflow-telemetry-version" content="{{ $anonymousUsageTelemetryPayload['version'] }}">
        <meta name="geoflow-telemetry-interval" content="{{ $anonymousUsageTelemetryPayload['interval_seconds'] }}">
    @endif
    <title>@isset($pageTitle){{ $pageTitle }} · @endisset{{ $adminBrandName }}</title>
    @if ($adminUiV3Enabled)
        <script data-gf-sidebar-bootstrap>
            (() => {
                const root = document.documentElement;
                root.setAttribute('data-gf-ui-booting', '');
                let sidebarState = 'expanded';
                try {
                    sidebarState = window.localStorage.getItem('geoflow.admin.ui-v3.sidebar-collapsed') === '1'
                        ? 'collapsed'
                        : 'expanded';
                } catch {
                    sidebarState = 'expanded';
                }
                root.setAttribute('data-gf-sidebar-state', sidebarState);
            })();
        </script>
        @include('admin.partials.v3-runtime-config')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    @endif
    <script src="{{ asset('js/lucide.min.js') }}" data-lucide-runtime defer></script>
    @stack('styles')
</head>
@if ($adminUiV3Enabled && $currentAdmin instanceof \App\Models\Admin)
    <body class="gf-admin-v3 @if(request()->routeIs('admin.ai-workspace')) gf-page--ai @endif">
        <a class="gf-skip-link" href="#main-content">{{ __('admin.ui_v3.skip_to_content') }}</a>
        <div class="gf-shell" data-gf-shell>
            <button class="gf-sidebar-overlay" type="button" aria-label="{{ __('admin.ui_v3.close_sidebar') }}" data-sidebar-close></button>
            <x-admin.v3.sidebar
                :admin="$currentAdmin"
                :navigation="$uiV3['navigation'] ?? []"
                :current="$uiV3['current'] ?? []"
                :recent="$uiV3['recent'] ?? []"
            >
                @hasSection('sidebar-recent-action')
                    <x-slot:recentAction>@yield('sidebar-recent-action')</x-slot>
                @endif
                @hasSection('sidebar-recent-content')
                    <x-slot:recentContent>@yield('sidebar-recent-content')</x-slot>
                @endif
            </x-admin.v3.sidebar>
            <div class="gf-shell__body">
                <x-admin.v3.topbar
                    :admin="$currentAdmin"
                    :current="$uiV3['current'] ?? []"
                    :update-notification="$adminUpdateNotificationPayload ?? []"
                />
                <main class="gf-main" id="main-content">
                    @if (!empty($uiV3['show_settings_navigation']))
                        <x-admin.v3.settings-subnav :items="$uiV3['settings_navigation'] ?? []" />
                    @endif
                    <div class="gf-content">
                        @if (session('message'))
                            <div class="gf-flash gf-flash--success admin-flash-alert" role="status">
                                <i data-lucide="circle-check"></i><span>{{ session('message') }}</span>
                            </div>
                        @endif
                        @if ($errors->any())
                            <div class="gf-flash gf-flash--danger admin-flash-alert" role="alert" data-admin-errors>
                                <i data-lucide="circle-alert"></i>
                                <div>@foreach ($errors->all() as $err)<div>{{ $err }}</div>@endforeach</div>
                            </div>
                        @endif
                        @yield('content')
                    </div>
                </main>
            </div>
        </div>
        <x-admin.v3.dialogs :admin="$currentAdmin" :site-url="$uiV3['site_url'] ?? config('app.url')" />
        <div class="gf-toast" role="status" aria-live="polite" data-gf-toast></div>
        @include('admin.partials.welcome-modal')
        @if (is_array($anonymousUsageTelemetryPayload ?? null))
            <script src="{{ asset('js/geoflow-pulse.js') }}" defer></script>
        @endif
        @stack('scripts')
    </body>
@else
    <body class="bg-gray-50">
        @include('admin.partials.header', [
            'adminBrandName' => $adminBrandName,
            'adminSiteName' => $adminSiteName ?? $adminBrandName,
            'pageTitle' => $pageTitle ?? '',
            'activeMenu' => $activeMenu ?? '',
        ])
        <main class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
            @if (session('message'))
                <div class="admin-flash-alert mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    <span class="block sm:inline">{{ session('message') }}</span>
                </div>
            @endif
            @if ($errors->any())
                <div class="admin-flash-alert mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                    @foreach ($errors->all() as $err)<div>{{ $err }}</div>@endforeach
                </div>
            @endif
            @yield('content')
        </main>
        @include('admin.partials.footer')
        @include('admin.partials.welcome-modal')
        @vite('resources/js/app.js')
        @if (is_array($anonymousUsageTelemetryPayload ?? null))
            <script src="{{ asset('js/geoflow-pulse.js') }}" defer></script>
        @endif
        @stack('scripts')
    </body>
@endif
</html>
