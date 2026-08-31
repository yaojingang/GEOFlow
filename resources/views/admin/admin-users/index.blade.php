@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.site-settings.index') }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 hover:bg-gray-50 hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.admin_users.page_title') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.admin_users.page_subtitle') }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3 sm:justify-end">
                <a href="{{ route('admin.admin-activity-logs') }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-[color,background-color,transform] duration-150 hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <i data-lucide="clipboard-list" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.admin_users.view_logs') }}
                </a>
                <a href="{{ route('admin.admin-users.create') }}" class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-[background-color,transform] duration-150 hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <i data-lucide="user-plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.admin_users.add_admin') }}
                </a>
            </div>
        </div>

        <div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:gap-6 [&>*:last-child]:col-span-2 md:[&>*:last-child]:col-span-1">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="users" class="h-6 w-6 text-indigo-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.admin_users.total_admins') }}</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $stats['total_admins'] }}</dd>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="badge-check" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.admin_users.active_admins') }}</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $stats['active_admins'] }}</dd>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="shield-check" class="h-6 w-6 text-amber-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.admin_users.super_admins') }}</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $stats['super_admins'] }}</dd>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <i data-lucide="info" class="w-5 h-5 text-blue-600 mt-0.5"></i>
                <div class="text-sm text-blue-900">
                    {{ __('admin.admin_users.permission_notice') }}
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.admin_users.list_title') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200" data-sticky-actions>
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_account') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_role') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_last_login') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_created') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_activity') }}</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($admins as $admin)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $admin['display_name'] !== '' ? $admin['display_name'] : $admin['username'] }}</div>
                                    <div class="text-sm text-gray-500">{{ $admin['username'] }}</div>
                                    @if ($admin['email'] !== '')
                                        <div class="text-xs text-gray-400">{{ $admin['email'] }}</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($admin['is_super_admin'])
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">{{ __('admin.admin_users.role_super_admin') }}</span>
                                    @else
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">{{ __('admin.admin_users.role_admin') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($admin['status'] === 'active')
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">{{ __('admin.admin_users.status_active') }}</span>
                                    @else
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">{{ __('admin.admin_users.status_inactive') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ $admin['last_login'] !== '' ? $admin['last_login'] : __('admin.admin_users.none_last_login') }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <div>{{ $admin['created_at'] }}</div>
                                    <div class="text-xs text-gray-400">
                                        {{ __('admin.admin_users.created_by', ['value' => $admin['creator_username'] !== '' ? $admin['creator_username'] : __('admin.admin_users.system_init')]) }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ __('admin.admin_users.activity_count', ['count' => $admin['activity_count']]) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    @if ($admin['id'] === $currentAdminId)
                                        <a href="{{ route('admin.admin-users.edit', ['adminId' => $admin['id']]) }}" class="inline-flex min-h-10 items-center text-blue-600 transition-[color,transform] duration-150 hover:text-blue-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                            {{ __('admin.button.edit') }}
                                        </a>
                                    @elseif (! $admin['is_super_admin'])
                                        <div class="inline-flex items-center justify-end gap-3">
                                            <a href="{{ route('admin.admin-users.edit', ['adminId' => $admin['id']]) }}" class="inline-flex min-h-10 items-center text-blue-600 transition-[color,transform] duration-150 hover:text-blue-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                                {{ __('admin.button.edit') }}
                                            </a>
                                            <form method="POST" action="{{ route('admin.admin-users.toggle-status', ['adminId' => $admin['id']]) }}" class="inline" @if($admin['status'] === 'active') data-admin-confirm-form data-admin-confirm-tone="warning" data-admin-confirm-title="{{ __('admin.admin_users.action_disable') }} {{ $admin['username'] }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.admin_users.action_disable') }}" @endif>
                                                @csrf
                                                <input type="hidden" name="next_status" value="{{ $admin['status'] === 'active' ? 'inactive' : 'active' }}">
                                                <button type="submit" class="inline-flex min-h-10 items-center transition-[color,transform] duration-150 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 {{ $admin['status'] === 'active' ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}" @if($admin['status'] === 'active') data-admin-confirm-submit disabled aria-disabled="true" @endif>
                                                    {{ $admin['status'] === 'active' ? __('admin.admin_users.action_disable') : __('admin.admin_users.action_enable') }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.admin-users.delete', ['adminId' => $admin['id']]) }}" class="inline" data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.admin_users.confirm_delete', ['username' => $admin['username']]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.button.delete') }}">
                                                @csrf
                                                <button type="submit" class="inline-flex min-h-10 items-center text-red-600 transition-[color,transform] duration-150 hover:text-red-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2" data-admin-confirm-submit disabled aria-disabled="true">
                                                    {{ __('admin.button.delete') }}
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-gray-300">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

@endsection
