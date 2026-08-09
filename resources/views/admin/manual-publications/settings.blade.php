@extends('admin.layouts.app')

@section('content')
<div class="px-4 sm:px-0">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.manual_publications.settings.title') }}</h1>
            <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.manual_publications.settings.subtitle') }}</p>
        </div>
        <a href="{{ route('admin.manual-publications.index') }}" class="inline-flex w-fit items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>{{ __('admin.manual_publications.button.back') }}
        </a>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="space-y-5">
            <form method="POST" action="{{ route('admin.manual-publications.settings.personas.store') }}" class="rounded-xl border border-blue-200 bg-white p-6 shadow-sm">
                @csrf
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.settings.new_persona') }}</h2>
                <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.settings.name') }} *</label><input name="name" required maxlength="120" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.settings.tone') }}</label><input name="tone" maxlength="120" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.settings.domain') }}</label><input name="domain" maxlength="255" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                    <div class="sm:col-span-2"><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.settings.bio') }}</label><textarea name="bio" rows="3" maxlength="5000" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea></div>
                    <div class="sm:col-span-2"><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.settings.disclosure') }}</label><textarea name="disclosure_text" rows="3" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea></div>
                </div>
                <input type="hidden" name="is_active" value="1">
                <button class="mt-5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">{{ __('admin.manual_publications.settings.save_persona') }}</button>
            </form>

            @foreach($personas as $persona)
                <form method="POST" action="{{ route('admin.manual-publications.settings.personas.update', ['personaId' => $persona->id]) }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    @csrf @method('PUT')
                    <div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-gray-900">#{{ $persona->id }} · {{ $persona->name }}</h3><span class="text-xs text-gray-500">{{ __('admin.manual_publications.settings.account_count', ['count' => $persona->accounts_count]) }}</span></div>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <input name="name" value="{{ $persona->name }}" required maxlength="120" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <input name="tone" value="{{ $persona->tone }}" maxlength="120" class="rounded-md border-gray-300 text-sm shadow-sm" placeholder="{{ __('admin.manual_publications.settings.tone') }}">
                        <input name="domain" value="{{ $persona->domain }}" maxlength="255" class="rounded-md border-gray-300 text-sm shadow-sm" placeholder="{{ __('admin.manual_publications.settings.domain') }}">
                        <label class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($persona->is_active) class="rounded border-gray-300 text-blue-600">{{ __('admin.manual_publications.settings.active') }}</label>
                        <textarea name="bio" rows="2" maxlength="5000" class="sm:col-span-2 rounded-md border-gray-300 text-sm shadow-sm" placeholder="{{ __('admin.manual_publications.settings.bio') }}">{{ $persona->bio }}</textarea>
                        <textarea name="disclosure_text" rows="2" maxlength="2000" class="sm:col-span-2 rounded-md border-gray-300 text-sm shadow-sm" placeholder="{{ __('admin.manual_publications.settings.disclosure') }}">{{ $persona->disclosure_text }}</textarea>
                    </div>
                    <button class="mt-4 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('admin.manual_publications.button.save') }}</button>
                </form>
            @endforeach
        </section>

        <section class="space-y-5">
            <form method="POST" action="{{ route('admin.manual-publications.settings.accounts.store') }}" class="rounded-xl border border-purple-200 bg-white p-6 shadow-sm">
                @csrf
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.settings.new_account') }}</h2>
                <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.persona') }} *</label><select name="persona_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"><option value="">{{ __('admin.manual_publications.option.select_persona') }}</option>@foreach($personas as $persona)<option value="{{ $persona->id }}">{{ $persona->name }}</option>@endforeach</select></div>
                    <div><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.platform') }} *</label><select name="platform" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">@foreach($platforms as $platform)<option value="{{ $platform }}">{{ __('admin.manual_publications.platform.'.$platform) }}</option>@endforeach</select></div>
                    <div><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.account') }} *</label><input name="account_name" required maxlength="160" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.custom_platform') }}</label><input name="custom_platform" maxlength="120" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                    <div class="sm:col-span-2"><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.settings.profile_url') }}</label><input type="url" name="profile_url" maxlength="1000" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                    <div class="sm:col-span-2"><label class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.settings.notes') }}</label><textarea name="notes" rows="3" maxlength="5000" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea></div>
                </div>
                <input type="hidden" name="is_active" value="1">
                <button class="mt-5 rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-700">{{ __('admin.manual_publications.settings.save_account') }}</button>
            </form>

            @foreach($accounts as $account)
                <form method="POST" action="{{ route('admin.manual-publications.settings.accounts.update', ['accountId' => $account->id]) }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    @csrf @method('PUT')
                    <h3 class="font-semibold text-gray-900">#{{ $account->id }} · {{ $account->account_name }}</h3>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <select name="persona_id" required class="rounded-md border-gray-300 text-sm shadow-sm">@foreach($personas as $persona)<option value="{{ $persona->id }}" @selected($account->persona_id === $persona->id)>{{ $persona->name }}</option>@endforeach</select>
                        <select name="platform" required class="rounded-md border-gray-300 text-sm shadow-sm">@foreach($platforms as $platform)<option value="{{ $platform }}" @selected($account->platform === $platform)>{{ __('admin.manual_publications.platform.'.$platform) }}</option>@endforeach</select>
                        <input name="account_name" value="{{ $account->account_name }}" required maxlength="160" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <input name="custom_platform" value="{{ $account->custom_platform }}" maxlength="120" class="rounded-md border-gray-300 text-sm shadow-sm" placeholder="{{ __('admin.manual_publications.field.custom_platform') }}">
                        <input type="url" name="profile_url" value="{{ $account->profile_url }}" maxlength="1000" class="sm:col-span-2 rounded-md border-gray-300 text-sm shadow-sm" placeholder="{{ __('admin.manual_publications.settings.profile_url') }}">
                        <textarea name="notes" rows="2" maxlength="5000" class="sm:col-span-2 rounded-md border-gray-300 text-sm shadow-sm" placeholder="{{ __('admin.manual_publications.settings.notes') }}">{{ $account->notes }}</textarea>
                        <label class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($account->is_active) class="rounded border-gray-300 text-blue-600">{{ __('admin.manual_publications.settings.active') }}</label>
                    </div>
                    <button class="mt-4 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('admin.manual_publications.button.save') }}</button>
                </form>
            @endforeach
        </section>
    </div>
</div>
@endsection
