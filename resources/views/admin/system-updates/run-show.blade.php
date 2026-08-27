@extends('admin.layouts.app')

@section('content')
    @php
        $payload = is_array($run->plan_json) ? $run->plan_json : [];
        $progress = is_array($payload['progress'] ?? null) ? array_values(array_filter($payload['progress'], 'is_array')) : [];
        $status = (string) $run->status;
        $statusClass = match ($status) {
            'queued' => 'border-slate-200 bg-slate-50 text-slate-600',
            'running' => 'border-blue-200 bg-blue-50 text-blue-700',
            'succeeded' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'failed' => 'border-red-200 bg-red-50 text-red-700',
            default => 'border-gray-200 bg-gray-50 text-gray-600',
        };
        $errorMessage = $run->error_message === 'legacy_executor_retired'
            ? __('admin.system_updates.error.legacy_executor_retired')
            : $run->error_message;
    @endphp

    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.system-updates.index') }}" class="mt-2 text-gray-400 hover:text-gray-600"><i data-lucide="arrow-left" class="h-5 w-5"></i></a>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.system_updates.section.run_detail') }}</h1>
                    <p class="mt-2 text-sm text-gray-600">{{ __('admin.system_updates.history.read_only') }}</p>
                </div>
            </div>
            <span class="inline-flex w-fit rounded-full border px-3 py-1 text-sm font-semibold {{ $statusClass }}">{{ __('admin.system_updates.run.status_'.$status) }}</span>
        </div>

        <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="grid gap-4 px-6 py-6 sm:grid-cols-2 xl:grid-cols-3">
                @foreach([
                    __('admin.system_updates.label.run_uuid') => $run->run_uuid,
                    __('admin.system_updates.plan.action') => __('admin.system_updates.run.action_'.$run->action),
                    __('admin.system_updates.label.target_version') => filled($run->target_version) ? 'v'.$run->target_version : __('admin.common.none'),
                    __('admin.system_updates.label.created_by') => optional($run->startedBy)->display_name ?: optional($run->startedBy)->username ?: __('admin.common.none'),
                    __('admin.system_updates.label.started_at') => optional($run->started_at)->format('Y-m-d H:i:s') ?: __('admin.common.none'),
                    __('admin.system_updates.label.finished_at') => optional($run->finished_at)->format('Y-m-d H:i:s') ?: __('admin.common.none'),
                ] as $label => $value)
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ $label }}</div>
                        <div class="mt-2 break-all text-sm font-semibold text-gray-900">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
            @if($errorMessage)
                <div class="border-t border-gray-100 px-6 py-5">
                    <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-700">{{ $errorMessage }}</div>
                </div>
            @endif
        </section>

        <section class="mt-6 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-5">
                <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.run_timeline') }}</h2>
            </div>
            <div class="px-6 py-6">
                <ol class="space-y-4">
                    @forelse($progress as $step)
                        <li class="flex gap-4">
                            <span class="mt-1.5 h-2.5 w-2.5 flex-none rounded-full {{ ($step['status'] ?? null) === 'failed' ? 'bg-red-500' : (($step['status'] ?? null) === 'succeeded' ? 'bg-emerald-500' : 'bg-blue-500') }}"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap justify-between gap-2">
                                    <span class="font-semibold text-gray-900">{{ __('admin.system_updates.progress.'.(string) ($step['key'] ?? 'complete')) }}</span>
                                    <span class="text-xs text-gray-500">{{ (string) ($step['at'] ?? '') }}</span>
                                </div>
                                @if(filled($step['message'] ?? null))
                                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ (string) $step['message'] }}</p>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">{{ __('admin.system_updates.empty.no_progress') }}</li>
                    @endforelse
                </ol>
            </div>
        </section>
    </div>
@endsection
