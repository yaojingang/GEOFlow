@extends('admin.layouts.app')

@section('content')
    <div
        class="px-4 sm:px-0"
        data-ai-source-providers-index
        data-model-test-url="{{ route('admin.ai-source-providers.model-bindings.test') }}"
        data-testing-label="{{ __('admin.ai_source_providers.testing') }}"
        data-test-success-prefix="{{ __('admin.ai_source_providers.test_success_prefix') }}"
        data-test-failed-prefix="{{ __('admin.ai_source_providers.test_failed_prefix') }}"
        data-test-network-error="{{ __('admin.ai_source_providers.test_network_error') }}"
        data-test-initialization-error="{{ __('admin.ai_source_providers.test_network_error') }}"
    >
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.ai_source_providers.page_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_source_providers.page_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.ai-source-providers.create') }}" class="inline-flex min-h-10 items-center justify-center gap-2 self-start rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-[background-color,transform] duration-150 hover:bg-emerald-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">
                <i data-lucide="plus" class="w-4 h-4"></i>
                {{ __('admin.ai_source_providers.create') }}
            </a>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4 lg:gap-6">
            <div class="rounded-lg bg-white p-6 shadow">
                <div class="text-sm font-medium text-gray-500">{{ __('admin.ai_source_providers.stats.total') }}</div>
                <div class="mt-2 text-2xl font-bold text-gray-900">{{ (int) ($stats['provider_count'] ?? 0) }}</div>
            </div>
            <div class="rounded-lg bg-white p-6 shadow">
                <div class="text-sm font-medium text-gray-500">{{ __('admin.ai_source_providers.stats.active') }}</div>
                <div class="mt-2 text-2xl font-bold text-teal-600">{{ (int) ($stats['active_provider_count'] ?? 0) }}</div>
            </div>
            <div class="rounded-lg bg-white p-6 shadow">
                <div class="text-sm font-medium text-gray-500">{{ __('admin.ai_source_providers.stats.today_usage') }}</div>
                <div class="mt-2 text-2xl font-bold text-orange-600">{{ number_format((int) ($stats['provider_today_usage'] ?? 0)) }}</div>
            </div>
            <div class="rounded-lg bg-white p-6 shadow">
                <div class="text-sm font-medium text-gray-500">{{ __('admin.ai_source_providers.stats.failed_runs') }}</div>
                <div class="mt-2 text-2xl font-bold text-rose-600">{{ number_format((int) ($stats['failed_runs'] ?? 0)) }}</div>
            </div>
        </div>

        <div class="mb-6 rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_source_providers.quick_config_title') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_source_providers.quick_config_desc') }}</p>
            </div>
            <div class="grid grid-cols-1 divide-y divide-gray-200 xl:grid-cols-2 xl:divide-x xl:divide-y-0">
                <form method="POST" action="{{ route('admin.ai-source-providers.model-bindings.upsert-api') }}" class="space-y-5 p-6">
                    @csrf
                    <input type="hidden" name="binding_type" value="deepseek">
                    <input type="hidden" id="deepseek_config_model_id" value="{{ (int) ($deepSeekApiConfig['id'] ?? 0) }}">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <i data-lucide="braces" class="h-5 w-5 text-emerald-600"></i>
                                <h4 class="text-base font-semibold text-gray-900">{{ __('admin.ai_source_providers.deepseek_config_title') }}</h4>
                            </div>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_source_providers.deepseek_config_desc') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800">JSON</span>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="deepseek_api_name" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_name') }}</label>
                            <input type="text" name="name" id="deepseek_api_name" required value="{{ $deepSeekApiConfig['name'] }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div>
                            <label for="deepseek_api_model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_model_id') }}</label>
                            <input type="text" name="model_id" id="deepseek_api_model_id" required value="{{ $deepSeekApiConfig['model_id'] }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.ai_source_providers.placeholder_deepseek_model_id') }}">
                        </div>
                    </div>

                    <div>
                        <label for="deepseek_api_url" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_api_url') }}</label>
                        <input type="url" name="api_url" id="deepseek_api_url" required value="{{ $deepSeekApiConfig['api_url'] }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-1">
                            <label for="deepseek_api_key" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_api_key') }}</label>
                            <input type="password" name="api_key" id="deepseek_api_key" @if ((int) ($deepSeekApiConfig['id'] ?? 0) <= 0) required @endif class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ (int) ($deepSeekApiConfig['id'] ?? 0) > 0 ? __('admin.ai_source_providers.placeholder_api_key_keep') : __('admin.ai_source_providers.placeholder_api_key') }}">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.ai_source_providers.configured_key') }}: {{ $deepSeekApiConfig['masked_api_key'] }}</p>
                        </div>
                        <div>
                            <label for="deepseek_daily_limit" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_daily_limit') }}</label>
                            <input type="number" name="daily_limit" id="deepseek_daily_limit" min="0" value="{{ (int) ($deepSeekApiConfig['daily_limit'] ?? 0) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div>
                            <label for="deepseek_max_tokens" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_max_tokens') }}</label>
                            <input type="number" name="max_tokens" id="deepseek_max_tokens" min="1" value="{{ $deepSeekApiConfig['max_tokens'] }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3">
                        <button type="button" data-model-test data-model-input="deepseek_config_model_id" data-result-target="deepseek-config-test-result" data-binding-type="deepseek" data-empty-message="{{ __('admin.ai_source_providers.save_api_before_test') }}" data-connection-test-button disabled aria-disabled="true" class="min-h-10 rounded-lg border border-emerald-200 bg-white px-4 py-2 text-sm font-medium text-emerald-700 transition-[color,background-color,opacity,transform] duration-150 hover:bg-emerald-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white disabled:active:scale-100">
                            {{ __('admin.ai_source_providers.structured_test') }}
                        </button>
                        <button type="submit" class="rounded-md border border-transparent bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">
                            {{ __('admin.ai_source_providers.save_api_config') }}
                        </button>
                    </div>
                    <div id="deepseek-config-test-result" class="text-xs" data-connection-test-result role="status" aria-live="polite" aria-atomic="true"></div>
                </form>

                <form method="POST" action="{{ route('admin.ai-source-providers.model-bindings.upsert-api') }}" class="space-y-5 p-6">
                    @csrf
                    <input type="hidden" name="binding_type" value="ark">
                    <input type="hidden" id="ark_config_model_id" value="{{ (int) ($arkApiConfig['id'] ?? 0) }}">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <i data-lucide="search-check" class="h-5 w-5 text-teal-600"></i>
                                <h4 class="text-base font-semibold text-gray-900">{{ __('admin.ai_source_providers.doubao_ark_config_title') }}</h4>
                            </div>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_source_providers.doubao_ark_config_desc') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-teal-100 px-2 py-1 text-xs font-semibold text-teal-800">Responses</span>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="ark_api_name" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_name') }}</label>
                            <input type="text" name="name" id="ark_api_name" required value="{{ $arkApiConfig['name'] }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                        </div>
                        <div>
                            <label for="ark_api_model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_model_id') }}</label>
                            <input type="text" name="model_id" id="ark_api_model_id" required value="{{ $arkApiConfig['model_id'] }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500" placeholder="{{ __('admin.ai_source_providers.placeholder_ark_model_id') }}">
                        </div>
                    </div>

                    <div>
                        <label for="ark_api_url" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_api_url') }}</label>
                        <input type="url" name="api_url" id="ark_api_url" required value="{{ $arkApiConfig['api_url'] }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-1">
                            <label for="ark_api_key" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_api_key') }}</label>
                            <input type="password" name="api_key" id="ark_api_key" @if ((int) ($arkApiConfig['id'] ?? 0) <= 0) required @endif class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500" placeholder="{{ (int) ($arkApiConfig['id'] ?? 0) > 0 ? __('admin.ai_source_providers.placeholder_api_key_keep') : __('admin.ai_source_providers.placeholder_api_key') }}">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.ai_source_providers.configured_key') }}: {{ $arkApiConfig['masked_api_key'] }}</p>
                        </div>
                        <div>
                            <label for="ark_daily_limit" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_daily_limit') }}</label>
                            <input type="number" name="daily_limit" id="ark_daily_limit" min="0" value="{{ (int) ($arkApiConfig['daily_limit'] ?? 0) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                        </div>
                        <div>
                            <label for="ark_max_tokens" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.field_max_tokens') }}</label>
                            <input type="number" name="max_tokens" id="ark_max_tokens" min="1" value="{{ $arkApiConfig['max_tokens'] }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3">
                        <button type="button" data-model-test data-model-input="ark_config_model_id" data-result-target="ark-config-test-result" data-binding-type="ark" data-empty-message="{{ __('admin.ai_source_providers.save_api_before_test') }}" data-connection-test-button disabled aria-disabled="true" class="min-h-10 rounded-lg border border-teal-200 bg-white px-4 py-2 text-sm font-medium text-teal-700 transition-[color,background-color,opacity,transform] duration-150 hover:bg-teal-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white disabled:active:scale-100">
                            {{ __('admin.ai_source_providers.structured_test') }}
                        </button>
                        <button type="submit" class="rounded-md border border-transparent bg-teal-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-teal-700">
                            {{ __('admin.ai_source_providers.save_api_config') }}
                        </button>
                    </div>
                    <div id="ark-config-test-result" class="text-xs" data-connection-test-result role="status" aria-live="polite" aria-atomic="true"></div>
                </form>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-lg bg-white shadow lg:col-span-2">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_source_providers.search_list_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_source_providers.search_list_desc') }}</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200" data-sticky-actions>
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.ai_source_providers.column.provider') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.ai_source_providers.column.options') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.ai_source_providers.column.usage') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.ai_source_providers.column.status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.ai_source_providers.column.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                        @if (empty($providers))
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                    <i data-lucide="search-x" class="mx-auto mb-2 h-8 w-8 text-gray-400"></i>
                                    <p>{{ __('admin.ai_source_providers.empty') }}</p>
                                    <a href="{{ route('admin.ai-source-providers.create') }}" class="mt-2 inline-flex min-h-10 items-center text-teal-600 transition-[color,transform] duration-150 hover:text-teal-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2">
                                        {{ __('admin.ai_source_providers.add_first') }}
                                    </a>
                                </td>
                            </tr>
                        @else
                            @foreach ($providers as $provider)
                                <tr>
                                    <td class="px-6 py-4 align-top">
                                        <div class="flex items-center gap-2">
                                            <div class="text-sm font-medium text-gray-900">{{ $provider['name'] }}</div>
                                            <span class="inline-flex rounded-full bg-teal-100 px-2 py-0.5 text-xs font-semibold text-teal-800">
                                                {{ $provider['provider_label'] }}
                                            </span>
                                        </div>
                                        <div class="mt-1 max-w-sm truncate text-sm text-gray-500">{{ $provider['endpoint_url'] }}</div>
                                        <div class="mt-1 text-xs text-gray-400">{{ __('admin.ai_source_providers.api_key_mask') }}: {{ $provider['masked_api_key'] }}</div>
                                    </td>
                                    <td class="px-6 py-4 align-top text-sm text-gray-700">
                                        <div>{{ __('admin.ai_source_providers.option_count', ['count' => (int) ($provider['metadata']['count'] ?? 10)]) }}</div>
                                        <div>{{ __('admin.ai_source_providers.option_summary') }}: {{ ! empty($provider['metadata']['need_summary']) ? __('admin.common.yes') : __('admin.common.no') }}</div>
                                        <div>{{ __('admin.ai_source_providers.option_content') }}: {{ ! empty($provider['metadata']['need_content']) ? __('admin.common.yes') : __('admin.common.no') }}</div>
                                    </td>
                                    <td class="px-6 py-4 align-top text-sm text-gray-900">
                                        @if ((int) $provider['daily_limit'] > 0)
                                            <div>{{ (int) $provider['used_today'] }} / {{ (int) $provider['daily_limit'] }}</div>
                                            <div class="text-xs text-gray-500">{{ __('admin.ai_source_providers.limit_today') }}</div>
                                        @else
                                            <div class="text-green-600">{{ __('admin.ai_source_providers.limit_unlimited') }}</div>
                                        @endif
                                        <div class="mt-1 text-xs text-gray-500">{{ __('admin.ai_source_providers.total_used', ['count' => number_format((int) $provider['total_used'])]) }}</div>
                                    </td>
                                    <td class="px-6 py-4 align-top">
                                        @if ($provider['status'] === 'active')
                                            <span class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-800">{{ __('admin.ai_source_providers.status_active') }}</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-red-100 px-2 py-1 text-xs font-semibold text-red-800">{{ __('admin.ai_source_providers.status_inactive') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 align-top text-sm font-medium">
                                        <div class="flex flex-wrap items-center gap-3">
                                            <button type="button" data-provider-test data-test-url="{{ route('admin.ai-source-providers.test', ['providerId' => $provider['id']]) }}" data-result-target="provider-test-result-{{ (int) $provider['id'] }}" data-connection-test-button disabled aria-disabled="true" class="inline-flex min-h-10 items-center text-emerald-600 transition-[color,opacity,transform] duration-150 hover:text-emerald-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:text-emerald-600 disabled:active:scale-100">{{ __('admin.ai_source_providers.test') }}</button>
                                            <a href="{{ route('admin.ai-source-providers.edit', ['providerId' => $provider['id']]) }}" class="inline-flex min-h-10 items-center text-blue-600 transition-[color,transform] duration-150 hover:text-blue-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">{{ __('admin.ai_source_providers.edit') }}</a>
                                            <form method="POST" action="{{ route('admin.ai-source-providers.delete', ['providerId' => $provider['id']]) }}" data-provider-delete-form data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.ai_source_providers.confirm_delete', ['name' => $provider['name']]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.ai_source_providers.delete') }}">
                                                @csrf
                                                <button type="submit" data-provider-delete-submit data-admin-confirm-submit disabled aria-disabled="true" class="inline-flex min-h-10 items-center text-red-600 transition-[color,opacity,transform] duration-150 hover:text-red-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:text-red-600 disabled:active:scale-100">{{ __('admin.ai_source_providers.delete') }}</button>
                                            </form>
                                        </div>
                                        <div id="provider-test-result-{{ (int) $provider['id'] }}" class="mt-2 max-w-xs whitespace-normal text-xs" data-connection-test-result role="status" aria-live="polite" aria-atomic="true"></div>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-lg bg-white shadow">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_source_providers.model_bindings_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_source_providers.model_bindings_desc') }}</p>
                </div>
                <form method="POST" action="{{ route('admin.ai-source-providers.model-bindings') }}" class="space-y-5 px-6 py-5">
                    @csrf
                    <div>
                        <label for="ark_model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.ark_model') }}</label>
                        <select name="ark_model_id" id="ark_model_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="0">{{ __('admin.ai_source_providers.model_none') }}</option>
                            @foreach ($arkModels as $model)
                                <option value="{{ (int) $model['id'] }}" @selected((int) $arkModelId === (int) $model['id'])>
                                    {{ $model['name'].' ('.$model['model_id'].')' }}
                                </option>
                            @endforeach
                        </select>
                        <div class="mt-2 flex items-center justify-between gap-3">
                            <p class="text-xs text-gray-500">{{ __('admin.ai_source_providers.ark_model_help') }}</p>
                            <button type="button" data-model-test data-model-input="ark_model_id" data-result-target="ark-model-test-result" data-binding-type="ark" data-empty-message="{{ __('admin.ai_source_providers.select_model_first') }}" data-connection-test-button disabled aria-disabled="true" class="inline-flex min-h-10 shrink-0 items-center text-xs font-medium text-emerald-600 transition-[color,opacity,transform] duration-150 hover:text-emerald-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:text-emerald-600 disabled:active:scale-100">{{ __('admin.ai_source_providers.test') }}</button>
                        </div>
                        <div id="ark-model-test-result" class="mt-2 text-xs" data-connection-test-result role="status" aria-live="polite" aria-atomic="true"></div>
                    </div>

                    <div>
                        <label for="deepseek_model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_source_providers.deepseek_model') }}</label>
                        <select name="deepseek_model_id" id="deepseek_model_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="0">{{ __('admin.ai_source_providers.model_none') }}</option>
                            @foreach ($deepSeekModels as $model)
                                <option value="{{ (int) $model['id'] }}" @selected((int) $deepSeekModelId === (int) $model['id'])>
                                    {{ $model['name'].' ('.$model['model_id'].')' }}
                                </option>
                            @endforeach
                        </select>
                        <div class="mt-2 flex items-center justify-between gap-3">
                            <p class="text-xs text-gray-500">{{ __('admin.ai_source_providers.deepseek_model_help') }}</p>
                            <button type="button" data-model-test data-model-input="deepseek_model_id" data-result-target="deepseek-model-test-result" data-binding-type="deepseek" data-empty-message="{{ __('admin.ai_source_providers.select_model_first') }}" data-connection-test-button disabled aria-disabled="true" class="inline-flex min-h-10 shrink-0 items-center text-xs font-medium text-emerald-600 transition-[color,opacity,transform] duration-150 hover:text-emerald-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:text-emerald-600 disabled:active:scale-100">{{ __('admin.ai_source_providers.test') }}</button>
                        </div>
                        <div id="deepseek-model-test-result" class="mt-2 text-xs" data-connection-test-result role="status" aria-live="polite" aria-atomic="true"></div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900">
                            {{ __('admin.ai_source_providers.save_bindings') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection
