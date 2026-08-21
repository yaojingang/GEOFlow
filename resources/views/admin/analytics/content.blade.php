@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.analytics._page-header', [
            'title' => __('admin.analytics.pages.content.title'),
            'subtitle' => __('admin.analytics.pages.content.subtitle'),
        ])
        @include('admin.analytics._filters', ['analyticsFilterRoute' => route('admin.analytics.content')])
        @include('admin.analytics._single-site-section')
    </div>
@endsection
