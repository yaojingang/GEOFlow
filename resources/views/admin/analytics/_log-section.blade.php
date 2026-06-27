<section class="mb-8 rounded-lg bg-white shadow-sm ring-1 ring-gray-200" data-analytics-log-section>
    <div class="border-b border-gray-100 px-6 py-5">
        <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.analytics.self_log_title') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.self_log_desc') }}</p>
        <div class="mt-4 rounded-lg border border-amber-100 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800">
            <div class="flex items-start gap-2">
                <i data-lucide="info" class="mt-0.5 h-4 w-4 shrink-0"></i>
                <p>{{ __('admin.analytics.logs_boundary_note') }}</p>
            </div>
        </div>
    </div>

    @if (empty($logSummary['has_data']))
        <div class="px-6 py-12 text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gray-100">
                <i data-lucide="file-search" class="h-7 w-7 text-gray-400"></i>
            </div>
            <p class="mt-4 text-sm font-medium text-gray-500">{{ __('admin.analytics.logs_title') }}</p>
            <h3 class="mt-4 text-lg font-semibold text-gray-900">{{ __('admin.analytics.logs_empty_title') }}</h3>
            <p class="mx-auto mt-2 max-w-2xl text-sm leading-6 text-gray-500">{{ __('admin.analytics.logs_empty_desc') }}</p>
        </div>
    @else
        <div class="space-y-6 p-6">
            <div>
                <div class="mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.analytics.logs_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.analytics.logs_desc') }}</p>
                </div>
                <h4 class="mb-4 text-base font-semibold text-gray-900">{{ __('admin.analytics.logs_overview') }}</h4>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        ['key' => 'pv', 'icon' => 'mouse-pointer-click', 'tone' => 'text-blue-600'],
                        ['key' => 'unique_ip', 'icon' => 'network', 'tone' => 'text-emerald-600'],
                        ['key' => 'ai_bot_pv', 'icon' => 'bot', 'tone' => 'text-purple-600'],
                        ['key' => 'errors', 'icon' => 'triangle-alert', 'tone' => 'text-red-600'],
                    ] as $card)
                        <div class="rounded-lg bg-gray-50 p-5">
                            <div class="flex items-center gap-4">
                                <i data-lucide="{{ $card['icon'] }}" class="h-6 w-6 {{ $card['tone'] }}"></i>
                                <div>
                                    <div class="whitespace-nowrap text-sm font-medium text-gray-500">{{ __('admin.analytics.logs_kpi.'.$card['key']) }}</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format((int) ($logSummary['kpis'][$card['key']] ?? 0)) }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <div class="rounded-lg border border-gray-100">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('admin.analytics.logs_trend') }}</h3>
                    </div>
                    <div class="overflow-x-auto p-5">
                        @php
                            $trendMax = max(1, ...array_map(fn ($row) => (int) ($row['pv'] ?? 0), $logSummary['traffic_trend'] ?? []));
                        @endphp
                        <div class="flex h-44 items-end gap-3 border-b border-gray-200 pb-2">
                            @foreach (($logSummary['traffic_trend'] ?? []) as $row)
                                @php
                                    $height = max(8, (int) round(((int) $row['pv'] / $trendMax) * 150));
                                    $aiHeight = (int) $row['pv'] > 0 ? max(0, (int) round(((int) $row['ai_bot_pv'] / max(1, (int) $row['pv'])) * $height)) : 0;
                                @endphp
                                <div class="flex min-w-[3.25rem] flex-col items-center gap-2">
                                    <div class="flex w-8 flex-col justify-end overflow-hidden rounded-t bg-blue-100" style="height: {{ $height }}px">
                                        @if ($aiHeight > 0)
                                            <div class="bg-purple-500" style="height: {{ $aiHeight }}px"></div>
                                        @endif
                                        <div class="bg-blue-500" style="height: {{ max(2, $height - $aiHeight) }}px"></div>
                                    </div>
                                    <div class="text-xs font-medium text-gray-700">{{ (int) $row['pv'] }}</div>
                                </div>
                            @endforeach
                        </div>
                        @include('admin.analytics._date-axis', ['series' => $logSummary['traffic_trend'] ?? []])
                    </div>
                </div>

                <div class="rounded-lg border border-gray-100">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('admin.analytics.logs_bot_breakdown') }}</h3>
                    </div>
                    <div class="space-y-4 p-5">
                        @php
                            $botMax = max(1, ...array_map(fn ($row) => (int) ($row['count'] ?? 0), $logSummary['bot_breakdown'] ?? []));
                        @endphp
                        @foreach (($logSummary['bot_breakdown'] ?? []) as $row)
                            @php
                                $percent = min(100, round(((int) $row['count'] / $botMax) * 100));
                            @endphp
                            <div>
                                <div class="mb-1 flex items-center justify-between gap-4 text-sm">
                                    <span class="font-medium text-gray-700">{{ $row['label'] }}</span>
                                    <span class="whitespace-nowrap font-semibold text-gray-900">{{ number_format((int) $row['count']) }}</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-gray-100">
                                    <div class="h-full rounded-full bg-slate-700" style="width: {{ $percent }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <div class="rounded-lg border border-gray-100">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('admin.analytics.logs_top_articles') }}</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.analytics.logs_table.article') }}</th>
                                    <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.analytics.logs_table.views') }}</th>
                                    <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.analytics.logs_table.unique_ip') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse (($logSummary['top_articles'] ?? []) as $article)
                                    <tr>
                                        <td class="min-w-[18rem] px-5 py-4 text-sm font-medium text-gray-900">
                                            <a href="{{ route('admin.articles.edit', ['articleId' => $article['article_id']]) }}" class="hover:text-blue-600">{{ $article['title'] }}</a>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-700">{{ number_format((int) $article['views']) }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-500">{{ number_format((int) $article['unique_ip']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="px-5 py-8 text-center text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-100">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('admin.analytics.logs_top_paths') }}</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.analytics.logs_table.path') }}</th>
                                    <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.analytics.logs_table.views') }}</th>
                                    <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.analytics.logs_table.unique_ip') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse (($logSummary['top_paths'] ?? []) as $path)
                                    <tr>
                                        <td class="min-w-[18rem] px-5 py-4 font-mono text-sm text-gray-900">{{ $path['path'] }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-700">{{ number_format((int) $path['views']) }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-500">{{ number_format((int) $path['unique_ip']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="px-5 py-8 text-center text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
</section>
