@php
    $aiVisibilityTopUrls = $topCitedUrls ?? collect();
@endphp

<section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
    <div class="p-5">
        <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.ai_visibility.top_urls.panel_title') }}</h3>
        <p class="mt-1 text-sm text-gray-500">{{ __('admin.analytics.ai_visibility.top_urls.panel_desc') }}</p>
    </div>
    @if ($aiVisibilityTopUrls->isEmpty())
        <p class="px-5 pb-5 text-sm text-gray-500">{{ __('admin.analytics.ai_visibility.top_urls.no_data') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-2.5 text-left font-semibold text-gray-600">#</th>
                        <th class="px-5 py-2.5 text-left font-semibold text-gray-600">{{ __('admin.analytics.ai_visibility.top_urls.table_page') }}</th>
                        <th class="px-5 py-2.5 text-right font-semibold text-gray-600">{{ __('admin.analytics.ai_visibility.top_urls.table_citations') }}</th>
                        <th class="px-5 py-2.5 text-right font-semibold text-gray-600"><span class="sr-only">{{ __('admin.analytics.ai_visibility.top_urls.visit') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($aiVisibilityTopUrls as $index => $item)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-2.5 font-mono text-xs tabular-nums text-gray-400">{{ $index + 1 }}</td>
                            <td class="max-w-md px-5 py-2.5">
                                <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" class="block truncate font-medium text-violet-700 hover:text-violet-900 hover:underline" title="{{ $item['url'] }}">
                                    {{ $item['title'] }}
                                </a>
                                <p class="truncate text-xs text-gray-400">{{ $item['domain'] }}</p>
                            </td>
                            <td class="px-5 py-2.5 text-right font-mono text-sm font-semibold tabular-nums text-gray-800">{{ $item['citations'] }}</td>
                            <td class="px-5 py-2.5 text-right">
                                <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-8 items-center rounded-md border border-gray-200 px-2 text-xs font-medium text-gray-600 hover:border-violet-300 hover:text-violet-700">
                                    {{ __('admin.analytics.ai_visibility.top_urls.visit') }}
                                    <i data-lucide="external-link" class="ml-1 h-3 w-3"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
