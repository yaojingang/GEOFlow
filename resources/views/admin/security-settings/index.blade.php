@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.security.page_title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.security.page_subtitle') }}</p>
        </div>

        <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-4">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="shield-alert" class="h-8 w-8 text-red-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.security.total_sensitive_words') }}</dt>
                                <dd class="text-2xl font-bold text-gray-900">{{ count($sensitiveWords) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="user-check" class="h-8 w-8 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.security.current_admin') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $currentAdmin?->username ?? 'admin' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="clock" class="h-8 w-8 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.security.account_created') }}</dt>
                                <dd class="text-sm font-medium text-gray-900">
                                    {{ optional($currentAdmin?->created_at)->format('Y-m-d') ?? __('admin.status.unknown') }}
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="shield-check" class="h-8 w-8 text-amber-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.security.current_role') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">
                                    {{ $isSuperAdmin ? __('admin.admin_users.role_super_admin') : __('admin.admin_users.role_admin') }}
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($isSuperAdmin)
            <div class="bg-white shadow rounded-lg mb-8">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.security.super_admin_entry') }}</h3>
                </div>
                <div class="px-6 py-4 flex flex-wrap gap-3">
                    <a href="{{ route('admin.admin-users.index') }}" class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                        <i data-lucide="users" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.nav.admin_users') }}
                    </a>
                    <a href="{{ route('admin.admin-activity-logs') }}" class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium text-gray-700 border border-gray-300 bg-white hover:bg-gray-50">
                        <i data-lucide="clipboard-list" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.admin_users.view_logs') }}
                    </a>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
            <div class="space-y-6">
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.security.add_sensitive_words') }}</h3>
                    </div>
                    <div class="px-6 py-6">
                        <form method="POST" action="{{ route('admin.security-settings.words.store') }}" class="space-y-4">
                            @csrf
                            <div>
                                <label for="words" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.security.words_label') }}</label>
                                <textarea
                                    name="words"
                                    id="words"
                                    rows="8"
                                    required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="{{ __('admin.security.words_placeholder') }}"
                                >{{ old('words') }}</textarea>
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.security.words_help') }}</p>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
                                    <i data-lucide="shield-plus" class="w-4 h-4 mr-2"></i>
                                    {{ __('admin.security.add_sensitive_words') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                @if (! empty($sensitiveWords))
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">{{ __('admin.security.words_list') }}</h3>
                        </div>
                        <div class="px-6 py-6">
                            <div class="max-h-96 overflow-y-auto">
                                <div class="space-y-2">
                                    @foreach ($sensitiveWords as $word)
                                        <div class="flex items-center justify-between p-2 bg-gray-50 rounded hover:bg-gray-100">
                                            <div class="flex items-center space-x-3">
                                                <span class="text-sm font-medium text-gray-900">{{ $word['word'] }}</span>
                                                <span class="text-xs text-gray-500">
                                                    {{ __('admin.security.word_added_at', ['value' => $word['created_at']]) }}
                                                </span>
                                            </div>
                                            <form method="POST" action="{{ route('admin.security-settings.words.delete', ['wordId' => $word['id']]) }}" class="inline">
                                                @csrf
                                                <button type="submit" onclick="return confirm(@js(__('admin.security.confirm_delete_word')))" class="text-red-600 hover:text-red-800 transition-colors">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="space-y-6">
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.security.change_password') }}</h3>
                    </div>
                    <div class="px-6 py-6">
                        <form method="POST" action="{{ route('admin.security-settings.password.update') }}" class="space-y-4">
                            @csrf
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.security.current_password') }}</label>
                                <input
                                    type="password"
                                    name="current_password"
                                    id="current_password"
                                    required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="{{ __('admin.security.current_password_placeholder') }}"
                                >
                            </div>

                            <div>
                                <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.security.new_password') }}</label>
                                <input
                                    type="password"
                                    name="new_password"
                                    id="new_password"
                                    required
                                    minlength="6"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="{{ __('admin.security.new_password_placeholder') }}"
                                >
                            </div>

                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.security.confirm_new_password') }}</label>
                                <input
                                    type="password"
                                    name="confirm_password"
                                    id="confirm_password"
                                    required
                                    minlength="6"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="{{ __('admin.security.confirm_new_password_placeholder') }}"
                                >
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                    <i data-lucide="key" class="w-4 h-4 mr-2"></i>
                                    {{ __('admin.security.change_password') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i data-lucide="alert-triangle" class="h-5 w-5 text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">{{ __('admin.security.tips_title') }}</h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                <ul class="list-disc list-inside space-y-1">
                                    <li>{{ __('admin.security.tip_publish_check') }}</li>
                                    <li>{{ __('admin.security.tip_auto_trash') }}</li>
                                    <li>{{ __('admin.security.tip_update_words') }}</li>
                                    <li>{{ __('admin.security.tip_strong_password') }}</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
