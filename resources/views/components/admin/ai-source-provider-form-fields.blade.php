@props([
    'mode',
    'provider' => null,
    'defaultEndpoint' => '',
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
    $fieldValue = static function (string $field, mixed $createDefault = '') use ($isCreate, $provider, $normalizeScalar): string {
        $fallback = $isCreate ? $createDefault : data_get($provider, $field, $createDefault);

        return $normalizeScalar(old($field, $fallback), $fallback);
    };
    $fieldChecked = static function (string $field, bool $createDefault = false) use ($isCreate, $provider): bool {
        $fallback = $isCreate ? $createDefault : (bool) data_get($provider, $field, $createDefault);
        $value = old($field, $fallback ? '1' : '0');

        if (! is_string($value) && ! is_int($value) && ! is_bool($value)) {
            return $fallback;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    };
    $selectedFormat = $fieldValue('content_formats', 'Markdown');
    $selectedStatus = $fieldValue('status', 'active');
    $errorIds = [
        'name' => 'ai-source-provider-name-error',
        'daily_limit' => 'ai-source-provider-daily-limit-error',
        'status' => 'ai-source-provider-status-error',
        'endpoint_url' => 'ai-source-provider-endpoint-url-error',
        'api_key' => 'ai-source-provider-api-key-error',
        'count' => 'ai-source-provider-count-error',
        'content_formats' => 'ai-source-provider-content-formats-error',
        'need_summary' => 'ai-source-provider-need-summary-error',
        'need_content' => 'ai-source-provider-need-content-error',
        'need_url' => 'ai-source-provider-need-url-error',
        'auth_info_level' => 'ai-source-provider-auth-info-level-error',
        'sites' => 'ai-source-provider-sites-error',
        'block_hosts' => 'ai-source-provider-block-hosts-error',
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
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.ai_source_providers.search_list_title') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_name') }}</label>
                <input id="name" name="name" type="text" required value="{{ $fieldValue('name') }}" autocomplete="off" @error('name') aria-invalid="true" aria-describedby="{{ $errorIds['name'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.ai_source_providers.placeholder_name') }}">
                @error('name')
                    <p id="{{ $errorIds['name'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="daily_limit" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_daily_limit') }}</label>
                <input id="daily_limit" name="daily_limit" type="number" min="0" value="{{ $fieldValue('daily_limit', 0) }}" inputmode="numeric" @error('daily_limit') aria-invalid="true" aria-describedby="{{ $errorIds['daily_limit'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                @error('daily_limit')
                    <p id="{{ $errorIds['daily_limit'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            @unless ($isCreate)
                <div class="md:col-span-2">
                    <label for="status" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_status') }}</label>
                    <select id="status" name="status" @error('status') aria-invalid="true" aria-describedby="{{ $errorIds['status'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="active" @selected($selectedStatus === 'active')>{{ __('admin.ai_source_providers.status_active') }}</option>
                        <option value="inactive" @selected($selectedStatus === 'inactive')>{{ __('admin.ai_source_providers.status_inactive') }}</option>
                    </select>
                    @error('status')
                        <p id="{{ $errorIds['status'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endunless
        </div>
    </fieldset>

    <fieldset class="border-t border-gray-200 pt-7">
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.ai_source_providers.quick_config_title') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5">
            <div>
                <label for="endpoint_url" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_endpoint_url') }}</label>
                <input id="endpoint_url" name="endpoint_url" type="url" value="{{ $fieldValue('endpoint_url', $defaultEndpoint) }}" autocomplete="url" spellcheck="false" @error('endpoint_url') aria-invalid="true" aria-describedby="{{ $errorIds['endpoint_url'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ $defaultEndpoint }}">
                @error('endpoint_url')
                    <p id="{{ $errorIds['endpoint_url'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="api_key" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_api_key') }}</label>
                <input id="api_key" name="api_key" type="password" @required($isCreate) autocomplete="new-password" spellcheck="false" aria-describedby="{{ $describedBy('api_key', 'ai-source-provider-api-key-help') }}" @error('api_key') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ $isCreate ? __('admin.ai_source_providers.placeholder_api_key') : __('admin.ai_source_providers.placeholder_api_key_keep') }}">
                <p id="ai-source-provider-api-key-help" class="mt-1.5 text-xs leading-5 text-gray-500">{{ $isCreate ? __('admin.ai_source_providers.api_key_help_create') : __('admin.ai_source_providers.api_key_help_edit') }}</p>
                @error('api_key')
                    <p id="{{ $errorIds['api_key'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>

    <fieldset class="border-t border-gray-200 pt-7">
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.ai_source_providers.column.options') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <label for="count" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_count') }}</label>
                <input id="count" name="count" type="number" min="1" max="20" value="{{ $fieldValue('count', 10) }}" inputmode="numeric" @error('count') aria-invalid="true" aria-describedby="{{ $errorIds['count'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                @error('count')
                    <p id="{{ $errorIds['count'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="content_formats" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_content_formats') }}</label>
                <select id="content_formats" name="content_formats" @error('content_formats') aria-invalid="true" aria-describedby="{{ $errorIds['content_formats'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="Markdown" @selected($selectedFormat === 'Markdown')>Markdown</option>
                    <option value="Text" @selected($selectedFormat === 'Text')>Text</option>
                </select>
                @error('content_formats')
                    <p id="{{ $errorIds['content_formats'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <input type="hidden" name="search_type" value="web">
        <input type="hidden" name="need_summary" value="0">
        <input type="hidden" name="need_content" value="0">
        <input type="hidden" name="need_url" value="0">
        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label class="flex min-h-10 items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                <input id="need_summary" type="checkbox" name="need_summary" value="1" @checked($fieldChecked('need_summary', true)) @error('need_summary') aria-invalid="true" aria-describedby="{{ $errorIds['need_summary'] }}" @enderror class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                {{ __('admin.ai_source_providers.field_need_summary') }}
            </label>
            <label class="flex min-h-10 items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                <input id="need_content" type="checkbox" name="need_content" value="1" @checked($fieldChecked('need_content', true)) @error('need_content') aria-invalid="true" aria-describedby="{{ $errorIds['need_content'] }}" @enderror class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                {{ __('admin.ai_source_providers.field_need_content') }}
            </label>
            <label class="flex min-h-10 items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                <input id="need_url" type="checkbox" name="need_url" value="1" @checked($fieldChecked('need_url', true)) @error('need_url') aria-invalid="true" aria-describedby="{{ $errorIds['need_url'] }}" @enderror class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                {{ __('admin.ai_source_providers.field_need_url') }}
            </label>
        </div>
        @foreach (['need_summary', 'need_content', 'need_url'] as $booleanField)
            @error($booleanField)
                <p id="{{ $errorIds[$booleanField] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
        @endforeach
    </fieldset>

    <fieldset class="border-t border-gray-200 pt-7">
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.ai_source_providers.field_sites') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
            <div class="md:col-span-2">
                <label for="auth_info_level" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_auth_info_level') }}</label>
                <input id="auth_info_level" name="auth_info_level" type="text" value="{{ $fieldValue('auth_info_level') }}" autocomplete="off" @error('auth_info_level') aria-invalid="true" aria-describedby="{{ $errorIds['auth_info_level'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.ai_source_providers.placeholder_auth_info_level') }}">
                @error('auth_info_level')
                    <p id="{{ $errorIds['auth_info_level'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="sites" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_sites') }}</label>
                <textarea id="sites" name="sites" rows="4" @error('sites') aria-invalid="true" aria-describedby="{{ $errorIds['sites'] }}" @enderror class="mt-1 block w-full resize-y rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.ai_source_providers.placeholder_sites') }}">{{ $fieldValue('sites') }}</textarea>
                @error('sites')
                    <p id="{{ $errorIds['sites'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="block_hosts" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_block_hosts') }}</label>
                <textarea id="block_hosts" name="block_hosts" rows="4" @error('block_hosts') aria-invalid="true" aria-describedby="{{ $errorIds['block_hosts'] }}" @enderror class="mt-1 block w-full resize-y rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.ai_source_providers.placeholder_block_hosts') }}">{{ $fieldValue('block_hosts') }}</textarea>
                @error('block_hosts')
                    <p id="{{ $errorIds['block_hosts'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>
</div>
