<section id="topic-sources" class="topic-source-section" aria-labelledby="topic-sources-title" data-topic-template="roundup">
    <div class="topic-section-heading"><h2 id="topic-sources-title">资讯与来源</h2><span>{{ count($topicArticles) }} 篇</span></div>
    <div class="topic-roundup-list">
        @php($lastGroup=null)
        @foreach($topicArticles as $source)
            @if($source['group']!=='' && $source['group']!==$lastGroup)
                <h3 class="topic-group-title" id="topic-group-{{ $source['article_id'] }}">{{ $source['group'] }}</h3>
            @endif
            @php($lastGroup=$source['group'])
            <article id="source-{{ $source['article_id'] }}" class="topic-roundup-item">
                <div class="topic-roundup-date">
                    @if($source['published_at'])
                        <time datetime="{{ $source['published_at'] }}"><strong>{{ \Carbon\CarbonImmutable::parse($source['published_at'])->setTimezone($topic['timezone'] ?? 'Asia/Shanghai')->format('m.d') }}</strong><span>{{ \Carbon\CarbonImmutable::parse($source['published_at'])->setTimezone($topic['timezone'] ?? 'Asia/Shanghai')->format('Y') }} 文章发布</span></time>
                    @else
                        <span>来源 {{ $loop->iteration }}</span>
                    @endif
                </div>
                <div><h3><a href="{{ $source['url'] }}">{{ $source['title'] }}</a></h3>
                    @if($source['reason'] || $source['excerpt'])<p>{{ $source['reason'] ?: \Illuminate\Support\Str::limit($source['excerpt'],180) }}</p>@endif
                    <a class="topic-roundup-link" href="{{ $source['url'] }}">阅读全文 ↗</a>
                </div>
            </article>
        @endforeach
    </div>
</section>
