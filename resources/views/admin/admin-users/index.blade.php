@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.site-settings.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.admin_users.page_title') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.admin_users.page_subtitle') }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.admin-activity-logs') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="clipboard-list" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.admin_users.view_logs') }}
                </a>
                <button type="button" onclick="showCreateAdminModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                    <i data-lucide="user-plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.admin_users.add_admin') }}
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
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
                <table class="min-w-full divide-y divide-gray-200">
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
                                        <button
                                            type="button"
                                            onclick="showEditAdminModal({{ \Illuminate\Support\Js::from($admin) }})"
                                            class="text-blue-600 hover:text-blue-800"
                                        >
                                            {{ __('admin.button.edit') }}
                                        </button>
                                    @elseif (! $admin['is_super_admin'])
                                        <div class="inline-flex items-center justify-end gap-3">
                                            <button
                                                type="button"
                                                onclick="showEditAdminModal({{ \Illuminate\Support\Js::from($admin) }})"
                                                class="text-blue-600 hover:text-blue-800"
                                            >
                                                {{ __('admin.button.edit') }}
                                            </button>
                                            <form method="POST" action="{{ route('admin.admin-users.toggle-status', ['adminId' => $admin['id']]) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="next_status" value="{{ $admin['status'] === 'active' ? 'inactive' : 'active' }}">
                                                <button type="submit" class="{{ $admin['status'] === 'active' ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                                    {{ $admin['status'] === 'active' ? __('admin.admin_users.action_disable') : __('admin.admin_users.action_enable') }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.admin-users.delete', ['adminId' => $admin['id']]) }}" class="inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('admin.admin_users.confirm_delete', ['username' => $admin['username']])) }})">
                                                @csrf
                                                <button type="submit" class="text-red-600 hover:text-red-800">
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

    <div id="create-admin-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.admin_users.modal_create') }}</h3>
                    <button type="button" onclick="hideCreateAdminModal()" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form method="POST" action="{{ route('admin.admin-users.store') }}" class="px-6 py-5 space-y-4">
                    @csrf
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_username') }}</label>
                        <input type="text" name="username" id="username" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.admin_users.placeholder_username') }}" value="{{ old('username') }}">
                    </div>

                    <div>
                        <label for="display_name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_display_name') }}</label>
                        <input type="text" name="display_name" id="display_name" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.admin_users.placeholder_display_name') }}" value="{{ old('display_name') }}">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_email') }}</label>
                        <input type="email" name="email" id="email" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.admin_users.placeholder_email') }}" value="{{ old('email') }}">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_password') }}</label>
                            <input type="password" name="password" id="password" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                        <div>
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_confirm_password') }}</label>
                            <input type="password" name="confirm_password" id="confirm_password" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                    </div>

                    <div class="bg-gray-50 border border-gray-200 rounded-md p-3 text-sm text-gray-600">
                        {{ __('admin.admin_users.create_help') }}
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="hideCreateAdminModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50">{{ __('admin.button.cancel') }}</button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md text-white bg-indigo-600 hover:bg-indigo-700">{{ __('admin.admin_users.create_admin_submit') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="edit-admin-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.admin_users.modal_edit') }}</h3>
                    <button type="button" onclick="hideEditAdminModal()" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form id="edit-admin-form" method="POST" action="#" class="px-6 py-5 space-y-4">
                    @csrf
                    <div>
                        <label for="edit_username" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_username') }}</label>
                        <input type="text" name="username" id="edit_username" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>

                    <div>
                        <label for="edit_display_name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_display_name') }}</label>
                        <input type="text" name="display_name" id="edit_display_name" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>

                    <div>
                        <label for="edit_email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_email') }}</label>
                        <input type="email" name="email" id="edit_email" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>

                    <div>
                        <label for="edit_status" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.column_status') }}</label>
                        <input type="hidden" name="status" id="edit_status_hidden" disabled>
                        <select name="status" id="edit_status" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="active">{{ __('admin.admin_users.status_active') }}</option>
                            <option value="inactive">{{ __('admin.admin_users.status_inactive') }}</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="edit_password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_new_password') }}</label>
                            <input type="password" name="password" id="edit_password" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                        <div>
                            <label for="edit_confirm_password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_confirm_new_password') }}</label>
                            <input type="password" name="confirm_password" id="edit_confirm_password" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                    </div>

                    <div class="bg-gray-50 border border-gray-200 rounded-md p-3 text-sm text-gray-600">
                        {{ __('admin.admin_users.edit_help') }}
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="hideEditAdminModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50">{{ __('admin.button.cancel') }}</button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md text-white bg-indigo-600 hover:bg-indigo-700">{{ __('admin.admin_users.update_admin_submit') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const updateAdminRouteTemplate = @json(route('admin.admin-users.update', ['adminId' => '__ADMIN_ID__']));
        const currentAdminId = @json($currentAdminId);

        function showCreateAdminModal() {
            document.getElementById('create-admin-modal').classList.remove('hidden');
        }

        function hideCreateAdminModal() {
            document.getElementById('create-admin-modal').classList.add('hidden');
        }

        function showEditAdminModal(admin) {
            const form = document.getElementById('edit-admin-form');
            const statusSelect = document.getElementById('edit_status');
            const statusHidden = document.getElementById('edit_status_hidden');
            const isSelf = Number(admin.id) === Number(currentAdminId);
            form.action = updateAdminRouteTemplate.replace('__ADMIN_ID__', admin.id);
            document.getElementById('edit_username').value = admin.username || '';
            document.getElementById('edit_display_name').value = admin.display_name || '';
            document.getElementById('edit_email').value = admin.email || '';
            statusSelect.value = admin.status || 'active';
            statusSelect.disabled = isSelf;
            statusHidden.disabled = !isSelf;
            statusHidden.value = admin.status || 'active';
            document.getElementById('edit_password').value = '';
            document.getElementById('edit_confirm_password').value = '';
            document.getElementById('edit-admin-modal').classList.remove('hidden');
        }

        function hideEditAdminModal() {
            document.getElementById('edit-admin-modal').classList.add('hidden');
        }
    </script>
@endpush
