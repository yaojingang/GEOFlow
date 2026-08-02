@php
    $trendRows = collect($series ?? [])->values();
    $trendMetrics = collect($metrics ?? [])->values();
    $chartWidth = 720;
    $chartHeight = 226;
    $plotLeft = 48;
    $plotRight = 704;
    $plotTop = 18;
    $plotBottom = 194;
    $plotHeight = $plotBottom - $plotTop;
    $trendCount = $trendRows->count();
    $trendMax = max(1, ...$trendMetrics->map(fn ($metric) => (float) ($trendRows->max($metric['key']) ?? 0))->all());
    $trendPoints = $trendRows->map(function ($row, $index) use ($trendCount, $trendMetrics, $plotLeft, $plotRight, $plotTop, $plotBottom, $plotHeight, $trendMax) {
        $x = $trendCount > 1 ? $plotLeft + (($plotRight - $plotLeft) * $index / ($trendCount - 1)) : ($plotLeft + $plotRight) / 2;
        $positions = [];
        foreach ($trendMetrics as $metric) {
            $value = max(0, (float) ($row[$metric['key']] ?? 0));
            $positions[$metric['key']] = $plotBottom - (($value / $trendMax) * $plotHeight);
        }

        return ['x' => $x, 'positions' => $positions];
    });
    $defaultTrend = $trendRows->last() ?? ['date' => ''];
    $defaultPoint = $trendPoints->last() ?? ['x' => ($plotLeft + $plotRight) / 2, 'positions' => []];
@endphp

<div data-analytics-trend data-default-index="{{ max(0, $trendCount - 1) }}" data-preview-label="{{ __('admin.analytics.trend.preview') }}" data-pinned-label="{{ __('admin.analytics.trend.pinned') }}">
    <script type="application/json" data-analytics-trend-data>@json($trendRows->all())</script>
    <script type="application/json" data-analytics-trend-metrics>@json($trendMetrics->all())</script>
    <div class="overflow-x-auto">
        <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="h-60 min-w-[42rem] w-full touch-manipulation outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2" role="group" tabindex="0" aria-label="{{ $chartLabel ?? __('admin.analytics.trend.aria') }}" aria-keyshortcuts="ArrowLeft ArrowRight Enter Escape" data-analytics-trend-chart>
            <line x1="{{ $plotLeft }}" y1="{{ $plotBottom }}" x2="{{ $plotRight }}" y2="{{ $plotBottom }}" stroke="#d1d5db" />
            <line x1="{{ $defaultPoint['x'] }}" y1="{{ $plotTop }}" x2="{{ $defaultPoint['x'] }}" y2="{{ $plotBottom }}" stroke="#94a3b8" stroke-dasharray="4 4" data-analytics-trend-guide />
            @foreach ($trendMetrics as $metric)
                @php
                    $polyline = $trendPoints->map(fn ($point) => number_format($point['x'], 2, '.', '').','.number_format($point['positions'][$metric['key']] ?? $plotBottom, 2, '.', ''))->implode(' ');
                @endphp
                <polyline points="{{ $polyline }}" fill="none" stroke="{{ $metric['color'] }}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" data-analytics-series="{{ $metric['key'] }}" />
                <circle cx="{{ $defaultPoint['x'] }}" cy="{{ $defaultPoint['positions'][$metric['key']] ?? $plotBottom }}" r="5" fill="white" stroke="{{ $metric['color'] }}" stroke-width="3" data-analytics-trend-marker="{{ $metric['key'] }}" />
            @endforeach
            @foreach ($trendPoints as $index => $point)
                <circle cx="{{ $point['x'] }}" cy="{{ $plotBottom }}" r="10" fill="transparent" data-analytics-trend-point data-x="{{ $point['x'] }}" data-y='@json($point['positions'])' aria-hidden="true" />
            @endforeach
        </svg>
    </div>
    @include('admin.analytics._date-axis', ['series' => $trendRows->all()])
    <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4" aria-live="polite">
        <div class="flex items-center justify-between gap-3">
            <time class="font-mono text-sm font-semibold tabular-nums text-gray-900" data-analytics-trend-date>{{ $defaultTrend['date'] ?? '' }}</time>
            <span class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-gray-500 ring-1 ring-gray-200" data-analytics-trend-state>{{ __('admin.analytics.trend.preview') }}</span>
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($trendMetrics as $metric)
                <div>
                    <dt class="flex items-center gap-1.5 text-xs text-gray-500"><span class="h-2 w-2 rounded-full" style="background-color: {{ $metric['color'] }}"></span>{{ $metric['label'] }}</dt>
                    <dd class="mt-1 text-right font-mono text-lg font-semibold tabular-nums text-gray-900" data-analytics-trend-value="{{ $metric['key'] }}">{{ number_format((float) ($defaultTrend[$metric['key']] ?? 0), $metric['decimals'] ?? 0) }}{{ $metric['suffix'] ?? '' }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</div>
