<header class="mb-8" data-analytics-page-header>
    <div>
        <h1 class="text-3xl font-bold tracking-tight text-gray-950">{{ $title }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">{{ $subtitle }}</p>
    </div>
    <div class="mt-6 flex items-end gap-4" data-analytics-page-toolbar>
        @include('admin.analytics._navigation', ['analyticsNavigationClass' => 'min-w-0 flex-1'])
        @if ($showRefresh ?? true)
            <button type="button" onclick="location.reload()" class="inline-flex min-h-10 w-fit shrink-0 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 transition duration-[120ms] motion-reduce:transition-none hover:bg-gray-50 active:scale-[.98] motion-reduce:active:scale-100">
                <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                {{ __('admin.analytics.refresh') }}
            </button>
        @endif
    </div>
</header>
