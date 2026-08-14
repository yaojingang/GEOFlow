@extends('theme.zeropoint.layout')

@section('content')
<section class="index-hero"><div class="shell hero-shell reveal"><p class="eyebrow">SITE SEARCH</p><h1>搜索已审核的公开内容。</h1>
    <form class="search-form" method="get" action="{{ route('site.zeropoint.search') }}"><label class="sr-only" for="site-search">搜索关键词</label><input id="site-search" name="q" value="{{ $query }}" maxlength="80" placeholder="例如：资质、首次到店、生活美容"><button type="submit">搜索</button></form>
</div></section>
<section class="shell section-pad">
    @if($query !== '')<p class="result-count">“{{ $query }}”找到 {{ $results->count() }} 个公开结果</p>@endif
    <div class="knowledge-list knowledge-index">
        @foreach($results as $item)
            @php($isHealth = $item->page?->area === 'health')
            <a class="knowledge-item" href="{{ \App\Support\ZeroPoint\PublicPageUrl::for((string) data_get($item->payload, 'slug'), (string) $item->page?->area) }}">
                <span>{{ $isHealth ? '知' : '证' }}</span><div><h2>{{ data_get($item->payload, 'title') }}</h2><p>{{ data_get($item->payload, 'summary') }}</p></div><b>查看</b>
            </a>
        @endforeach
    </div>
</section>
@endsection
