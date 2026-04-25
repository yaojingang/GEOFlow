<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('admin.login.title') }} — {{ $adminSiteName }}</title>
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <style>
        body {
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0) 32%),
                radial-gradient(circle at bottom right, rgba(229, 231, 235, 0.72), rgba(229, 231, 235, 0) 30%),
                linear-gradient(180deg, #f5f5f7 0%, #e5e7eb 100%);
            min-height: 100vh;
        }
        .login-form {
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(24px) saturate(180%);
            border: 1px solid rgba(209, 213, 219, 0.9);
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
        }
        .login-badge {
            background: linear-gradient(180deg, #6b7280 0%, #374151 100%);
        }
    </style>
</head>
<body class="overflow-hidden">
<div class="fixed right-4 top-4 z-50">
    <select onchange="window.location.href=this.value" class="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 shadow-sm">
        @foreach (\App\Support\AdminWeb::supportedLocales() as $localeCode => $localeLabel)
            <option value="{{ route('admin.locale.switch', ['locale' => $localeCode]) }}" @selected(app()->getLocale() === $localeCode)>
                {{ $localeLabel }}
            </option>
        @endforeach
    </select>
</div>
<div class="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-md px-4">
    <div class="rounded-2xl p-8 login-form">
        <div class="text-center mb-8">
            <div class="login-badge w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="shield-check" class="w-8 h-8 text-white"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ __('admin.login.title') }}</h1>
            <p class="text-gray-600">{{ __('admin.login.subtitle', ['site_name' => $adminSiteName]) }}</p>
        </div>
        @if (session('message'))
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
                {{ session('message') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                {{ $errors->first() }}
            </div>
        @endif
        <form method="POST" action="{{ route('admin.login.attempt') }}" class="space-y-6">
            @csrf
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.login.username') }}</label>
                <input type="text" id="username" name="username" required value="{{ old('username') }}"
                       class="block w-full px-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="{{ __('admin.login.username_placeholder') }}" autocomplete="username">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.login.password') }}</label>
                <input type="password" id="password" name="password" required
                       class="block w-full px-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="{{ __('admin.login.password_placeholder') }}" autocomplete="current-password">
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg">
                {{ __('admin.login.submit') }}
            </button>
        </form>
    </div>
    <div class="text-center mt-6">
        <a href="{{ url('/') }}" class="text-gray-600 hover:text-gray-900 text-sm">{{ __('admin.login.back_home') }}</a>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof lucide !== 'undefined') lucide.createIcons();
    });
</script>
</body>
</html>
