@extends('theme.apple_support_clone.layout')

@section('theme_content')
    <div class="as-archive-page">
        <section class="as-compact-hero" aria-labelledby="archiveMonthTitle">
            <div class="as-container as-narrow">
                <nav class="as-breadcrumb" aria-label="breadcrumb">
                    <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('site.archive') }}">{{ __('site.archive_title') }}</a>
                    <span aria-hidden="true">/</span>
                    <span>{{ $periodLabel }}</span>
                </nav>
                <h1 id="archiveMonthTitle">{{ __('site.archive_month_title', ['period' => $periodLabel]) }}</h1>
            </div>
        </section>

        <section class="as-support-list-section">
            <div class="as-container as-narrow">
                @if($articles->isEmpty())
                    <div class="as-empty-state">
                        <p>{{ __('site.archive_empty') }}</p>
                    </div>
                @else
                    <div class="as-support-link-list">
                        @foreach($articles as $article)
                            @include('theme.apple_support_clone.partials.article-card', [
                                'article' => $article,
                                'showFeaturedBadge' => false,
                                'variant' => 'support-row',
                            ])
                        @endforeach
                    </div>
                    @if($articles->hasPages())
                        <div class="as-pagination">
                            {{ $articles->onEachSide(1)->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </section>
    </div>
@endsection
