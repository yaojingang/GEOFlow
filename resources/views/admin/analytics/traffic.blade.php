@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.analytics._page-header', [
            'title' => __('admin.analytics.pages.traffic.title'),
            'subtitle' => __('admin.analytics.pages.traffic.subtitle'),
        ])
        @include('admin.analytics._log-section', ['analyticsLogRoute' => route('admin.analytics.traffic')])
    </div>
@endsection
