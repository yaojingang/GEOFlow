<section id="topic-sources" class="topic-source-section" aria-labelledby="topic-sources-title" data-topic-template="guide">
    <details class="topic-reading-index" data-topic-reading-index open>
        <summary>阅读目录 · {{ count($topicArticles) }} 篇</summary>
        <nav aria-label="专题阅读目录"><ol>
            @foreach($topicArticles as $source)
                <li><a href="#source-{{ $source['article_id'] }}">{{ $source['title'] }}</a></li>
            @endforeach
        </ol></nav>
    </details>
    <div class="topic-section-heading"><h2 id="topic-sources-title">按顺序阅读</h2><span>{{ count($topicArticles) }} 篇</span></div>
    <div class="topic-reading-steps">
        @php($lastGroup=null)
        @foreach($topicArticles as $source)
            @if($source['group']!=='' && $source['group']!==$lastGroup)
                <h3 class="topic-group-title" id="topic-group-{{ $source['article_id'] }}">{{ $source['group'] }}</h3>
            @endif
            @php($lastGroup=$source['group'])
            @include('site.partials.topic-source',['sourceNumber'=>$loop->iteration])
        @endforeach
    </div>
</section>
