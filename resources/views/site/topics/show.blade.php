@extends($topicLayout ?? 'site.layout')
@section($topicContentSection ?? 'content')
<article class="geo-topics topic-detail topic-layout-{{ $topic['template_key'] }}" data-topic-design="20261001">
    @php($topicGroups=str_starts_with($topicTemplateView ?? 'site.topics.templates.default','site.topics.templates.') ? collect($topicArticles)->filter(fn($source)=>$source['group']!=='')->unique('group') : collect())
    @php($hasTopicSummary=$topicSummary['one_sentence'] || $topicSummary['facts'] || $topicSummary['scope'] || $topicSummary['reading_advice'])
    <nav class="topic-breadcrumb" aria-label="当前位置"><a href="{{ $siteUrls->home() }}">首页</a><span>/</span><a href="{{ $siteUrls->topics() }}">{{ $topicChannelName ?? '专题' }}</a><span>/</span><span>{{ $topic['base_title'] }}</span></nav>
    <header class="topic-hero">
        <div class="topic-hero-copy">
            <p class="topic-kicker"><span class="topic-status-dot" aria-hidden="true"></span>{{ $topic['template_key']==='guide'?'阅读指南':($topic['template_key']==='roundup'?'资讯盘点':'主题聚合') }} · 专题阅读</p>
            <h1>{{ $topic['title'] }}</h1>
            @if($topic['intro'])<p class="topic-intro">{{ $topic['intro'] }}</p>@endif
            @if($topic['tags'])<nav class="topic-tags topic-hero-tags" aria-label="专题标签">@foreach(array_slice($topic['tags'],0,4) as $topicTag)<a href="{{ $siteUrls->topics(['tag'=>$topicTag]) }}">{{ $topicTag }}</a>@endforeach</nav>@endif
            <div class="topic-hero-meta">
                <span>{{ $topic['article_count'] }} 篇来源文章</span>
                @if($topic['modified_at'])<span>整理更新 <time datetime="{{ $topic['modified_at'] }}">{{ \Carbon\CarbonImmutable::parse($topic['modified_at'])->setTimezone($topic['timezone'] ?? 'Asia/Shanghai')->format('Y.m.d') }}</time></span>@endif
            </div>
            <div class="topic-hero-actions"><a class="topic-primary-link" href="#topic-reading-content">开始阅读 <span aria-hidden="true">↓</span></a><button class="topic-copy-button" type="button" data-topic-copy data-url="{{ $topic['url'] }}">复制链接</button><span data-topic-copy-status role="status" aria-live="polite"></span></div>
        </div>
        <nav class="topic-route" aria-label="专题阅读路线">
            <p class="topic-route-label">从这里开始</p>
            <h2>一条清晰的阅读路线</h2>
            @if($topicGroups->isNotEmpty())<ol>@foreach($topicGroups as $groupSource)<li><a href="#topic-group-{{ $groupSource['article_id'] }}"><span class="topic-route-number" aria-hidden="true">{{ str_pad((string)$loop->iteration,2,'0',STR_PAD_LEFT) }}</span><span>{{ $groupSource['group'] }}</span><span class="topic-route-arrow" aria-hidden="true">↗</span></a></li>@endforeach</ol>
            @else<p class="topic-route-description">汇集 {{ $topic['article_count'] }} 篇文章，按专题顺序逐篇阅读。</p><a class="topic-route-all" href="#topic-reading-content">查看来源文章 <span aria-hidden="true">→</span></a>@endif
            <p class="topic-route-note">先看摘要，再按需深入原文。</p>
        </nav>
    </header>
    @if($topic['freshness_message']??null)<p class="topic-notice" data-freshness-status="{{ $topic['freshness_status'] }}">{{ $topic['freshness_message'] }} @if(in_array($topic['freshness_status'],['expired','scheduled'],true)&&$topic['freshness_coverage'])<span>原覆盖范围：{{ $topic['freshness_coverage'] }}</span>@endif</p>@endif
    @if($topic['warnings'])<p class="topic-notice">部分整理信息已隐藏，请直接阅读来源文章。</p>@endif
    <div class="topic-detail-grid"><div class="topic-detail-main">
        @if($hasTopicSummary)
        <section class="topic-summary" id="topic-summary" aria-labelledby="topic-summary-title">
            <div class="topic-section-heading"><h2 id="topic-summary-title">专题摘要</h2><span>结构化摘要</span></div>
            @if($topicSummary['one_sentence'])<p class="topic-conclusion">{{ $topicSummary['one_sentence'] }}</p>@endif
            @if($topicSummary['facts'])
