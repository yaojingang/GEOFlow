@extends('site.layout')

@section('content')
    <div class="site-container px-4 sm:px-6 lg:px-8 py-8">
        <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-8">
            <a href="{{ route('site.home') }}" class="hover:text-gray-700">{{ __('front.nav.home') }}</a>
            <span>/</span>
            <a href="{{ route('site.archive') }}" class="hover:text-gray-700">{{ __('site.archive_title') }}</a>
            <span>/</span>
            <span class="text-gray-900">{{ $periodLabel }}</span>
        </nav>

        <h1 class="text-3xl font-bold text-gray-900 mb-6">{{ __('site.archive_month_title', ['period' => $periodLabel]) }}</h1>

        @if($articles->isEmpty())
            <p class="text-gray-600">{{ __('site.archive_empty') }}</p>
        @else
            <div class="space-y-8">
                @foreach($articles as $article)
                    @include('site.partials.article-card', ['article' => $article, 'showFeaturedBadge' => false])
                @endforeach
            </div>
            @if($articles->hasPages())
                <div class="mt-12">
                    {{ $articles->onEachSide(1)->links() }}
                </div>
            @endif
        @endif
    </div>
@endsection
