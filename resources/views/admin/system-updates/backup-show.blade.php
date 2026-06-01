@extends('admin.layouts.app')

@section('content')
    @php
        $statusClasses = [
            'pass' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'warn' => 'border-amber-200 bg-amber-50 text-amber-700',
            'fail' => 'border-red-200 bg-red-50 text-red-700',
        ];
        $statusLabels = [
            'pass' => __('admin.system_updates.rollback_preflight.status_pass'),
            'warn' => __('admin.system_updates.rollback_preflight.status_warn'),
            'fail' => __('admin.system_updates.rollback_preflight.status_fail'),
        ];
        $actionClasses = [
            'added' => 'bg-blue-50 text-blue-700',
            'modified' => 'bg-amber-50 text-amber-700',
            'deleted' => 'bg-red-50 text-red-700',
        ];
        $actionLabels = [
            'added' => __('admin.system_updates.plan.added'),
            'modified' => __('admin.system_updates.plan.modified'),
            'deleted' => __('admin.system_updates.plan.deleted'),
        ];
        $shortHash = static fn (?string $hash): string => filled($hash) ? substr((string) $hash, 0, 12) : __('admin.common.none');
        $totalBytes = collect($files)->sum(fn (array $file): int => (int) ($file['bytes'] ?? 0));
        $preflightStatus = (string) ($preflight['status'] ?? 'pass');
        $preflightClass = $statusClasses[$preflightStatus] ?? $statusClasses['pass'];
    @endphp

    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.system-updates.index') }}" class="mt-1 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.system_updates.section.backup_detail') }}</h1>
                    <p class="mt-2 font-mono text-sm text-gray-600">{{ $backup->backup_uuid }}</p>
                </div>
            </div>

            @if($rollbackReady)
                <form method="POST" action="{{ route('admin.system-updates.rollback', ['backupUuid' => $backup->backup_uuid]) }}" class="flex flex-wrap items-end gap-2" data-confirm-message="{{ __('admin.system_updates.confirm.rollback_backup') }}" onsubmit="return confirm(this.dataset.confirmMessage)">
                    @csrf
                    @if($passwordRequired)
                        <label class="block">
                            <span class="sr-only">{{ __('admin.system_updates.label.current_admin_password') }}</span>
                            <input type="password" name="current_admin_password" placeholder="{{ __('admin.system_updates.label.current_admin_password') }}" class="block w-56 rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </label>
                    @endif
                    <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-100">
                        <i data-lucide="rotate-ccw" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.system_updates.button.rollback_backup') }}
                    </button>
                </form>
            @endif
        </div>

        <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="grid gap-4 px-6 py-6 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-lg bg-gray-50 p-4">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.from_version') }}</div>
                    <div class="mt-2 text-xl font-bold text-gray-900">v{{ $backup->from_version }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-4">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.to_version') }}</div>
                    <div class="mt-2 text-xl font-bold text-gray-900">v{{ $backup->to_version }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-4">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.total_bytes') }}</div>
                    <div class="mt-2 text-xl font-bold text-gray-900">{{ number_format($totalBytes) }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-4">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.created_by') }}</div>
                    <div class="mt-2 text-sm font-semibold text-gray-900">{{ optional($backup->createdBy)->display_name ?: optional($backup->createdBy)->username ?: __('admin.common.none') }}</div>
                </div>
                <div class="rounded-lg border p-4 {{ $preflightClass }}">
                    <div class="text-sm font-medium">{{ __('admin.system_updates.label.preflight_status') }}</div>
                    <div class="mt-2 text-xl font-bold">{{ $statusLabels[$preflightStatus] ?? __('admin.common.none') }}</div>
                </div>
            </div>
            <div class="grid gap-4 border-t border-gray-100 px-6 py-5 md:grid-cols-3">
                <div class="rounded-lg bg-emerald-50 p-4 text-emerald-700">
                    <div class="text-sm font-medium">{{ __('admin.system_updates.backup.preflight_pass') }}</div>
                    <div class="mt-2 text-2xl font-bold">{{ (int) ($preflight['pass'] ?? 0) }}</div>
                </div>
                <div class="rounded-lg bg-amber-50 p-4 text-amber-700">
                    <div class="text-sm font-medium">{{ __('admin.system_updates.backup.preflight_warn') }}</div>
                    <div class="mt-2 text-2xl font-bold">{{ (int) ($preflight['warn'] ?? 0) }}</div>
                </div>
                <div class="rounded-lg bg-red-50 p-4 text-red-700">
                    <div class="text-sm font-medium">{{ __('admin.system_updates.backup.preflight_fail') }}</div>
                    <div class="mt-2 text-2xl font-bold">{{ (int) ($preflight['fail'] ?? 0) }}</div>
                </div>
            </div>
        </section>

        <section class="mt-6 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.backup_files') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.backup_files_desc') }}</p>
                    </div>
                    <span class="rounded-full bg-gray-50 px-3 py-1 text-sm font-semibold text-gray-600">
                        {{ __('admin.system_updates.backup.file_count', ['count' => count($files)]) }}
                    </span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-semibold text-gray-500">{{ __('admin.system_updates.plan.file') }}</th>
                            <th class="px-6 py-3 text-left font-semibold text-gray-500">{{ __('admin.system_updates.plan.action') }}</th>
                            <th class="px-6 py-3 text-left font-semibold text-gray-500">{{ __('admin.system_updates.label.preflight_status') }}</th>
                            <th class="px-6 py-3 text-left font-semibold text-gray-500">{{ __('admin.system_updates.label.current_sha256') }}</th>
                            <th class="px-6 py-3 text-left font-semibold text-gray-500">{{ __('admin.system_updates.label.old_sha256') }}</th>
                            <th class="px-6 py-3 text-left font-semibold text-gray-500">{{ __('admin.system_updates.label.new_sha256') }}</th>
                            <th class="px-6 py-3 text-right font-semibold text-gray-500">{{ __('admin.system_updates.plan.bytes') }}</th>
                            <th class="px-6 py-3 text-right font-semibold text-gray-500">{{ __('admin.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse($files as $file)
                            @php($fileStatus = (string) ($file['preflight_status'] ?? 'pass'))
                            @php($fileAction = (string) ($file['action'] ?? 'modified'))
                            @php($fileStatusClass = $statusClasses[$fileStatus] ?? $statusClasses['pass'])
                            @php($fileActionClass = $actionClasses[$fileAction] ?? 'bg-gray-50 text-gray-600')
                            <tr>
                                <td class="max-w-xl px-6 py-4 font-mono text-xs text-gray-800">
                                    {{ (string) ($file['path'] ?? '') }}
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $fileActionClass }}">
                                        {{ $actionLabels[$fileAction] ?? $fileAction }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $fileStatusClass }}">
                                        {{ $statusLabels[$fileStatus] ?? __('admin.common.none') }}
                                    </span>
                                    <div class="mt-1 max-w-xs text-xs leading-5 text-gray-500">{{ (string) ($file['preflight_message'] ?? '') }}</div>
                                </td>
                                <td class="px-6 py-4 font-mono text-xs text-gray-500">{{ $shortHash((string) ($file['current_sha256'] ?? '')) }}</td>
                                <td class="px-6 py-4 font-mono text-xs text-gray-500">{{ $shortHash((string) ($file['old_sha256'] ?? $file['sha256'] ?? '')) }}</td>
                                <td class="px-6 py-4 font-mono text-xs text-gray-500">{{ $shortHash((string) ($file['new_sha256'] ?? '')) }}</td>
                                <td class="px-6 py-4 text-right text-gray-500">{{ number_format((int) ($file['bytes'] ?? 0)) }}</td>
                                <td class="px-6 py-4 text-right">
                                    @if($rollbackReady && !empty($file['can_restore']))
                                        <form method="POST" action="{{ route('admin.system-updates.rollback-file', ['backupUuid' => $backup->backup_uuid]) }}" class="inline-flex flex-wrap justify-end gap-2" data-confirm-message="{{ __('admin.system_updates.confirm.restore_file') }}" onsubmit="return confirm(this.dataset.confirmMessage)">
                                            @csrf
                                            <input type="hidden" name="path" value="{{ (string) ($file['path'] ?? '') }}">
                                            @if($passwordRequired)
                                                <input type="password" name="current_admin_password" placeholder="{{ __('admin.system_updates.label.current_admin_password') }}" class="block w-44 rounded-md border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            @endif
                                            <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100">
                                                <i data-lucide="rotate-ccw" class="mr-1.5 h-3.5 w-3.5"></i>
                                                {{ __('admin.system_updates.button.restore_file') }}
                                            </button>
                                        </form>
                                    @else
                                        <button type="button" disabled class="inline-flex cursor-not-allowed items-center rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-400">
                                            <i data-lucide="lock" class="mr-1.5 h-3.5 w-3.5"></i>
                                            {{ __('admin.system_updates.button.restore_file') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-10 text-center text-sm text-gray-500">
                                    {{ __('admin.system_updates.empty.no_backup_files') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
