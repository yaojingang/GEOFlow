@extends('admin.layouts.app')

@section('content')
    @php
        /** @var \Illuminate\Pagination\LengthAwarePaginator $logs */
    @endphp
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.admin-users.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.activity_logs.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.activity_logs.subtitle') }}</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="clipboard-list" class="h-6 w-6 text-indigo-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.activity_logs.total_logs') }}</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $stats['total_logs'] }}</dd>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="calendar-clock" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.activity_logs.today_logs') }}</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $stats['today_logs'] }}</dd>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="users" class="h-6 w-6 text-amber-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.activity_logs.active_admins') }}</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $stats['active_admins'] }}</dd>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <form method="GET" action="{{ route('admin.admin-activity-logs') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="md:col-span-2">
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.activity_logs.search') }}</label>
                        <input type="text" name="search" id="search" value="{{ $filters['search'] }}" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.activity_logs.search_placeholder') }}">
                    </div>
                    <div>
                        <label for="admin_id" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.activity_logs.admin') }}</label>
                        <select name="admin_id" id="admin_id" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="0">{{ __('admin.activity_logs.all_admins') }}</option>
                            @foreach ($admins as $admin)
                                <option value="{{ $admin['id'] }}" @selected($filters['admin_id'] === $admin['id'])>{{ $admin['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end gap-3">
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                            <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.activity_logs.filter') }}
                        </button>
                        <a href="{{ route('admin.admin-activity-logs') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            {{ __('admin.activity_logs.reset') }}
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.activity_logs.list_title') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.activity_logs.time') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.activity_logs.admin') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.activity_logs.action') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.activity_logs.page') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.activity_logs.target') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.activity_logs.details') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.activity_logs.ip') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($logs as $log)
                            @php
                                $rawDetails = trim((string) ($log->details ?? ''));
                                $decodedDetails = $rawDetails !== '' ? json_decode($rawDetails, true) : null;
                                $detailsText = is_array($decodedDetails)
                                    ? json_encode($decodedDetails, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                                    : $rawDetails;
                                $roleRaw = strtolower(trim((string) ($log->admin_role ?? 'admin')));
                                $isSuperAdmin = in_array($roleRaw, ['super_admin', 'superadmin'], true);
                                $adminDisplayName = trim((string) ($log->admin?->display_name ?? ''));
                            @endphp
                            <tr class="align-top">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <div>{{ optional($log->created_at)->format('Y-m-d H:i:s') }}</div>
                                    <div class="text-xs text-gray-400">{{ optional($log->created_at)->diffForHumans() }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $adminDisplayName !== '' ? $adminDisplayName : $log->admin_username }}</div>
                                    <div class="text-sm text-gray-500">{{ $log->admin_username }}</div>
                                    <div class="text-xs text-gray-400">{{ $isSuperAdmin ? __('admin.activity_logs.role_super_admin') : __('admin.activity_logs.role_admin') }}</div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 whitespace-nowrap">{{ $log->action }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    <div>{{ $log->page ?: '-' }}</div>
                                    <div class="text-xs text-gray-400">{{ $log->request_method ?: 'GET' }}</div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    @if (! empty($log->target_type))
                                        {{ $log->target_type }}@if (! empty($log->target_id)) #{{ (int) $log->target_id }} @endif
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-xs text-gray-600">
                                    <pre class="whitespace-pre-wrap break-words max-w-xl">{{ $detailsText !== '' ? \Illuminate\Support\Str::limit($detailsText, 500) : '-' }}</pre>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $log->ip_address ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.activity_logs.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($logs->hasPages())
            <div class="mt-6 flex items-center justify-between">
                <div class="text-sm text-gray-500">
                    {{ __('admin.activity_logs.page_summary', ['total' => $logs->total(), 'page' => $logs->currentPage(), 'total_pages' => $logs->lastPage()]) }}
                </div>
                <div class="flex items-center gap-2">
                    @if ($logs->onFirstPage())
                        <span class="px-4 py-2 border border-gray-200 rounded-md text-sm text-gray-300 bg-white">{{ __('admin.activity_logs.prev') }}</span>
                    @else
                        <a href="{{ $logs->previousPageUrl() }}" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 bg-white hover:bg-gray-50">{{ __('admin.activity_logs.prev') }}</a>
                    @endif
                    @if ($logs->hasMorePages())
                        <a href="{{ $logs->nextPageUrl() }}" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 bg-white hover:bg-gray-50">{{ __('admin.activity_logs.next') }}</a>
                    @else
                        <span class="px-4 py-2 border border-gray-200 rounded-md text-sm text-gray-300 bg-white">{{ __('admin.activity_logs.next') }}</span>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endsection
