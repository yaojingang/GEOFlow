@php
    $adminBrandName = \App\Support\AdminWeb::siteName();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@isset($pageTitle){{ $pageTitle }} — @endisset{{ $adminBrandName }}</title>
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <style>
        body {
            font-feature-settings: "cv02", "cv03", "cv04", "cv11";
        }

        .admin-main {
            min-height: calc(100vh - 9rem);
        }

        .admin-main .rounded-2xl,
        .admin-main .rounded-xl,
        .admin-main .rounded-lg {
            border-radius: 8px;
        }

        .admin-main .shadow,
        .admin-main .shadow-sm,
        .admin-main .shadow-lg {
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        }

        .admin-main .bg-white {
            border-color: rgba(226, 232, 240, 0.92);
        }

        .admin-main table thead {
            background: #f8fafc;
        }

        .admin-main table th {
            font-size: 0.72rem;
            letter-spacing: 0;
        }

        .admin-main input,
        .admin-main select,
        .admin-main textarea {
            border-radius: 8px;
        }
    </style>
    @stack('styles')
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
@include('admin.partials.header', [
    'adminBrandName' => $adminBrandName,
    'adminSiteName' => $adminSiteName ?? $adminBrandName,
    'pageTitle' => $pageTitle ?? '',
    'activeMenu' => $activeMenu ?? '',
])
    <main data-admin-gui-shell class="admin-main mx-auto w-full max-w-[1440px] px-4 py-5 sm:px-6 lg:px-8">
        @if (session('message'))
            <div class="admin-flash-alert mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-700">
                <span class="block sm:inline">{{ session('message') }}</span>
            </div>
        @endif
        @if ($errors->any())
            <div class="admin-flash-alert mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-red-700">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif
        @yield('content')
    </main>
@include('admin.partials.footer')
@include('admin.partials.welcome-modal')
@stack('scripts')
</body>
</html>