<details class="topic-key-points"><summary>关键要点与引用 <span>{{ count($topicSummary['facts']) }} 条要点</span></summary><ul class="topic-facts">@foreach($topicSummary['facts'] as $fact)<li><span>{{ $fact['text'] }}</span><span class="topic-citations">@foreach($fact['article_ids'] as $sourceId)@php($source=collect($topicArticles)->firstWhere('article_id',$sourceId))@if($source)<a href="{{ $source['url'] }}" aria-label="依据：{{ $source['title'] }}">[{{ collect($topicArticles)->search(fn($a)=>(int)$a['article_id']===(int)$sourceId)+1 }}]</a>@endif @endforeach</span>@if(!empty($fact['evidence']))<details class="topic-fact-evidence"><summary>查看原文片段</summary>@foreach($fact['evidence'] as $fragment)<blockquote tabindex="0" aria-label="原文引用片段">{{ strip_tags($fragment['text']) }}</blockquote>@endforeach</details>@endif</li>@endforeach</ul></details>
            @endif
            @if($topicSummary['scope'] || $topicSummary['reading_advice'])<details class="topic-reading-advice"><summary>阅读建议与适用范围</summary>
                @if($topicSummary['reading_advice'])<div class="topic-summary-row"><h3>阅读建议</h3><p>{{ $topicSummary['reading_advice'] }}</p></div>@endif
                @if($topicSummary['scope'])<div class="topic-summary-row"><h3>适用范围与限制</h3><p>{{ $topicSummary['scope'] }}</p></div>@endif
            </details>@endif
        </section>
        @endif
        <div id="topic-reading-content">@include($topicTemplateView ?? 'site.topics.templates.default')</div>
        @if($topic['faq'])<section class="topic-faq" id="topic-faq"><div class="topic-section-heading"><h2>常见问题</h2><span>继续了解</span></div>@foreach($topic['faq'] as $faq)<details><summary>{{ $faq['question'] }}</summary><p>{{ $faq['answer'] }}</p><p>来源：@foreach($faq['article_ids'] as $id)@php($source=collect($topicArticles)->firstWhere('article_id',$id))@if($source)<a href="{{ $source['url'] }}">{{ $source['title'] }}</a> @endif @endforeach</p></details>@endforeach</section>@endif
    </div><aside class="topic-sidebar" aria-label="专题辅助信息">
        <nav class="topic-page-index" aria-label="本页目录"><p class="topic-sidebar-label">本页目录</p>
            @if($hasTopicSummary)<a href="#topic-summary">专题摘要 <span aria-hidden="true">↗</span></a>@endif
            <a href="#topic-reading-content">来源文章 <span>{{ $topic['article_count'] }}</span></a>
            @foreach($topicGroups as $groupSource)<a class="topic-index-group" href="#topic-group-{{ $groupSource['article_id'] }}">{{ $groupSource['group'] }}</a>@endforeach
            @if($topic['faq'])<a href="#topic-faq">常见问题 <span>{{ count($topic['faq']) }}</span></a>@endif
        </nav>
        <details class="topic-auxiliary" data-topic-auxiliary open><summary>查看专题信息与来源</summary><div class="topic-auxiliary-content">
        @if($topicScore)<section class="topic-score"><p class="topic-kicker">{{ $topicScore['type']==='ai_assisted'?'AI ASSISTED SCORE':'EDITOR SCORE' }}</p><p class="topic-score-total"><strong>{{ number_format($topicScore['total'],1) }}</strong><span>/ 10</span></p><div class="topic-stars" role="img" aria-label="{{ number_format($topicScore['total']/2,2) }} / 5 星"><span aria-hidden="true">★★★★★</span><span aria-hidden="true" style="width:{{ max(0,min(100,$topicScore['total']*10)) }}%">★★★★★</span></div>@foreach($topicScore['dimensions'] as $dimension)<div class="topic-score-dimension"><span>{{ $dimension['name'] }}</span><div aria-hidden="true"><i style="width:{{ $dimension['score']*10 }}%"></i></div><strong>{{ number_format($dimension['score'],1) }}</strong></div>@endforeach<p>{{ $topicScore['source'] }}</p>@if(!empty($topicScore['rated_at']))<p>评价日期 {{ \Carbon\CarbonImmutable::parse($topicScore['rated_at'])->setTimezone($topic['timezone'] ?? 'Asia/Shanghai')->format('Y.m.d') }}</p>@endif
            <details class="topic-score-method"><summary>评分依据与计算</summary>
                <p>{{ $topicScore['calculation'] }}</p>
                <ul>@foreach($topicScore['dimensions'] as $dimension)<li>{{ $dimension['name'] }} · 权重 {{ number_format(($topicScore['normalized_weights'][$loop->index]??0)*100,1) }}%</li>@endforeach</ul>
                @foreach($topicScore['evidence'] as $evidence)
                    <p>{{ $evidence['text'] }}</p>
                    <p>@foreach($evidence['article_ids'] as $id)
                        @php($evidenceSource=collect($topicArticles)->firstWhere('article_id',$id))
                        @if($evidenceSource)<a href="{{ $evidenceSource['url'] }}">{{ $evidenceSource['title'] }}</a> @endif
                    @endforeach</p>
                @endforeach
                @if(!empty($topicScore['valid_until']))<p>评分有效期至 {{ $topicScore['valid_until'] }}</p>@endif
            </details>
        </section>@endif
        <details class="topic-info" data-topic-info open>
            <summary>专题信息</summary>
            <dl>
                <div><dt>首次公开</dt><dd>
                    @if($topic['first_published_at'])
                        <time datetime="{{ $topic['first_published_at'] }}">{{ \Carbon\CarbonImmutable::parse($topic['first_published_at'])->setTimezone($topic['timezone'] ?? 'Asia/Shanghai')->format('Y.m.d') }}</time>
                    @else
                        未公开
                    @endif
                </dd></div>
                <div><dt>整理更新</dt><dd>{{ $topic['modified_at'] ? \Carbon\CarbonImmutable::parse($topic['modified_at'])->setTimezone($topic['timezone'] ?? 'Asia/Shanghai')->format('Y.m.d') : '暂无更新日期' }}</dd></div>
                @if(($topic['freshness_status']??'unknown')!=='unknown' && !empty($topic['freshness_snapshot_json']['last_verified_at']))
                    <div><dt>实际内容复核</dt><dd><time datetime="{{ $topic['freshness_snapshot_json']['last_verified_at'] }}">{{ \Carbon\CarbonImmutable::parse($topic['freshness_snapshot_json']['last_verified_at'])->setTimezone($topic['freshness_snapshot_json']['timezone'])->setTimezone($topic['timezone'] ?? 'Asia/Shanghai')->format('Y.m.d') }}</time></dd></div>
                @endif
                @if(!empty($topic['freshness_snapshot_json']['public_updates']))
                    <div><dt>公开更新记录</dt><dd>{{ $topic['freshness_snapshot_json']['public_updates'] }}</dd></div>
                @endif
                @foreach($topic['basic_info'] as $info)
                    <div><dt>{{ $info['label'] }}</dt><dd>{{ $info['value'] }}</dd></div>
                @endforeach
            </dl>
        </details>
        @if($topic['tags'])<section class="topic-info"><h2>相关标签</h2><div class="topic-tags">@foreach(array_slice($topic['tags'],0,8) as $topicTag)<a href="{{ $siteUrls->topics(['tag'=>$topicTag]) }}">{{ $topicTag }}</a>@endforeach</div></section>@endif
        <section class="topic-info"><h2>来源与审核</h2>@if(!empty($topic['review_info']))<p>审核方式：{{ $topic['review_info']['label'] }} · <time datetime="{{ $topic['review_info']['reviewed_at'] }}">{{ \Carbon\CarbonImmutable::parse($topic['review_info']['reviewed_at'])->setTimezone($topic['timezone']??'Asia/Shanghai')->format('Y.m.d') }}</time></p>@endif<p>本专题整理本站当前可公开阅读的文章。事实旁的引用链接可以查看依据，文章发布与专题整理日期分别记录。</p></section>
    </div></details></aside></div>
    <div class="topic-back-link"><a href="{{ $siteUrls->topics() }}">← 返回全部专题</a></div>
</article>
@endsection
