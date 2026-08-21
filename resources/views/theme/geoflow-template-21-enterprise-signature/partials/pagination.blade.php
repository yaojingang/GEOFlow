@if($paginator->hasPages())
    <nav class="ent-pagination__nav" role="navigation" aria-label="分页导航">
        @if($paginator->onFirstPage())
            <span class="ent-pagination__control is-disabled" aria-disabled="true">上一页</span>
        @else
            <a class="ent-pagination__control" href="{{ $paginator->previousPageUrl() }}" rel="prev">上一页</a>
        @endif

        <span class="ent-pagination__pages" aria-label="页码">
            @foreach($elements as $element)
                @if(is_string($element))
                    <span class="ent-pagination__ellipsis" aria-hidden="true">{{ $element }}</span>
                @endif

                @if(is_array($element))
                    @foreach($element as $page => $url)
                        @if($page === $paginator->currentPage())
                            <span class="ent-pagination__page is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="ent-pagination__page" href="{{ $url }}" aria-label="前往第 {{ $page }} 页">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </span>

        @if($paginator->hasMorePages())
            <a class="ent-pagination__control" href="{{ $paginator->nextPageUrl() }}" rel="next">下一页</a>
        @else
            <span class="ent-pagination__control is-disabled" aria-disabled="true">下一页</span>
        @endif

        <span class="ent-pagination__summary">
            显示第 <strong>{{ $paginator->firstItem() }}</strong> 到 <strong>{{ $paginator->lastItem() }}</strong> 条，共 <strong>{{ $paginator->total() }}</strong> 条结果
        </span>
    </nav>
@endif
