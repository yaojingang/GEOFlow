@extends('site.layout')

@section('content')
    <div class="site-container px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl">
            @include('site.partials.lead-form', ['leadForm' => $leadForm])
        </div>
    </div>
@endsection
