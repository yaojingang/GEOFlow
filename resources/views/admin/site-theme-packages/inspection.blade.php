@extends('admin.site-theme-packages.base')
@section('intro', __('admin.theme_packages.inspect_intro'))
@section('package-content')
    <ol class="flex flex-wrap gap-x-8 gap-y-3 text-sm" aria-label="{{ __('admin.theme_packages.steps') }}"><li class="text-gray-500">1 · {{ __('admin.theme_packages.step_upload') }}</li><li aria-current="step" class="font-semibold text-blue-700">2 · {{ __('admin.theme_packages.step_inspect') }}</li><li class="text-gray-500">3 · {{ __('admin.theme_packages.step_install') }}</li></ol>
    @include('admin.site-theme-packages.summary')
    @if(!empty($inspection['risks']))<ul class="list-disc space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-5 pl-10 text-sm text-amber-900">@foreach($inspection['risks'] as $risk)<li>{{ $risk }}</li>@endforeach</ul>@endif
    @if(in_array($inspection['conflict']['code'] ?? null, ['builtin_conflict', 'theme_conflict'], true))
        <div role="alert" class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900"><p>{{ $inspection['conflict']['message'] }}</p><a class="inline-flex min-h-11 items-center font-semibold underline" href="{{ route('admin.site-settings.theme-packages.imports.create') }}">{{ __('admin.theme_packages.upload_again') }}</a></div>
    @else
        <form method="POST" action="{{ route('admin.site-settings.theme-packages.imports.install', ['token' => $inspection['token']]) }}" class="space-y-5 rounded-lg border border-gray-200 bg-white p-5 sm:p-6">
            @csrf
            @if(($inspection['conflict']['code'] ?? null) === 'already_installed')<p class="text-sm text-gray-600">{{ __('admin.theme_packages.conflict.identical') }}</p>@endif
            <label class="flex cursor-pointer items-start gap-3 text-sm leading-6 text-gray-800"><input type="checkbox" name="trusted_source" value="1" required class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600"><span>{{ __('admin.theme_packages.trust_source') }}</span></label>
            <p class="text-sm leading-6 text-gray-500">{{ __('admin.theme_packages.install_boundary') }}</p>
            <button class="inline-flex min-h-11 items-center gap-2 rounded-md bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700 active:scale-[.98]" type="submit">{{ __('admin.theme_packages.install_button') }}<i data-lucide="arrow-right" class="h-4 w-4" aria-hidden="true"></i></button>
        </form>
    @endif
@endsection
