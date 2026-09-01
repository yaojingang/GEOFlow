@props([
    'mode',
    'targetAdmin' => null,
    'isSelf' => false,
    'sharedProvider' => null,
    'switchSharedProvider' => null,
    'sharingImpact' => null,
])

@php
    $isCreate = $mode === 'create';
    $normalizeScalar = static function (mixed $value, mixed $fallback = ''): string {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($fallback) || is_int($fallback) || is_float($fallback)
            ? (string) $fallback
            : '';
    };
    $fieldValue = static function (string $field) use ($isCreate, $targetAdmin, $normalizeScalar): string {
        $fallback = $isCreate ? '' : data_get($targetAdmin, $field, '');

        return $normalizeScalar(old($field, $fallback), $fallback);
    };
    $selectedStatus = $fieldValue('status');
    if (! in_array($selectedStatus, ['active', 'inactive'], true)) {
        $selectedStatus = $isCreate ? 'active' : $normalizeScalar(data_get($targetAdmin, 'status', 'active'), 'active');
    }
    $showAiConfig = $isCreate || (! $isSelf && ! data_get($targetAdmin, 'is_super_admin', false));
    $storedAiConfigMode = data_get($targetAdmin, 'shared_ai_config_owner_id') === null
        ? 'independent'
        : 'shared_current_super';
    $hasPersistedSharedProvider = ! $isCreate && $storedAiConfigMode === 'shared_current_super';
    $selectedAiConfigMode = $normalizeScalar(
        old('ai_config_mode', $isCreate ? 'independent' : $storedAiConfigMode),
        $isCreate ? 'independent' : $storedAiConfigMode,
    );
    if (! in_array($selectedAiConfigMode, ['independent', 'shared_current_super'], true)) {
        $selectedAiConfigMode = $isCreate ? 'independent' : $storedAiConfigMode;
    }
    $providerName = $normalizeScalar(data_get($sharedProvider, 'name', ''), '');
    $providerStatus = $normalizeScalar(data_get($sharedProvider, 'status', ''), '');
    $switchProviderName = $normalizeScalar(data_get($switchSharedProvider, 'name', ''), '');
    $switchProviderSelected = $normalizeScalar(old('switch_shared_provider', ''), '') === '1';
    $sharedDefaultCount = (int) data_get($sharingImpact, 'sharedDefaultCount', 0);
    if (is_object($sharingImpact) && method_exists($sharingImpact, 'sharedDefaultCount')) {
        $sharedDefaultCount = $sharingImpact->sharedDefaultCount();
    }
    $pendingTaskCount = max(0, (int) data_get($sharingImpact, 'pendingTaskCounts.total', 0));
    $errorIds = [
        'username' => 'admin-user-username-error',
        'display_name' => 'admin-user-display-name-error',
        'email' => 'admin-user-email-error',
        'status' => 'admin-user-status-error',
        'password' => 'admin-user-password-error',
        'confirm_password' => 'admin-user-confirm-password-error',
        'ai_config_mode' => 'admin-user-ai-config-mode-error',
        'switch_shared_provider' => 'admin-user-provider-switch-error',
    ];
    $describedBy = static function (string $field, ?string $helpId = null) use ($errorIds, $errors): string {
        return implode(' ', array_filter([
            $helpId,
            $errors->has($field) ? $errorIds[$field] : null,
        ]));
    };
@endphp

