@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-start gap-4">
            <a href="{{ route('admin.system-updates.index') }}" aria-label="{{ __('admin.common.back') }}" class="mt-2 text-gray-400 hover:text-gray-600"><i data-lucide="arrow-left" class="h-5 w-5"></i></a>
            <div>
                <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.system_updates.section.backup_detail') }}</h1>
                <p class="mt-2 text-sm text-gray-600">{{ __('admin.system_updates.history.read_only') }}</p>
                <p class="mt-2 break-all font-mono text-xs text-gray-500">{{ $backup->backup_uuid }}</p>
            </div>
        </div>

        <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="grid gap-4 px-6 py-6 sm:grid-cols-2 xl:grid-cols-3">
                @foreach([
                    __('admin.system_updates.label.from_version') => $backup->from_version ?: __('admin.common.none'),
                    __('admin.system_updates.label.to_version') => $backup->to_version ?: __('admin.common.none'),
                    __('admin.system_updates.label.file_count') => number_format((int) $backup->file_count),
                    __('admin.system_updates.label.total_bytes') => number_format((int) $backup->total_bytes),
                    __('admin.system_updates.label.created_by') => optional($backup->createdBy)->display_name ?: optional($backup->createdBy)->username ?: __('admin.common.none'),
                    __('admin.common.created_at') => optional($backup->created_at)->format('Y-m-d H:i:s') ?: __('admin.common.none'),
                ] as $label => $value)
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ $label }}</div>
                        <div class="mt-2 break-all text-sm font-semibold text-gray-900">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-gray-100 px-6 py-5">
                <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                    <span class="rounded-full bg-gray-100 px-3 py-1 font-semibold text-gray-700">{{ __('admin.system_updates.backup.status_'.$backup->status) }}</span>
                    @if($backup->run)
                        <a href="{{ route('admin.system-updates.runs.show', ['runUuid' => $backup->run->run_uuid]) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ __('admin.system_updates.button.view_run_detail') }}</a>
                    @endif
                </div>
            </div>
        </section>
    </div>
@endsection
