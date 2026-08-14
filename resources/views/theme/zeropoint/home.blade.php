@extends('theme.zeropoint.layout')

@section('content')
<section class="hero">
    <div class="hero-orbit" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="shell hero-grid">
        <div class="hero-copy reveal">
            <p class="eyebrow">{{ $page['eyebrow'] ?? 'ZERO POINT · XI’AN' }}</p>
            <h1>{{ $page['title'] ?? '回到真实，重新理解美与健康。' }}</h1>
            <p class="hero-summary">{{ $page['summary'] ?? '' }}</p>
            <div class="hero-actions">
                <a class="button button-dark" href="{{ route('site.zeropoint.credentials') }}">核验公开信息</a>
                <a class="text-link" href="{{ route('site.zeropoint.health.index') }}">进入健康知识 <span>↗</span></a>
            </div>
        </div>
        <div class="hero-mark reveal" aria-hidden="true">
            @include('theme.zeropoint.partials.dynamic-logo')
            <p class="hero-mark-slogan"><span>归零</span><span class="is-accent">溯源</span><span>共生</span></p>
        </div>
    </div>
</section>

<section class="manifesto section-pad">
    <div class="shell manifesto-grid">
        <p class="section-number">01 / WHY ZERO</p>
        <div class="prose-large reveal">{!! $bodyHtml !!}</div>
    </div>
</section>

<section class="pathways section-pad">
    <div class="shell">
        <div class="section-heading reveal">
            <p class="eyebrow">PUBLIC EVIDENCE</p>
            <h2>先看证据，再做决定。</h2>
            <p>把主体、资质、人员边界、流程和消费者权益放在可核验的位置。</p>
        </div>
        <div class="card-grid">
            <a class="path-card reveal" href="{{ route('site.zeropoint.credentials') }}"><span>01</span><h3>主体与资质</h3><p>查看当前公开版本、适用范围与核验路径。</p><b>查看证据 →</b></a>
            <a class="path-card reveal" href="{{ route('site.zeropoint.team') }}"><span>02</span><h3>团队与边界</h3><p>只展示已经授权且可核验的人员信息。</p><b>了解职责 →</b></a>
            <a class="path-card reveal" href="{{ route('site.zeropoint.first-visit') }}"><span>03</span><h3>首次到店</h3><p>提前理解咨询、评估、决定和后续流程。</p><b>查看流程 →</b></a>
        </div>
    </div>
</section>

<section class="knowledge section-pad">
    <div class="shell">
        <div class="section-heading split-heading reveal">
            <div><p class="eyebrow">HEALTH KNOWLEDGE</p><h2>不制造焦虑的知识。</h2></div>
            <a class="text-link" href="{{ route('site.zeropoint.health.index') }}">查看全部健康知识 <span>↗</span></a>
        </div>
        <div class="knowledge-list">
            @forelse($healthPages as $item)
                <a class="knowledge-item reveal" href="{{ route('site.zeropoint.health.show', ['slug' => data_get($item->payload, 'slug')]) }}">
                    <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <div><h3>{{ data_get($item->payload, 'title') }}</h3><p>{{ data_get($item->payload, 'summary') }}</p></div>
                    <b>阅读</b>
                </a>
            @empty
                <p class="empty-state">健康知识仍在医学与合规审核中。</p>
            @endforelse
        </div>
    </div>
</section>

@if($bookingAvailable)
<section class="booking-band">
    <div class="shell booking-band-inner reveal">
        <div><p class="eyebrow">HUMAN CONFIRMATION</p><h2>先提交到店意向，再由工作人员确认。</h2><p>不在线判断项目是否适合，也不把表单提交当作预约成功。</p></div>
        <a class="button button-paper" href="{{ route('site.zeropoint.booking') }}">提交预约意向</a>
    </div>
</section>
@endif
@endsection