<div class="space-y-8">
    <fieldset>
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.admin_users.column_account') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700">{{ __('admin.admin_users.field_username') }}</label>
                <input id="username" name="username" type="text" required value="{{ $fieldValue('username') }}" autocomplete="username" @error('username') aria-invalid="true" aria-describedby="{{ $errorIds['username'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.admin_users.placeholder_username') }}">
                @error('username')
                    <p id="{{ $errorIds['username'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="display_name" class="block text-sm font-medium text-gray-700">{{ __('admin.admin_users.field_display_name') }}</label>
                <input id="display_name" name="display_name" type="text" value="{{ $fieldValue('display_name') }}" autocomplete="name" @error('display_name') aria-invalid="true" aria-describedby="{{ $errorIds['display_name'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.admin_users.placeholder_display_name') }}">
                @error('display_name')
                    <p id="{{ $errorIds['display_name'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="md:col-span-2">
                <label for="email" class="block text-sm font-medium text-gray-700">{{ __('admin.admin_users.field_email') }}</label>
                <input id="email" name="email" type="email" value="{{ $fieldValue('email') }}" autocomplete="email" @error('email') aria-invalid="true" aria-describedby="{{ $errorIds['email'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.admin_users.placeholder_email') }}">
                @error('email')
                    <p id="{{ $errorIds['email'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            @unless ($isCreate)
                <div class="md:col-span-2">
                    <label for="status" class="block text-sm font-medium text-gray-700">{{ __('admin.admin_users.column_status') }}</label>
                    @if ($isSelf)
                        <input type="hidden" name="status" value="{{ $selectedStatus }}">
                        <input id="status" type="text" readonly aria-readonly="true" value="{{ $selectedStatus === 'inactive' ? __('admin.admin_users.status_inactive') : __('admin.admin_users.status_active') }}" @error('status') aria-invalid="true" aria-describedby="{{ $errorIds['status'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-100 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @else
                        <select id="status" name="status" required @error('status') aria-invalid="true" aria-describedby="{{ $errorIds['status'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="active" @selected($selectedStatus === 'active')>{{ __('admin.admin_users.status_active') }}</option>
                            <option value="inactive" @selected($selectedStatus === 'inactive')>{{ __('admin.admin_users.status_inactive') }}</option>
                        </select>
                    @endif
                    @error('status')
                        <p id="{{ $errorIds['status'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endunless
        </div>
    </fieldset>

    <fieldset class="border-t border-gray-200 pt-7">
        <legend class="text-sm font-semibold text-gray-900">{{ $isCreate ? __('admin.admin_users.field_password') : __('admin.admin_users.field_new_password') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">{{ $isCreate ? __('admin.admin_users.field_password') : __('admin.admin_users.field_new_password') }}</label>
                <input id="password" name="password" type="password" @required($isCreate) autocomplete="new-password" aria-describedby="{{ $describedBy('password', 'admin-user-password-help') }}" @error('password') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('password')
                    <p id="{{ $errorIds['password'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700">{{ $isCreate ? __('admin.admin_users.field_confirm_password') : __('admin.admin_users.field_confirm_new_password') }}</label>
                <input id="confirm_password" name="confirm_password" type="password" @required($isCreate) autocomplete="new-password" aria-describedby="{{ $describedBy('confirm_password', 'admin-user-password-help') }}" @error('confirm_password') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('confirm_password')
                    <p id="{{ $errorIds['confirm_password'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
        <p id="admin-user-password-help" class="mt-4 rounded-lg bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-600 ring-1 ring-inset ring-gray-200">
            {{ $isCreate ? __('admin.admin_users.create_help') : __('admin.admin_users.edit_help') }}
        </p>
    </fieldset>

    @if ($showAiConfig)
        <fieldset class="border-t border-gray-200 pt-7" @error('ai_config_mode') aria-invalid="true" @enderror>
            <legend class="text-sm font-semibold text-gray-900">{{ __('admin.admin_users.ai_config_heading') }}</legend>
            <p id="admin-user-ai-config-mode-help" class="mt-2 text-sm leading-6 text-gray-600">
                {{ __('admin.admin_users.ai_config_help') }}
            </p>

            @unless ($isCreate)
                <input type="hidden" name="expected_ai_config_access_version" value="{{ (int) data_get($targetAdmin, 'ai_config_access_version', 1) }}">
                <input type="hidden" name="expected_shared_ai_config_owner_id" value="{{ data_get($targetAdmin, 'shared_ai_config_owner_id') === null ? '' : (int) data_get($targetAdmin, 'shared_ai_config_owner_id') }}">
            @endunless

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                <label for="ai_config_mode_independent" class="grid min-h-10 cursor-pointer grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:border-gray-300 active:scale-[0.99] focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500 focus-within:ring-offset-2 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/50">
                    <input
                        id="ai_config_mode_independent"
                        name="ai_config_mode"
                        type="radio"
                        value="independent"
                        required
                        @checked($selectedAiConfigMode === 'independent')
                        @error('ai_config_mode') aria-invalid="true" @enderror
                        aria-describedby="{{ $describedBy('ai_config_mode', 'admin-user-ai-config-mode-help') }}"
                        class="peer mt-1 h-4 w-4 shrink-0 border-gray-300 text-blue-600 focus:ring-blue-500"
                    >
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-gray-900">{{ __('admin.admin_users.ai_config_independent') }}</span>
                        <span class="mt-1 block text-sm leading-6 text-gray-600">{{ __('admin.admin_users.ai_config_independent_description') }}</span>
                    </span>
                    @if ($hasPersistedSharedProvider)
                        <span class="col-start-2 mt-3 hidden rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 ring-1 ring-inset ring-amber-200 peer-checked:block">
                            {{ __('admin.admin_users.ai_config_independent_impact', ['defaults' => $sharedDefaultCount, 'tasks' => $pendingTaskCount]) }}
                        </span>
                    @endif
                </label>

                <label for="ai_config_mode_shared" class="flex min-h-10 min-w-0 cursor-pointer items-start gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:border-gray-300 active:scale-[0.99] focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500 focus-within:ring-offset-2 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/50">
                    <input
                        id="ai_config_mode_shared"
                        name="ai_config_mode"
                        type="radio"
                        value="shared_current_super"
                        required
                        @checked($selectedAiConfigMode === 'shared_current_super')
                        @error('ai_config_mode') aria-invalid="true" @enderror
                        aria-describedby="{{ $describedBy('ai_config_mode', 'admin-user-ai-config-mode-help') }}"
                        class="mt-1 h-4 w-4 shrink-0 border-gray-300 text-blue-600 focus:ring-blue-500"
                    >
                    <span class="min-w-0">
                        <span class="block min-w-0 text-sm font-semibold text-gray-900 [overflow-wrap:anywhere]">
                            {{ $hasPersistedSharedProvider
                                ? __('admin.admin_users.ai_config_shared_existing', ['provider' => $providerName])
                                : __('admin.admin_users.ai_config_shared') }}
                        </span>
                        <span class="mt-1 block text-sm leading-6 text-gray-600">{{ __('admin.admin_users.ai_config_shared_priority') }}</span>
                        <span class="mt-3 flex min-w-0 max-w-full flex-wrap items-center gap-2 text-xs text-gray-600">
                            @unless ($isCreate)
                                <span>{{ __('admin.admin_users.ai_config_current_provider') }}</span>
                            @endunless
                            <span class="min-w-0 max-w-full font-medium text-gray-800 [overflow-wrap:anywhere]">{{ $providerName }}</span>
                            <span class="inline-flex min-w-0 max-w-full rounded-full px-2 py-0.5 font-medium [overflow-wrap:anywhere] {{ $providerStatus === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">
                                {{ __('admin.admin_users.ai_config_provider_status', ['status' => $providerStatus === 'active' ? __('admin.admin_users.status_active') : __('admin.admin_users.status_inactive')]) }}
                            </span>
                        </span>
                    </span>
                </label>
            </div>

            @if (! $isCreate && $switchSharedProvider !== null)
                <div class="mt-3 min-w-0 rounded-xl bg-blue-50/60 p-4 ring-1 ring-inset ring-blue-200">
                    <label for="switch_shared_provider" class="flex min-h-10 min-w-0 cursor-pointer items-start gap-3 rounded-lg focus-within:ring-2 focus-within:ring-blue-500 focus-within:ring-offset-2 focus-within:ring-offset-blue-50">
                        <input
                            id="switch_shared_provider"
                            name="switch_shared_provider"
                            type="checkbox"
                            value="1"
                            @checked($switchProviderSelected)
                            @error('switch_shared_provider') aria-invalid="true" @enderror
                            aria-describedby="{{ $describedBy('switch_shared_provider', 'admin-user-provider-switch-help') }}"
                            class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        >
                        <span class="min-w-0">
                            <span class="block min-w-0 text-sm font-semibold text-gray-900 [overflow-wrap:anywhere]">
                                {{ __('admin.admin_users.ai_config_switch_provider', ['provider' => $switchProviderName]) }}
                            </span>
                            <span id="admin-user-provider-switch-help" class="mt-1 block min-w-0 text-sm leading-6 text-gray-600 [overflow-wrap:anywhere]">
                                {{ __('admin.admin_users.ai_config_switch_provider_help', [
                                    'provider' => $switchProviderName,
                                    'defaults' => $sharedDefaultCount,
                                    'tasks' => $pendingTaskCount,
                                ]) }}
                            </span>
                        </span>
                    </label>
                    @error('switch_shared_provider')
                        <p id="{{ $errorIds['switch_shared_provider'] }}" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            @error('ai_config_mode')
                <p id="{{ $errorIds['ai_config_mode'] }}" class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </fieldset>
    @endif
</div>
