@extends('admin.layouts.app')

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <div>
        <a href="{{ route('admin.manual-publications.index') }}" class="text-sm font-medium text-blue-700 hover:text-blue-900">{{ __('admin.browser_operations.connect.back') }}</a>
        <h1 class="mt-3 text-2xl font-bold text-gray-950">{{ __('admin.browser_operations.connect.heading') }}</h1>
        <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.browser_operations.connect.description') }}</p>
    </div>

    @if(!$authorization)
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <p class="text-sm text-gray-700">{{ __('admin.browser_operations.connect.missing') }}</p>
        </div>
    @else
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('admin.browser_operations.connect.code') }}</div>
            <div class="mt-2 font-mono text-3xl font-bold tracking-widest text-gray-950">{{ $authorization['user_code'] }}</div>
            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">{{ __('admin.browser_operations.connect.client') }}</dt><dd class="mt-1 font-semibold text-gray-900">{{ $authorization['client_name'] }}</dd></div>
                <div><dt class="text-gray-500">{{ __('admin.browser_operations.connect.status') }}</dt><dd class="mt-1 font-semibold text-gray-900">{{ $authorization['status'] }}</dd></div>
            </dl>
            @if(($authorization['status'] ?? '') === 'pending')
                <form method="POST" action="{{ route('admin.manual-publications.browser-connect.decision') }}" class="mt-6 flex flex-wrap gap-3">
                    @csrf
                    <input type="hidden" name="user_code" value="{{ $authorization['user_code'] }}">
                    <button type="submit" name="decision" value="approve" class="min-h-10 rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white active:scale-[.96]">{{ __('admin.browser_operations.connect.approve') }}</button>
                    <button type="submit" name="decision" value="deny" class="min-h-10 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-red-700 ring-1 ring-red-200 active:scale-[.96]">{{ __('admin.browser_operations.connect.deny') }}</button>
                </form>
            @endif
        </div>
    @endif
</div>
@endsection
