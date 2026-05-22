@php
    $axisRows = array_values($series ?? []);
    $axisCount = count($axisRows);
    $axisLabels = [];

    if ($axisCount === 1) {
        $axisLabels = ['middle' => $axisRows[0]];
    } elseif ($axisCount === 2) {
        $axisLabels = [
            'start' => $axisRows[0],
            'end' => $axisRows[1],
        ];
    } elseif ($axisCount > 2) {
        $axisLabels = [
            'start' => $axisRows[0],
            'middle' => $axisRows[(int) floor(($axisCount - 1) / 2)],
            'end' => $axisRows[$axisCount - 1],
        ];
    }
@endphp

@if ($axisLabels !== [])
    <div data-analytics-axis="compact" class="mt-3 flex items-center justify-between text-xs text-gray-500">
        @foreach ($axisLabels as $position => $row)
            <span data-axis-label="{{ $position }}" class="whitespace-nowrap @if($position === 'middle') text-center @elseif($position === 'end') text-right @endif">
                {{ substr((string) ($row['date'] ?? ''), 5) }}
            </span>
        @endforeach
    </div>
@endif
