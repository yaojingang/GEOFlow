@php
    $width = 600;
    $height = 180;
    $values = [];
    foreach ($series as $row) {
        $values[] = (int) ($row[$primaryKey] ?? 0);
        $values[] = (int) ($row[$secondaryKey] ?? 0);
    }
    $max = max(1, ...$values);
    $count = max(1, count($series));
    $step = $count > 1 ? $width / ($count - 1) : $width;
    $buildPoints = function (string $key) use ($series, $height, $max, $step): string {
        $points = [];
        foreach ($series as $index => $row) {
            $x = $index * $step;
            $y = $height - (((int) ($row[$key] ?? 0) / $max) * ($height - 12)) - 6;
            $points[] = round($x, 2).','.round($y, 2);
        }
        return implode(' ', $points);
    };
@endphp

<div class="overflow-x-auto">
    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-56 min-w-[38rem] w-full" role="img">
        <line x1="0" y1="{{ $height - 6 }}" x2="{{ $width }}" y2="{{ $height - 6 }}" stroke="#e5e7eb" />
        <polyline points="{{ $buildPoints($primaryKey) }}" fill="none" stroke="#2563eb" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
        <polyline points="{{ $buildPoints($secondaryKey) }}" fill="none" stroke="#059669" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</div>
@include('admin.analytics._date-axis', ['series' => $series])
