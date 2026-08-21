<header class="mb-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">{{ __('admin.analytics.page_title') }}</p>
            <h1 class="mt-1 text-3xl font-bold tracking-tight text-gray-950">{{ $title }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">{{ $subtitle }}</p>
        </div>
        <button type="button" onclick="location.reload()" class="inline-flex min-h-10 w-fit items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 transition duration-[120ms] motion-reduce:transition-none hover:bg-gray-50 active:scale-[.98] motion-reduce:active:scale-100">
            <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
            {{ __('admin.analytics.refresh') }}
        </button>
    </div>
    @include('admin.analytics._navigation')
</header>
