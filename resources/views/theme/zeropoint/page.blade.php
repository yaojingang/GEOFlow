@extends('theme.zeropoint.layout')

@section('content')
<article class="page-article {{ ($page['area'] ?? '') === 'health' ? 'health-article' : '' }}">
    <header class="page-hero">
        <div class="shell hero-shell reveal">
            <nav class="breadcrumb" aria-label="面包屑"><a href="{{ route('site.home') }}">首页</a><span>/</span><span>{{ $page['area'] === 'health' ? '健康知识' : '公开信息' }}</span></nav>
            <p class="eyebrow">{{ $page['eyebrow'] ?? '' }}</p>
            <h1>{{ $page['title'] }}</h1>
            @if(!empty($page['summary']))<p class="page-summary">{{ $page['summary'] }}</p>@endif
            <div class="review-line"><span>版本 {{ $snapshot->version }}</span><span>公开于 {{ $snapshot->published_at?->format('Y-m-d') }}</span><span>可提交纠错</span></div>
        </div>
    </header>
    <div class="shell article-grid">
        <aside class="article-aside"><span>{{ $page['area'] === 'health' ? '健康教育' : '公开核验' }}</span><p>{{ $page['area'] === 'health' ? '不替代面诊与个体医疗判断。' : '内容来自当前活动发布快照。' }}</p></aside>
        <div class="article-body reveal">{!! $bodyHtml !!}</div>
    </div>
    @if(($page['area'] ?? '') !== 'health' && !empty($page['cta_label']) && !empty($page['cta_url']))
        <div class="shell narrow page-cta reveal"><p>需要进一步了解？</p><a class="button button-dark" href="{{ $page['cta_url'] }}">{{ $page['cta_label'] }}</a></div>
    @endif
</article>
@endsection
