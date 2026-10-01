@extends($topicLayout ?? 'site.layout')
@section($topicContentSection ?? 'content')
<div class="geo-topics topic-index" data-topic-design="20261001">
    <nav class="topic-breadcrumb" aria-label="当前位置"><a href="{{ $siteUrls->home() }}">首页</a><span>/</span><span>{{ $topicChannelName ?? '专题' }}</span></nav>
    <header class="topic-list-heading"><p class="topic-kicker"><span class="topic-status-dot" aria-hidden="true"></span>专题阅读</p><h1>{{ $topicChannelName ?? '专题' }}</h1><p>围绕一个主题，读懂关键要点，再深入值得阅读的文章。</p></header>
    <form class="topic-search" method="get" action="{{ $siteUrls->topics() }}"><label class="sr-only" for="topic-search">搜索专题</label><input id="topic-search" name="search" value="{{ $search }}" placeholder="搜索专题名称或导读"><button type="submit">搜索</button>@if($search || $tag)<a href="{{ $siteUrls->topics() }}">查看全部</a>@endif</form>
    @if($topicTags->isNotEmpty())
        @php($visibleTopicTags=$topicTags->take(8)->when($tag,fn($tags)=>$tags->push($tag))->unique())
        <nav class="topic-tag-filter" aria-label="专题标签"><div class="topic-tags"><a href="{{ $siteUrls->topics() }}" @if(!$tag) aria-current="page" @endif>全部专题</a>@foreach($visibleTopicTags as $topicTag)<a href="{{ $siteUrls->topics(['tag'=>$topicTag]) }}" @if($tag===$topicTag) aria-current="page" @endif>{{ $topicTag }}</a>@endforeach</div>
            @if($topicTags->diff($visibleTopicTags)->isNotEmpty())<details class="topic-tags-more"><summary>更多标签</summary><div class="topic-tags">@foreach($topicTags->diff($visibleTopicTags) as $topicTag)<a href="{{ $siteUrls->topics(['tag'=>$topicTag]) }}">{{ $topicTag }}</a>@endforeach</div></details>@endif
        </nav>
    @endif
    <div class="topic-section-heading topic-results-heading"><h2>{{ $search || $tag ? '筛选结果' : '全部专题' }}</h2><span>{{ $topics->total() }} 个专题</span></div>
    <div class="topic-collection">
        @forelse($topics as $item)
        <article class="topic-collection-item"><p class="topic-kicker">{{ $item['article_count'] }} 篇文章 · {{ $item['template_key']==='guide'?'阅读指南':($item['template_key']==='roundup'?'资讯盘点':'主题聚合') }}</p><h2><a href="{{ $siteUrls->topic($item) }}">{{ $item['title'] }}</a></h2>@if($item['summary']['one_sentence'] || $item['intro'])<p>{{ \Illuminate\Support\Str::limit($item['summary']['one_sentence'] ?: $item['intro'],160) }}</p>@endif<div class="topic-item-footer"><span>@if($item['modified_at'])整理更新 <time datetime="{{ $item['modified_at'] }}">{{ \Carbon\CarbonImmutable::parse($item['modified_at'])->setTimezone($item['timezone'] ?? 'Asia/Shanghai')->format('Y.m.d') }}</time>@endif</span><a href="{{ $siteUrls->topic($item) }}">阅读专题 <span aria-hidden="true">↗</span></a></div></article>
        @empty
        <div class="topic-empty"><h2>{{ $search || $tag ? '暂时没有匹配的专题' : '专题正在整理' }}</h2><p>{{ $search || $tag ? '试试其他关键词，或查看全部专题。' : '你可以先浏览已公开的文章。' }}</p><a href="{{ $search || $tag ? $siteUrls->topics() : $siteUrls->home() }}">{{ $search || $tag ? '查看全部专题' : '浏览文章' }}</a></div>
        @endforelse
    </div>
    @if($topics->hasPages())<div class="topic-pagination">{{ $topics->links() }}</div>@endif
</div>
@endsection
