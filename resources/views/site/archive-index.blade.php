@extends('site.layout')

@section('content')
    <div class="site-container px-4 sm:px-6 lg:px-8 py-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-6">{{ __('site.archive_title') }}</h1>

        @if(count($archives) === 0)
            <p class="text-gray-600">{{ __('site.archive_empty') }}</p>
        @else
            <ul class="space-y-3">
                @foreach($archives as $row)
                    <li>
                        <a href="{{ route('site.archive.month', ['year' => $row['year'], 'month' => $row['month']]) }}" class="text-blue-600 hover:text-blue-800">
                            {{ $row['year'] }}-{{ $row['month'] }}
                        </a>
                        <span class="text-gray-500 text-sm ml-2">({{ $row['count'] }})</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
