@php
    $toneMap = [
        'blue' => 'bg-blue-600',
        'amber' => 'bg-amber-500',
        'purple' => 'bg-purple-600',
        'green' => 'bg-emerald-600',
        'slate' => 'bg-slate-700',
    ];
@endphp

<div class="space-y-4">
    @foreach ($funnel['stages'] as $stage)
        @php
            $percent = max(3, min(100, ((int) $stage['count'] / max(1, (int) $funnel['max'])) * 100));
        @endphp
        <div>
            <div class="mb-1 flex items-center justify-between gap-4 text-sm">
                <span class="font-medium text-gray-700">{{ $stage['label'] }}</span>
                <span class="whitespace-nowrap font-semibold text-gray-900">{{ number_format((int) $stage['count']) }}</span>
            </div>
            <div class="h-3 overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full {{ $toneMap[$stage['tone']] ?? 'bg-blue-600' }}" style="width: {{ $percent }}%"></div>
            </div>
        </div>
    @endforeach
</div>
