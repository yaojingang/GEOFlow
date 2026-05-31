@extends('theme.apple_support_clone.layout')

@section('theme_content')
    <div class="as-archive-page">
        <section class="as-compact-hero" aria-labelledby="archiveTitle">
            <div class="as-container as-narrow">
                <nav class="as-breadcrumb" aria-label="breadcrumb">
                    <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                    <span aria-hidden="true">/</span>
                    <span>{{ __('site.archive_title') }}</span>
                </nav>
                <h1 id="archiveTitle">{{ __('site.archive_title') }}</h1>
                <p>{{ $siteDescription }}</p>
            </div>
        </section>

        <section class="as-support-list-section">
            <div class="as-container as-narrow">
                @if(count($archives) === 0)
                    <div class="as-empty-state">
                        <p>{{ __('site.archive_empty') }}</p>
                    </div>
                @else
                    <div class="as-support-link-list">
                        @foreach($archives as $row)
                            <article class="as-support-row">
                                <a href="{{ route('site.archive.month', ['year' => $row['year'], 'month' => $row['month']]) }}">
                                    <span>{{ $row['year'] }}-{{ $row['month'] }}</span>
                                    <small>{{ $row['count'] }}</small>
                                </a>
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </div>
@endsection
