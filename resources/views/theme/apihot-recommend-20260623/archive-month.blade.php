@extends('theme.apihot-recommend-20260623.layout')

@section('content')
    <div class="ne-shell ne-layout">
        <section class="ne-feed">
            <div class="ne-page-head ne-category-head">
                <div class="ne-page-kicker">{{ $siteTitle }} · {{ __('site.archive_title') }}</div>
                <h1 class="ne-page-title">{{ __('site.archive_month_title', ['period' => $periodLabel]) }}</h1>
                <p class="ne-page-desc">
                    汇总 {{ $periodLabel }} 发布的 API 推荐、案例解读和效率工具文章。
                </p>
                <div class="ne-category-tabs" aria-label="{{ __('site.archive_title') }}">
                    <a href="{{ route('site.archive') }}" class="is-active">{{ __('site.archive_title') }}</a>
                    <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                </div>
            </div>

            <section class="ne-feed-card">
                <div class="ne-section-title">
                    <span class="ne-title-row">{{ $periodLabel }}</span>
                </div>

                @forelse($articles as $article)
                    @include('theme.apihot-recommend-20260623.partials.article-card', ['article' => $article])
                @empty
                    <div class="ne-archive-empty">
                        {{ __('site.archive_empty') }}
                    </div>
                @endforelse
            </section>

            @if($articles->hasPages())
                <div class="mt-3">
                    {{ $articles->onEachSide(1)->links() }}
                </div>
            @endif
        </section>

        @include('theme.apihot-recommend-20260623.partials.sidebar')
    </div>
@endsection
