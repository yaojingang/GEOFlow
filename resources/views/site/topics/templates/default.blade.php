<section id="topic-sources" class="topic-source-section" aria-labelledby="topic-sources-title" data-topic-template="default">
    <div class="topic-section-heading"><h2 id="topic-sources-title">来源文章</h2><span>{{ count($topicArticles) }} 篇</span></div>
    <div class="topic-source-cards">
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
