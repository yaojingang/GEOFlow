@props([
    'mode',
    'targetAdmin' => null,
    'isSelf' => false,
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
    $errorIds = [
        'username' => 'admin-user-username-error',
        'display_name' => 'admin-user-display-name-error',
        'email' => 'admin-user-email-error',
        'status' => 'admin-user-status-error',
        'password' => 'admin-user-password-error',
        'confirm_password' => 'admin-user-confirm-password-error',
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
</div>
