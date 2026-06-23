@extends('theme.apihot-recommend-20260623.layout')

@section('content')
    <div class="ne-shell ne-layout">
        <section class="ne-feed">
            <div class="ne-page-head ne-category-head">
                <div class="ne-page-kicker">{{ $siteTitle }} · Archive</div>
                <h1 class="ne-page-title">{{ __('site.archive_title') }}</h1>
                <p class="ne-page-desc">
                    按发布时间浏览 API 推荐、工具评测和自动化工作流内容，快速回到过往更新。
                </p>
            </div>

            <section class="ne-feed-card">
                <div class="ne-section-title">
                    <span class="ne-title-row">内容时间线</span>
                </div>

                @if(count($archives) === 0)
                    <div class="ne-archive-empty">
                        {{ __('site.archive_empty') }}
                    </div>
                @else
                    <div class="ne-archive-grid">
                        @foreach($archives as $row)
                            @php
                                $period = $row['year'].'-'.$row['month'];
                            @endphp
                            <a href="{{ route('site.archive.month', ['year' => $row['year'], 'month' => $row['month']]) }}" class="ne-archive-month">
                                <span class="ne-archive-period">{{ $period }}</span>
                                <span class="ne-archive-count">{{ $row['count'] }} 篇内容</span>
                                <span class="ne-card-action">
                                    查看列表
                                    <i data-lucide="arrow-right" class="w-4 h-4"></i>
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>
        </section>

        @include('theme.apihot-recommend-20260623.partials.sidebar')
    </div>
@endsection
