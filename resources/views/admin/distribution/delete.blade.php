@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-0">
        <div>
            <a href="{{ route('admin.distribution.show', ['channelId' => (int) $channel->id]) }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">{{ __('admin.distribution.delete.back') }}</a>
            <h1 class="mt-3 text-2xl font-bold text-gray-900">{{ __('admin.distribution.delete.heading') }}</h1>
            <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.distribution.delete.subtitle', ['channel' => (string) $channel->name]) }}</p>
            <div class="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm">
                <span class="font-semibold text-gray-900">{{ $channel->name }}</span>
                <span class="ml-2 text-gray-500">{{ $channel->domain }}</span>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.distribution.delete.impact_heading') }}</h2>
            <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach ([
                    'remote_content_count' => 'remote_content',
                    'linked_task_count' => 'linked_tasks',
                    'secret_count' => 'credentials',
                    'queued_count' => 'queued_jobs',
                    'fresh_sending_count' => 'sending_jobs',
                    'active_operation_count' => 'channel_operations',
                    'log_count' => 'logs',
                ] as $impactKey => $labelKey)
                    <div class="rounded-lg bg-gray-50 p-4">
                        <dt class="text-xs font-medium text-gray-500">{{ __('admin.distribution.delete.impact.'.$labelKey) }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ (int) $impact[$impactKey] }}</dd>
                    </div>
                @endforeach
            </dl>
            @if ((int) $impact['remote_content_count'] > 0)
                <a href="{{ route('admin.distribution.jobs', ['channel_id' => (int) $channel->id]) }}" class="mt-5 inline-flex text-sm font-medium text-blue-700 hover:text-blue-900">{{ __('admin.distribution.delete.review_jobs') }}</a>
            @endif
        </div>

        @if ((string) $channel->status !== \App\Models\DistributionChannel::STATUS_DELETING)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-6">
                <h2 class="text-lg font-semibold text-amber-950">{{ __('admin.distribution.delete.prepare_heading') }}</h2>
                <p class="mt-2 text-sm leading-6 text-amber-900">{{ __('admin.distribution.delete.prepare_desc') }}</p>
                <form method="POST" action="{{ route('admin.distribution.delete.prepare', ['channelId' => (int) $channel->id]) }}" class="mt-5">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                        {{ __('admin.distribution.delete.button.prepare') }}
                    </button>
                </form>
            </div>
        @else
            <div class="rounded-lg border border-red-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-red-900">{{ __('admin.distribution.delete.confirm_heading') }}</h2>
                <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.distribution.delete.confirm_desc') }}</p>

                @if ((int) $impact['fresh_sending_count'] > 0)
                    <div class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                        {{ __('admin.distribution.delete.sending_wait', ['count' => (int) $impact['fresh_sending_count']]) }}
                        <a href="{{ route('admin.distribution.delete', ['channelId' => (int) $channel->id]) }}" class="ml-2 font-semibold underline underline-offset-2">{{ __('admin.distribution.delete.button.refresh') }}</a>
                    </div>
                @endif
                @if ((int) $impact['fresh_operation_count'] > 0)
                    <div class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                        {{ __('admin.distribution.delete.operation_wait', ['count' => (int) $impact['fresh_operation_count']]) }}
                        <a href="{{ route('admin.distribution.delete', ['channelId' => (int) $channel->id]) }}" class="ml-2 font-semibold underline underline-offset-2">{{ __('admin.distribution.delete.button.refresh') }}</a>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.distribution.destroy', ['channelId' => (int) $channel->id]) }}" class="mt-6 space-y-5">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="impact_fingerprint" value="{{ $impact['impact_fingerprint'] }}">

                    @if ((int) $impact['remote_content_count'] > 0)
                        <label class="flex items-start gap-3 text-sm text-gray-700">
                            <input type="checkbox" name="ack_remote_content" value="1" @checked(old('ack_remote_content')) class="mt-1 rounded border-gray-300 text-red-600 focus:ring-red-500">
                            <span>{{ __('admin.distribution.delete.ack.remote_content', ['count' => (int) $impact['remote_content_count']]) }}</span>
                        </label>
                    @endif
                    @if ((int) $impact['linked_task_count'] > 0)
                        <label class="flex items-start gap-3 text-sm text-gray-700">
                            <input type="checkbox" name="ack_task_changes" value="1" @checked(old('ack_task_changes')) class="mt-1 rounded border-gray-300 text-red-600 focus:ring-red-500">
                            <span>{{ __('admin.distribution.delete.ack.task_changes', [
                                'count' => (int) $impact['linked_task_count'],
                                'local' => (int) $impact['tasks_switch_to_local_only'],
                                'paused' => (int) $impact['tasks_pause_distribution_only'],
                            ]) }}</span>
                        </label>
                    @endif
                    @if ((int) $impact['secret_count'] > 0)
                        <label class="flex items-start gap-3 text-sm text-gray-700">
                            <input type="checkbox" name="ack_credentials" value="1" @checked(old('ack_credentials')) class="mt-1 rounded border-gray-300 text-red-600 focus:ring-red-500">
                            <span>{{ __('admin.distribution.delete.ack.credentials', ['count' => (int) $impact['secret_count']]) }}</span>
                        </label>
                    @endif
                    <label class="flex items-start gap-3 text-sm text-gray-700">
                        <input type="checkbox" name="ack_history" value="1" @checked(old('ack_history')) class="mt-1 rounded border-gray-300 text-red-600 focus:ring-red-500">
                        <span>{{ __('admin.distribution.delete.ack.history') }}</span>
                    </label>
                    @if ((int) $impact['stale_sending_count'] > 0)
                        <label class="flex items-start gap-3 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                            <input type="checkbox" name="force_stale_sending" value="1" @checked(old('force_stale_sending')) class="mt-1 rounded border-red-300 text-red-600 focus:ring-red-500">
                            <span>{{ __('admin.distribution.delete.ack.stale_sending', [
                                'count' => (int) $impact['stale_sending_count'],
                                'minutes' => (int) ceil(((int) $impact['stale_after_seconds']) / 60),
                            ]) }}</span>
                        </label>
                    @endif
                    @if ((int) $impact['stale_operation_count'] > 0)
                        <label class="flex items-start gap-3 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                            <input type="checkbox" name="force_stale_operations" value="1" @checked(old('force_stale_operations')) class="mt-1 rounded border-red-300 text-red-600 focus:ring-red-500">
                            <span>{{ __('admin.distribution.delete.ack.stale_operations', [
                                'count' => (int) $impact['stale_operation_count'],
                                'minutes' => (int) ceil(\App\Services\GeoFlow\DistributionChannelOperationLeaseService::LEASE_SECONDS / 60),
                            ]) }}</span>
                        </label>
                    @endif

                    <div>
                        <label for="confirmation_name" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.delete.field.confirmation_name', ['channel' => (string) $channel->name]) }}</label>
                        <input id="confirmation_name" name="confirmation_name" type="text" value="{{ old('confirmation_name') }}" required autocomplete="off" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500">
                        @error('confirmation_name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.delete.field.current_password') }}</label>
                        <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500">
                        @error('current_password')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>

                    @if ($errors->any())
                        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
                    @endif

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" @disabled((int) $impact['fresh_sending_count'] > 0 || (int) $impact['fresh_operation_count'] > 0) class="inline-flex items-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                            {{ __('admin.distribution.delete.button.delete') }}
                        </button>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.distribution.delete.cancel', ['channelId' => (int) $channel->id]) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="text-sm font-medium text-gray-600 hover:text-gray-900">{{ __('admin.distribution.delete.button.cancel') }}</button>
                </form>
            </div>
        @endif
    </div>
@endsection
