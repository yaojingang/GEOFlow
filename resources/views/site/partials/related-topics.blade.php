@if(!empty($relatedTopics) && count($relatedTopics))
<section class="geo-topics topic-article-links" aria-label="所属专题"><h2>继续阅读相关专题</h2>@foreach($relatedTopics as $item)<p><a href="{{ $siteUrls->topic($item) }}">{{ $item['title'] }} ↗</a><span> · {{ $item['article_count'] }} 篇文章</span></p>@endforeach</section>
@endif
