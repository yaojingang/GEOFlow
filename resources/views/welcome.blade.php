<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center p-6">
    <div class="text-center max-w-lg">
        <h1 class="text-2xl font-semibold text-gray-900">{{ config('geoflow.site_name', config('app.name')) }}</h1>
        <p class="mt-2 text-gray-600 text-sm">{{ config('geoflow.site_full_name') }}</p>
        <div class="mt-8 flex flex-wrap justify-center gap-4">
            <a href="{{ route('admin.entry') }}" class="inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700">
                管理后台
            </a>
        </div>
    </div>
</body>
</html>
