@extends('theme.zeropoint.layout')

@section('content')
<section class="index-hero"><div class="shell hero-shell reveal"><p class="eyebrow">HEALTH KNOWLEDGE</p><h1>把复杂问题，讲到边界清楚。</h1><p>这里提供中性、可追溯、有审阅日期的健康知识。页面不设置预约或具体服务推介。</p></div></section>
<section class="shell section-pad">
    <div class="knowledge-list knowledge-index">
        @forelse($pages as $item)
            <a class="knowledge-item reveal" href="{{ route('site.zeropoint.health.show', ['slug' => data_get($item->payload, 'slug')]) }}">
                <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                <div><h2>{{ data_get($item->payload, 'title') }}</h2><p>{{ data_get($item->payload, 'summary') }}</p></div>
                <b>{{ $item->published_at?->format('Y-m-d') }}</b>
            </a>
        @empty
            <p class="empty-state">暂无完成医学与合规审核的健康知识。</p>
        @endforelse
    </div>
</section>
@endsection
