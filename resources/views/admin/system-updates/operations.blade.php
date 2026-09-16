@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto flex max-w-4xl flex-col gap-6 px-4 sm:px-0" data-updater-console data-instance-id="{{ $instanceId }}" data-receipt-url="{{ route('admin.system-updates.updater.console') }}">
        <header class="flex flex-col gap-3">
            <a href="{{ route('admin.system-updates.index') }}" class="text-sm font-medium text-blue-700 hover:underline">{{ __('updater.back') }}</a>
            <h1 class="text-3xl font-bold text-gray-900">{{ __('updater.title') }}</h1>
            <p class="text-sm leading-6 text-gray-600">{{ __('updater.introduction') }}</p>
        </header>

        @if($error)
            <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">{{ $error }}</div>
        @endif

        @if($requestId)
            <section class="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="receipt-title">
                <h2 id="receipt-title" class="text-lg font-semibold text-gray-900">{{ __('updater.receipt') }}</h2>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-sm text-gray-500">{{ __('updater.request_id') }}</dt><dd class="mt-1 break-all font-mono text-sm text-gray-900">{{ $requestId }}</dd></div>
                    @if($receipt)
                        <div><dt class="text-sm text-gray-500">{{ __('updater.state') }}</dt><dd class="mt-1 font-semibold text-gray-900">{{ __('updater.state_'.$receipt['state']) }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-sm text-gray-500">{{ __('updater.operation_id') }}</dt><dd class="mt-1 break-all font-mono text-sm text-gray-900">{{ $receipt['operation_id'] }}</dd></div>
                    @endif
                </dl>
                @if(($receipt['state'] ?? null) === 'recovery_required')
                    <p class="text-sm leading-6 text-amber-800">{{ __('updater.background_held') }}</p>
                @elseif(($receipt['state'] ?? null) === 'rolled_back')
                    <p class="text-sm leading-6 text-amber-800">{{ __('updater.rolled_back') }}</p>
                @elseif(!$receipt)
                    <p class="text-sm leading-6 text-gray-600">{{ __('updater.pending_hint') }}</p>
                @endif
                <a href="{{ route('admin.system-updates.updater.console', ['request' => $requestId]) }}" class="inline-flex min-h-10 items-center justify-center self-start rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('updater.refresh') }}</a>
            </section>
        @elseif($capabilities)
            @if(is_array($plan))
                <section class="flex flex-col gap-4 rounded-xl border border-blue-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="plan-title">
                    <h2 id="plan-title" class="text-lg font-semibold text-gray-900">{{ __('updater.review') }}</h2>
                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div><dt class="text-sm text-gray-500">{{ __('updater.action') }}</dt><dd class="mt-1 font-semibold text-gray-900">{{ __('updater.'.$plan['action']) }}</dd></div>
                        <div><dt class="text-sm text-gray-500">{{ __('updater.expires') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ \Illuminate\Support\Carbon::parse($plan['expires_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}</dd></div>
                        <div><dt class="text-sm text-gray-500">{{ __('updater.maintenance') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ __('updater.'.($plan['maintenance_required'] ? 'required' : 'not_required')) }}</dd></div>
                        <div><dt class="text-sm text-gray-500">{{ __('updater.request_id') }}</dt><dd class="mt-1 break-all font-mono text-sm text-gray-900">{{ $plan['client_request_id'] }}</dd></div>
                        @foreach(['source_version', 'target_version', 'recovery_point_id'] as $detail)
                            @if(is_string($plan['details'][$detail] ?? null))
                                <div><dt class="text-sm text-gray-500">{{ __('updater.'.$detail) }}</dt><dd class="mt-1 break-all text-sm text-gray-900">{{ $plan['details'][$detail] }}</dd></div>
                            @endif
                        @endforeach
                        @if(is_array($plan['details']['scope'] ?? null))
                            <div class="sm:col-span-2"><dt class="text-sm text-gray-500">{{ __('updater.scope') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ implode(' · ', array_map(fn ($area) => __('updater.scope_'.$area), array_filter($plan['details']['scope'], 'is_string'))) }}</dd></div>
                        @endif
                    </dl>
                    @if($plan['continuation'] === 'host_only')
                        <p class="text-sm leading-6 text-amber-800">{{ __('updater.host_only') }}</p>
                    @endif
                    <p class="text-sm leading-6 text-gray-600">{{ __('updater.keep_id') }}</p>
                    <a href="{{ route('admin.system-updates.updater.console', ['request' => $plan['client_request_id']]) }}" class="text-sm font-medium text-blue-700 hover:underline">{{ __('updater.lookup') }}</a>
                    <form method="POST" action="{{ route('admin.system-updates.updater.submit') }}" class="flex flex-col gap-4 border-t border-gray-100 pt-4" data-updater-admission data-request-id="{{ $plan['client_request_id'] }}" data-storage-failed="{{ __('updater.storage_failed') }}">
                        @csrf
                        <input type="hidden" name="instance_id" value="{{ $plan['management_instance_id'] }}">
                        @foreach(['action', 'plan_id', 'plan_sha256', 'expected_epoch', 'client_request_id'] as $field)
                            <input type="hidden" name="{{ $field }}" value="{{ $plan[$field] }}">
                        @endforeach
                        @foreach(['allow_maintenance', 'confirm_host_access'] as $flag)
                            <input type="hidden" name="{{ $flag }}" value="0">
                            @if(($flag === 'allow_maintenance' && $plan['maintenance_required']) || ($flag === 'confirm_host_access' && $plan['continuation'] === 'host_only'))
                            <label class="flex items-start gap-3 text-sm leading-6 text-gray-700"><input type="checkbox" name="{{ $flag }}" value="1" class="mt-1 rounded border-gray-300 text-blue-600">{{ __('updater.'.$flag) }}</label>
                            @endif
                        @endforeach
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="flex flex-col gap-2 text-sm font-medium text-gray-700">{{ __('updater.password') }}<input name="password" type="password" autocomplete="current-password" class="w-full min-h-10 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"></label>
                            <label class="flex flex-col gap-2 text-sm font-medium text-gray-700">{{ __('updater.code') }}<input name="authorization_code" type="password" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required class="w-full min-h-10 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"></label>
                        </div>
                        <p data-updater-bootstrap-status role="status" class="text-sm text-amber-800">{{ __('updater.initializing') }}</p>
                        <p data-updater-client-error role="alert" class="hidden text-sm text-red-700"></p>
                        <button type="submit" disabled class="min-h-10 self-start rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ __('updater.submit') }}</button>
                    </form>
                </section>
            @endif
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('updater.new_plan') }}</h2>
                <form method="POST" action="{{ route('admin.system-updates.updater.action-plan') }}" class="mt-4 flex flex-col gap-4" data-updater-plan-form>
                    @csrf
                    <input type="hidden" name="instance_id" value="{{ $instanceId }}">
                    <input type="hidden" name="expected_epoch" value="{{ $capabilities['recovery']['epoch'] }}">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex flex-col gap-2 text-sm font-medium text-gray-700">{{ __('updater.action') }}<select name="action" class="min-h-10 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">@foreach($capabilities['actions'] as $action)<option value="{{ $action }}">{{ __('updater.'.$action) }}</option>@endforeach</select></label>
                        <label class="flex flex-col gap-2 text-sm font-medium text-gray-700">{{ __('updater.point') }}<select name="recovery_point_id" class="min-h-10 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">{{ __('updater.select_point') }}</option>@foreach($points as $point)<option value="{{ $point['id'] }}">{{ $point['id'] }}</option>@endforeach</select></label>
                    </div>
                    <button type="submit" class="min-h-10 self-start rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">{{ __('updater.new_plan') }}</button>
                </form>
            </section>
        @endif

        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('updater.lookup') }}</h2>
            <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('updater.lookup_hint') }}</p>
            <form method="GET" action="{{ route('admin.system-updates.updater.console') }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <label class="flex min-w-0 flex-1 flex-col gap-2 text-sm font-medium text-gray-700">{{ __('updater.request_id') }}<input name="request" required pattern="[A-Za-z0-9][A-Za-z0-9._-]{7,127}" value="{{ $requestId }}" class="w-full min-h-10 rounded-md border border-gray-300 bg-white px-3 py-2 font-mono text-sm focus:border-blue-500 focus:ring-blue-500"></label>
                <button type="submit" class="min-h-10 rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('updater.lookup') }}</button>
            </form>
        </section>
    </div>
@endsection

@push('scripts')
    @vite('resources/js/admin/system-updates.js')
@endpush
