@php
    $isCreate = ($mode ?? 'create') === 'create';
    $model = $model ?? null;
    $normalizeScalar = static function (mixed $value, mixed $fallback = ''): string {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($fallback) || is_int($fallback) || is_float($fallback)
            ? (string) $fallback
            : '';
    };
    $fieldValue = static function (string $field, mixed $createDefault = '') use ($isCreate, $model, $normalizeScalar): string {
        $fallback = $isCreate ? $createDefault : data_get($model, $field, $createDefault);

        return $normalizeScalar(old($field, $fallback), $fallback);
    };
    $selectedModelType = $fieldValue('model_type', 'chat');
    $selectedStatus = $fieldValue('status', 'active');
    $selectedAccessScope = $fieldValue('access_scope', \App\Models\AiModel::ACCESS_SCOPE_USER_CONTENT);
    $maxTokensVisible = ($supportsModelMaxTokens ?? false) && $selectedModelType === 'chat';
@endphp

<div class="space-y-8">
    <fieldset>
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.ai_models.basic_section') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_name') }}</label>
                <input type="text" name="name" id="name" required value="{{ $fieldValue('name') }}" @error('name') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_name') }}" autocomplete="off">
                @error('name')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            @if ($actorIsSuperAdmin ?? false)
                <div>
                    <label for="access_scope" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_access_scope') }}</label>
                    <select name="access_scope" id="access_scope" class="mt-1 block min-h-10 w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="{{ \App\Models\AiModel::ACCESS_SCOPE_USER_CONTENT }}" @selected($selectedAccessScope === \App\Models\AiModel::ACCESS_SCOPE_USER_CONTENT)>{{ __('admin.ai_models.access_scope_user_content') }}</option>
                        <option value="{{ \App\Models\AiModel::ACCESS_SCOPE_SYSTEM_ONLY }}" @selected($selectedAccessScope === \App\Models\AiModel::ACCESS_SCOPE_SYSTEM_ONLY)>{{ __('admin.ai_models.access_scope_system_only') }}</option>
                    </select>
                    <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ __('admin.ai_models.access_scope_help') }}</p>
                    @error('access_scope')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif
            <div>
                <label for="version" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_version') }}</label>
                <input type="text" name="version" id="version" value="{{ $fieldValue('version') }}" @error('version') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_version') }}" autocomplete="off">
                @error('version')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="model_type" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_type') }}</label>
                <select name="model_type" id="model_type" required class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <option value="chat" @selected($selectedModelType === 'chat')>{{ __('admin.ai_models.type_chat_option') }}</option>
                    <option value="embedding" @selected($selectedModelType === 'embedding')>{{ __('admin.ai_models.type_embedding_option') }}</option>
                </select>
                <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ $isCreate ? __('admin.ai_models.type_help_create') : __('admin.ai_models.type_help') }}</p>
                @error('model_type')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_model_id') }}</label>
                <input type="text" name="model_id" id="model_id" required value="{{ $fieldValue('model_id') }}" @error('model_id') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_model_id') }}" autocomplete="off" spellcheck="false">
                @error('model_id')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>

    <fieldset class="border-t border-gray-200 pt-7">
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.ai_models.connection_section') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5">
            <div>
                <label for="api_url" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_api_url') }}</label>
                <input type="url" name="api_url" id="api_url" value="{{ $fieldValue('api_url', 'https://api.deepseek.com') }}" @error('api_url') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_api_url') }}" autocomplete="url" spellcheck="false">
                <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ $isCreate ? __('admin.ai_models.api_url_help_create') : __('admin.ai_models.api_url_help') }}</p>
                @error('api_url')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="api_key" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_api_key') }}</label>
                <div class="relative mt-1">
                    <input type="password" name="api_key" id="api_key" @required($isCreate) @error('api_key') aria-invalid="true" @enderror class="block w-full rounded-lg border-gray-300 {{ $isCreate ? 'pr-24' : '' }} shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ $isCreate ? __('admin.ai_models.placeholder_api_key') : __('admin.ai_models.placeholder_api_key_keep') }}" autocomplete="new-password" spellcheck="false">
                    @if ($isCreate)
                        <button type="button" class="absolute inset-y-0 right-1 my-1 inline-flex min-w-20 items-center justify-center rounded-md px-3 text-xs font-semibold text-gray-500 transition-[color,background-color,transform] duration-150 hover:bg-gray-100 hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500" data-api-key-toggle aria-controls="api_key" aria-pressed="false">
                            {{ __('admin.ai_models.show_api_key') }}
                        </button>
                    @endif
                </div>
                <p id="apiKeyHelp" class="mt-1.5 text-xs leading-5 text-gray-500">{{ $isCreate ? __('admin.ai_models.api_key_help_create') : __('admin.ai_models.api_key_help_edit') }}</p>
                @error('api_key')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>

    <fieldset class="border-t border-gray-200 pt-7">
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.ai_models.advanced_section') }}</legend>
        <div @class(['mt-4 grid grid-cols-1 gap-5 md:grid-cols-2', 'lg:grid-cols-3' => $isCreate])>
            <div>
                <label for="failover_priority" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_failover_priority') }}</label>
                <input type="number" name="failover_priority" id="failover_priority" min="1" value="{{ $fieldValue('failover_priority', 100) }}" @error('failover_priority') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm tabular-nums">
                <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ __('admin.ai_models.failover_priority_help') }}</p>
                @error('failover_priority')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="daily_limit" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_daily_limit') }}</label>
                <input type="number" name="daily_limit" id="daily_limit" min="0" value="{{ $fieldValue('daily_limit', 0) }}" @error('daily_limit') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm tabular-nums">
                <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ __('admin.ai_models.limit_help') }}</p>
                @error('daily_limit')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div data-max-tokens-field class="{{ $maxTokensVisible ? '' : 'hidden' }}">
                <label for="max_tokens" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_max_tokens') }}</label>
                <input type="number" name="max_tokens" id="max_tokens" min="1" value="{{ $fieldValue('max_tokens') }}" @error('max_tokens') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm tabular-nums" placeholder="{{ __('admin.ai_models.max_tokens_placeholder', ['tokens' => (int) ($contentMaxTokens ?? 16384)]) }}">
                <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ $isCreate ? __('admin.ai_models.max_tokens_help_create') : __('admin.ai_models.max_tokens_help') }}</p>
                @error('max_tokens')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            @unless ($isCreate)
                <div id="statusField">
                    <label for="status" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_status') }}</label>
                    <select name="status" id="status" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="active" @selected($selectedStatus === 'active')>{{ __('admin.ai_models.status_active') }}</option>
                        <option value="inactive" @selected($selectedStatus === 'inactive')>{{ __('admin.ai_models.status_inactive') }}</option>
                    </select>
                    @error('status')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endunless
        </div>
    </fieldset>
</div>
