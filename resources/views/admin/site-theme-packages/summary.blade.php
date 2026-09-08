<section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 bg-gray-50 p-5">
        <div class="min-w-0"><h2 class="break-words text-xl font-semibold text-gray-900">{{ $package['theme']['name'] }}</h2><p class="mt-1 break-all font-mono text-xs text-gray-500">{{ $package['theme']['id'] }}</p></div>
        @if(($package['distribution']['visibility'] ?? '') === 'customer_private')<span class="rounded-md bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">{{ __('admin.theme_packages.private') }}</span>@endif
    </div>
    <dl class="grid gap-5 p-5 sm:grid-cols-3">
        <div><dt class="text-xs text-gray-500">{{ __('admin.theme_packages.version') }}</dt><dd class="mt-1 break-words text-sm font-medium text-gray-900">{{ $package['theme']['version'] }}</dd></div>
        <div><dt class="text-xs text-gray-500">{{ __('admin.theme_packages.files') }}</dt><dd class="mt-1 text-sm font-medium text-gray-900">{{ count($package['files']) }}</dd></div>
        <div><dt class="text-xs text-gray-500">{{ __('admin.theme_packages.unpacked_size') }}</dt><dd class="mt-1 text-sm font-medium text-gray-900">{{ number_format(collect($package['files'])->sum('bytes') / 1024 / 1024, 2) }} MiB</dd></div>
    </dl>
    @if(!empty($package['distribution']['note']))<p class="border-t border-gray-100 px-5 py-4 text-sm leading-6 text-gray-600">{{ $package['distribution']['note'] }}</p>@endif
    <div class="space-y-3 border-t border-gray-100 px-5 py-4 text-sm text-gray-600">
        <p class="font-medium text-green-700">{{ __('admin.theme_packages.compatibility') }}</p>
        <p>{{ __('admin.theme_packages.exported_with') }}: GEOFlow {{ $package['exported_with']['geoflow'] }} · PHP {{ $package['exported_with']['php'] }} · Laravel {{ $package['exported_with']['laravel'] }}</p>
        <p>{{ __('admin.theme_packages.pages') }}: @foreach($package['pages']['provided'] as $page){{ __('admin.theme_packages.preview.page.'.$page) }}{{ $loop->last ? '' : ' · ' }}@endforeach</p>
        @if(count($package['pages']['fallback']) > 0)<p class="text-xs text-gray-500">{{ __('admin.theme_packages.fallback_pages') }}</p>@endif
    </div>
    <details class="border-t border-gray-200">
        <summary class="cursor-pointer px-5 py-4 text-sm font-medium text-gray-800">{{ __('admin.theme_packages.requirements') }}</summary>
        <dl class="space-y-4 px-5 pb-5 text-sm">
            @foreach(['geoflow' => 'GEOFlow', 'php' => 'PHP', 'laravel' => 'Laravel'] as $component => $label)
                <div><dt class="font-medium text-gray-700">{{ $label }}</dt><dd class="mt-1 break-all font-mono text-xs text-gray-600">{{ $package['requires'][$component] }}</dd></div>
            @endforeach
            @foreach(['contracts', 'views', 'routes', 'assets'] as $kind)
                @if(!empty($package['requires'][$kind]))
                    <div><dt class="font-medium text-gray-700">{{ __('admin.theme_packages.requirement_'.$kind) }}</dt><dd class="mt-1 space-y-1 break-all font-mono text-xs text-gray-600">@foreach($package['requires'][$kind] as $key => $dependency)<p>{{ $kind === 'contracts' ? $key.' = '.$dependency : $dependency }}</p>@endforeach</dd></div>
                @endif
            @endforeach
        </dl>
    </details>
    <details class="border-t border-gray-200">
        <summary class="cursor-pointer px-5 py-4 text-sm font-medium text-gray-800">{{ __('admin.theme_packages.file_details') }}</summary>
        <div class="overflow-x-auto px-5 pb-5">
            <table class="w-full table-fixed text-left text-xs"><thead><tr class="border-b border-gray-200 text-gray-500"><th scope="col" class="py-2 font-medium">{{ __('admin.theme_packages.file_path') }}</th><th scope="col" class="w-24 py-2 text-right font-medium">{{ __('admin.theme_packages.bytes') }}</th></tr></thead><tbody>@foreach($package['files'] as $file)<tr class="border-b border-gray-100"><td class="break-all py-2 pr-4 font-mono text-gray-700"><span class="block">{{ $file['path'] }}</span>@if(isset($inspection))<a class="mt-1 flex min-h-11 items-center font-sans text-xs font-medium text-blue-700 underline hover:text-blue-900" href="{{ route('admin.site-settings.theme-packages.imports.file', ['token' => $inspection['token'], 'fileIndex' => $loop->index]) }}" aria-label="{{ __('admin.theme_packages.file_view') }}: {{ $file['path'] }}">{{ __('admin.theme_packages.file_view') }}</a>@endif<span class="mt-1 block text-[10px] text-gray-400">SHA-256: {{ $file['sha256'] }}</span></td><td class="py-2 text-right text-gray-500">{{ number_format($file['bytes']) }}</td></tr>@endforeach</tbody></table>
        </div>
    </details>
</section>
