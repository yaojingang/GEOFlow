@php
    $max = 1;
    foreach ($series as $row) {
        $max = max($max, (int) $row['completed'] + (int) $row['failed'] + (int) $row['running'] + (int) $row['pending']);
    }
@endphp

<div class="flex h-56 items-end gap-3 overflow-x-auto border-b border-gray-200 pb-2">
    @foreach ($series as $row)
        @php
            $segments = [
                ['key' => 'completed', 'class' => 'bg-emerald-500'],
                ['key' => 'failed', 'class' => 'bg-red-500'],
                ['key' => 'running', 'class' => 'bg-blue-500'],
                ['key' => 'pending', 'class' => 'bg-amber-500'],
            ];
            $total = max(0, (int) $row['completed'] + (int) $row['failed'] + (int) $row['running'] + (int) $row['pending']);
            $height = max(8, (int) round(($total / $max) * 190));
        @endphp
        <div class="flex min-w-[3.25rem] flex-col items-center" title="{{ substr((string) $row['date'], 5) }}">
            <div class="flex w-8 flex-col justify-end overflow-hidden rounded-t bg-gray-100" style="height: {{ $height }}px">
                @foreach ($segments as $segment)
                    @php
                        $value = (int) $row[$segment['key']];
                        $segmentHeight = $total > 0 ? max(4, (int) round(($value / $total) * $height)) : 0;
                    @endphp
                    @if ($value > 0)
                        <div class="{{ $segment['class'] }}" style="height: {{ $segmentHeight }}px"></div>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@include('admin.analytics._date-axis', ['series' => $series])
