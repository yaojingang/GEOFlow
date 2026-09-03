@extends('admin.layouts.app')

@section('content')
    <div
        class="px-4 sm:px-0"
        data-ai-models-index
        data-test-initialization-error="{{ __('admin.ai_models.test_dialog.initialization_error') }}"
        data-client-timeout-ms="100000"
    >
        <div class="mb-8 flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h1 class="break-words text-2xl font-bold text-gray-900 text-balance">{{ __('admin.ai_models.page_title') }}</h1>
                <p class="mt-1 text-pretty text-sm leading-6 text-gray-600">{{ __('admin.ai_models.page_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.ai-models.create') }}" class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.ai_models.create') }}
            </a>
        </div>

        <section class="mb-6 overflow-hidden rounded-lg bg-white shadow" aria-labelledby="personal-ai-defaults-title">
            <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                <h2 id="personal-ai-defaults-title" class="text-lg font-medium text-gray-900">{{ __('admin.ai_models.personal_defaults_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.ai_models.personal_defaults_desc') }}</p>
            </div>
            <form method="POST" action="{{ route('admin.ai-models.personal-defaults') }}" class="grid gap-4 px-5 py-5 sm:px-6 lg:grid-cols-2" data-personal-ai-defaults-form>
                @csrf
                <div>
                    <label for="personal_default_chat_model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.personal_default_chat') }}</label>
                    <select id="personal_default_chat_model_id" name="default_chat_model_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="0">{{ __('admin.ai_models.personal_default_auto') }}</option>
                        @foreach (collect($personalDefaultModelOptions)->where('model_type', 'chat') as $option)
                            <option value="{{ $option['id'] }}" @selected($personalDefaultChatModelId === $option['id'])>
                                {{ $option['name'] }}{{ $option['version'] !== '' ? ' · '.$option['version'] : '' }} · {{ __('admin.ai_models.source_'.$option['source']) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="personal_default_embedding_model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.personal_default_embedding') }}</label>
                    <select id="personal_default_embedding_model_id" name="default_embedding_model_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="0">{{ __('admin.ai_models.personal_default_auto') }}</option>
                        @foreach (collect($personalDefaultModelOptions)->where('model_type', 'embedding') as $option)
                            <option value="{{ $option['id'] }}" @selected($personalDefaultEmbeddingModelId === $option['id'])>
                                {{ $option['name'] }}{{ $option['version'] !== '' ? ' · '.$option['version'] : '' }} · {{ __('admin.ai_models.source_'.$option['source']) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="flex justify-start lg:col-span-2 lg:justify-end">
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-slate-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-2">
                        {{ __('admin.ai_models.save_personal_defaults') }}
                    </button>
                </div>
            </form>
        </section>

        @if (! $actorIsSuperAdmin)
            <section class="mb-6 rounded-lg bg-white px-5 py-5 shadow sm:px-6" aria-labelledby="ai-access-preview-title">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h2 id="ai-access-preview-title" class="text-base font-semibold text-gray-900">{{ __('admin.ai_models.preview_title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-gray-600">
                            {{ $accessPreview['mode'] === 'shared' ? __('admin.ai_models.preview_mode_shared') : __('admin.ai_models.preview_mode_independent') }}
                        </p>
                        @if ($accessPreview['mode'] === 'shared')
                            <p class="mt-1 break-words text-sm leading-6 {{ $accessPreview['provider_available'] ? 'text-gray-600' : 'text-amber-700' }}">
                                {{ $accessPreview['provider_available']
                                    ? __('admin.ai_models.preview_provider_available', ['provider' => $accessPreview['provider_name']])
                                    : __('admin.ai_models.preview_provider_inactive') }}
                            </p>
                        @endif
                    </div>
                    <div class="shrink-0 rounded-lg bg-blue-50 px-3 py-2 text-sm font-medium leading-6 text-blue-800">
                        {{ __('admin.ai_models.preview_personal_first') }}
                    </div>
                </div>
                @if ($accessPreview['needs_repair'])
                    <div class="mt-4 flex gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800" role="status">
                        <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></i>
                        <p>{{ __('admin.ai_models.preview_no_available_models') }}</p>
                    </div>
                @endif
            </section>
        @endif

        @if ($showSystemConfiguration)
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6" data-system-ai-configuration>
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_models.vector_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_models.vector_desc') }}</p>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">{{ __('admin.ai_models.pgvector') }}</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full {{ $pgvectorEnabled ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                            {{ $pgvectorEnabled ? __('admin.ai_models.pgvector_enabled') : __('admin.ai_models.pgvector_fallback') }}
                        </span>
                    </div>

                    <form method="POST" action="{{ route('admin.ai-models.default-embedding') }}" class="space-y-3">
                        @csrf
                        <div>
                            <label for="default_embedding_model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.default_embedding') }}</label>
                            <select name="default_embedding_model_id" id="default_embedding_model_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="0">{{ __('admin.ai_models.embedding_auto') }}</option>
                                @foreach ($embeddingModels as $embeddingModel)
                                    <option value="{{ (int) $embeddingModel['id'] }}" @selected($defaultEmbeddingModelId === (int) $embeddingModel['id'])>
                                        {{ $embeddingModel['name'].' ('.$embeddingModel['model_id'].')' }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.ai_models.embedding_help') }}</p>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-slate-800 hover:bg-slate-900">
                                {{ __('admin.ai_models.save_default') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_models.type_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_models.type_desc') }}</p>
                </div>
                <div class="px-6 py-5 space-y-3 text-sm text-gray-700">
                    <p>{{ __('admin.ai_models.type_chat') }}</p>
                    <p>{{ __('admin.ai_models.type_embedding') }}</p>
                    <p>{{ __('admin.ai_models.type_rerank') }}</p>
                    <p>{{ __('admin.ai_models.type_fallback') }}</p>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_models.chunking_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_models.chunking_desc') }}</p>
                </div>
                <div class="px-6 py-5">
                    <form method="POST" action="{{ route('admin.ai-models.chunking-config') }}" class="space-y-4">
                        @csrf
                        <div>
                            <label for="knowledge_chunk_strategy" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.chunk_strategy') }}</label>
                            <select name="knowledge_chunk_strategy" id="knowledge_chunk_strategy" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="rule" @selected(($chunkingConfig['strategy'] ?? 'rule') === 'rule')>{{ __('admin.ai_models.chunk_strategy_rule') }}</option>
                                <option value="auto" @selected(($chunkingConfig['strategy'] ?? 'rule') === 'auto')>{{ __('admin.ai_models.chunk_strategy_auto') }}</option>
                                <option value="semantic_llm" @selected(($chunkingConfig['strategy'] ?? 'rule') === 'semantic_llm')>{{ __('admin.ai_models.chunk_strategy_semantic') }}</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.ai_models.chunk_strategy_help') }}</p>
                        </div>
                        <div>
                            <label for="knowledge_chunking_model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.chunking_model') }}</label>
                            <select name="knowledge_chunking_model_id" id="knowledge_chunking_model_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="0">{{ __('admin.ai_models.chunking_model_none') }}</option>
                                @foreach ($chatModels as $chatModel)
                                    <option value="{{ (int) $chatModel['id'] }}" @selected((int) ($chunkingConfig['model_id'] ?? 0) === (int) $chatModel['id'])>
                                        {{ $chatModel['name'].' ('.$chatModel['model_id'].')' }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.ai_models.chunking_model_help') }}</p>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-slate-800 hover:bg-slate-900">
                                {{ __('admin.ai_models.save_chunking') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @endif

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_models.section_my_models') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_models.list_desc') }}</p>
            </div>

            <div class="max-w-full overflow-x-auto overscroll-x-contain" data-personal-ai-model-table-scroll tabindex="0" aria-label="{{ __('admin.ai_models.section_my_models') }}">
                <table class="w-full min-w-[920px] divide-y divide-gray-200" data-sticky-actions>
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="w-[28%] min-w-64 px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.info') }}</th>
                        <th class="min-w-32 px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.version') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.usage') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.limit') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.status') }}</th>
                        <th class="min-w-60 px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                    @if (empty($models))
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                <i data-lucide="cpu" class="w-8 h-8 mx-auto mb-2 text-gray-400"></i>
                                <p>{{ __('admin.ai_models.empty') }}</p>
                                <a href="{{ route('admin.ai-models.create') }}" class="mt-2 inline-flex min-h-10 items-center rounded-lg px-3 text-blue-600 transition-[color,background-color,transform] duration-150 hover:bg-blue-50 hover:text-blue-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                                    {{ __('admin.ai_models.add_first') }}
                                </a>
                            </td>
                        </tr>
                    @else
                        @foreach ($models as $model)
                            <tr>
                                <td class="min-w-64 max-w-sm px-6 py-4 align-top">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <div class="break-all text-sm font-medium text-gray-900">{{ $model['name'] }}</div>
                                            <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ $model['model_type'] === 'embedding' ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800' }}">
                                                {{ $model['model_type'] === 'embedding' ? __('admin.ai_models.type_embedding_option') : __('admin.ai_models.chat') }}
                                            </span>
                                            @if ($model['is_default_embedding'])
                                                <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800">{{ __('admin.ai_models.embedding_default') }}</span>
                                            @endif
                                        </div>
                                        <div class="break-all text-sm text-gray-500">{{ $model['model_id'] }}</div>
                                        <div class="text-xs text-gray-400">{{ __('admin.ai_models.api_key_mask') }}: {{ $model['api_key_configured'] ? __('admin.ai_models.api_key_configured') : __('admin.ai_models.api_key_not_configured') }}</div>
                                        <div class="text-xs text-gray-400">{{ __('admin.ai_models.failover_priority_label', ['priority' => (int) $model['failover_priority']]) }}</div>
                                    </div>
                                </td>
                                <td class="min-w-32 break-all px-6 py-4 align-top text-sm text-gray-900">
                                    {{ $model['version'] !== '' ? $model['version'] : '-' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <div>{{ __('admin.ai_models.usage_tasks', ['count' => (string) $model['task_count']]) }}</div>
                                        <div>{{ __('admin.ai_models.usage_articles', ['count' => (string) $model['article_count']]) }}</div>
                                        <div>{{ __('admin.ai_models.usage_total', ['count' => (string) number_format((int) $model['total_used'])]) }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    @if ((int) $model['daily_limit'] > 0)
                                        <div>{{ (int) $model['used_today'] }} / {{ (int) $model['daily_limit'] }}</div>
                                        <div class="text-xs text-gray-500">{{ __('admin.ai_models.limit_today') }}</div>
                                    @else
                                        <span class="text-green-600">{{ __('admin.ai_models.limit_unlimited') }}</span>
                                    @endif
                                    @if ($model['model_type'] === 'chat')
                                        <details class="mt-2 max-w-xs whitespace-normal text-xs text-slate-600" data-workspace-readiness>
                                            <summary class="cursor-pointer font-medium text-slate-700">
                                                {{ __('admin.ai_models.readiness_title') }}
                                                @if ($model['workspace_readiness_status'] !== '')
                                                    · {{ __('admin.ai_models.readiness_status.'.$model['workspace_readiness_status']) }}
                                                @endif
                                            </summary>
                                            <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
                                                @forelse (collect($model['workspace_readiness_profile'])->only(['configuration', 'authentication', 'plain_text', 'streaming', 'structured_output', 'tool_schema', 'tool_roundtrip', 'cancellation', 'performance']) as $check => $result)
                                                    <span>{{ __('admin.ai_models.readiness_checks.'.$check) }}</span>
                                                    <span class="text-right font-medium">{{ __('admin.ai_models.readiness_status.'.(is_array($result) ? ($result['status'] ?? 'unknown') : 'unknown')) }}</span>
                                                @empty
                                                    <span class="col-span-2 text-slate-400">{{ __('admin.ai_models.readiness_not_checked') }}</span>
                                                @endforelse
                                            </div>
                                            @if ($model['workspace_readiness_expires_at'])
                                                <p class="mt-2 text-slate-400">{{ __('admin.ai_models.readiness_valid_until', ['time' => $model['workspace_readiness_expires_at']]) }}</p>
                                            @endif
                                            @if ($model['workspace_readiness_failure_code'] !== '')
                                                <p class="mt-1 text-red-600">{{ __('admin.ai_models.readiness_failure', ['code' => $model['workspace_readiness_failure_code']]) }}</p>
                                            @endif
                                        </details>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($model['status'] === 'active')
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            {{ __('admin.ai_models.status_active') }}
                                        </span>
                                    @elseif ($model['status'] === 'inactive')
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                            {{ __('admin.ai_models.status_inactive') }}
                                        </span>
                                    @else
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                            {{ __('admin.ai_models.status_unknown') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="min-w-60 whitespace-nowrap px-6 py-4 align-top text-sm font-medium">
                                    <div class="flex min-w-max items-center gap-3">
                                        <button
                                            type="button"
                                            class="inline-flex min-h-10 items-center text-emerald-600 transition-[color,transform] duration-150 hover:text-emerald-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                            data-ai-model-test-button
                                            data-model-id="{{ (int) $model['id'] }}"
                                            data-model-name="{{ $model['name'] }}"
                                            data-provider-model-id="{{ $model['model_id'] }}"
                                            data-model-type="{{ $model['model_type'] }}"
                                            data-test-url="{{ route('admin.ai-models.test', ['modelId' => $model['id']]) }}"
                                            data-edit-url="{{ route('admin.ai-models.edit', ['modelId' => $model['id']]) }}"
                                            disabled
                                            aria-disabled="true"
                                        >{{ __('admin.ai_models.test') }}</button>
                                        <a href="{{ route('admin.ai-models.edit', ['modelId' => $model['id']]) }}" class="inline-flex min-h-10 items-center text-blue-600 transition-[color,transform] duration-150 hover:text-blue-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2" data-ai-model-test-fallback="{{ (int) $model['id'] }}">{{ __('admin.ai_models.edit') }}</a>
                                        <form
                                            method="POST"
                                            action="{{ route('admin.ai-models.delete', ['modelId' => $model['id']]) }}"
                                            class="inline-flex"
                                            data-admin-confirm-form
                                            data-admin-confirm-tone="danger"
                                            data-admin-confirm-title="{{ __('admin.ai_models.delete_dialog.title') }} “{{ $model['name'] }}”"
                                            data-admin-confirm-message="{{ __('admin.ai_models.delete_dialog.impact') }}"
                                            data-admin-confirm-guidance="{{ __('admin.action_dialog.generic_impact') }}"
                                            data-admin-confirm-label="{{ __('admin.ai_models.delete_dialog.confirm') }}"
                                        >
                                            @csrf
                                            <button type="submit" class="min-h-10 text-red-600 transition-[color,transform] duration-150 hover:text-red-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2" data-admin-confirm-submit disabled aria-disabled="true">{{ __('admin.ai_models.delete') }}</button>
                                        </form>
                                    </div>
                                    <p class="mt-2 hidden max-w-xs whitespace-normal text-xs text-red-700" data-ai-model-test-status aria-live="polite"></p>
                                </td>
                            </tr>
                        @endforeach
                    @endif
                    </tbody>
                </table>
            </div>
        </div>

        @if (! $actorIsSuperAdmin)
            <section class="mt-6 overflow-hidden rounded-lg bg-white shadow" aria-labelledby="shared-ai-models-title">
                <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                    <h2 id="shared-ai-models-title" class="text-lg font-medium text-gray-900">{{ __('admin.ai_models.section_shared_models') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.ai_models.shared_models_desc') }}</p>
                </div>
                <div class="divide-y divide-gray-200">
                    @forelse ($sharedModels as $model)
                        <div class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6" data-shared-ai-model>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="break-words text-sm font-semibold text-gray-900">{{ $model['name'] }}</h3>
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $model['model_type'] === 'embedding' ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800' }}">
                                        {{ $model['model_type'] === 'embedding' ? __('admin.ai_models.type_embedding_option') : __('admin.ai_models.chat') }}
                                    </span>
                                </div>
                                <p class="mt-1 break-words text-sm text-gray-500">{{ $model['version'] !== '' ? $model['version'] : __('admin.ai_models.version_unspecified') }}</p>
                            </div>
                            <span class="inline-flex w-fit rounded-full px-2 py-1 text-xs font-semibold {{ $model['is_available'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">
                                {{ $model['is_available'] ? __('admin.ai_models.status_available') : __('admin.ai_models.status_unavailable') }}
                            </span>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-sm leading-6 text-gray-500 sm:px-6">{{ __('admin.ai_models.shared_models_empty') }}</p>
                    @endforelse
                </div>
            </section>
        @endif

        @if ($actorIsSuperAdmin)
            <section class="mt-6 overflow-hidden rounded-lg bg-white shadow" aria-labelledby="governance-ai-models-title">
                <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                    <h2 id="governance-ai-models-title" class="text-lg font-medium text-gray-900">{{ __('admin.ai_models.section_governance_models') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.ai_models.governance_models_desc') }}</p>
                </div>
                <div class="divide-y divide-gray-200">
                    @forelse ($governanceModels as $model)
                        <div class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_minmax(10rem,auto)_auto] sm:items-center sm:px-6" data-governance-ai-model>
                            <div class="min-w-0">
                                <h3 class="break-words text-sm font-semibold text-gray-900">{{ $model['name'] }}</h3>
                                <p class="mt-1 break-words text-sm text-gray-500">{{ $model['version'] !== '' ? $model['version'] : __('admin.ai_models.version_unspecified') }}</p>
                            </div>
                            <div class="min-w-0 text-sm text-gray-600">
                                <p class="break-words font-medium text-gray-800">{{ $model['owner']['display_name'] }}</p>
                                <p class="mt-0.5">{{ __('admin.ai_models.owner_status_label', ['status' => $model['owner']['status']]) }}</p>
                            </div>
                            <span class="inline-flex w-fit rounded-full px-2 py-1 text-xs font-semibold {{ $model['is_available'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">
                                {{ $model['is_available'] ? __('admin.ai_models.status_available') : __('admin.ai_models.status_unavailable') }}
                            </span>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-sm leading-6 text-gray-500 sm:px-6">{{ __('admin.ai_models.governance_models_empty') }}</p>
                    @endforelse
                </div>
                @if ($governancePaginator?->hasPages())
                    <div class="border-t border-gray-200 px-5 py-4 sm:px-6">
                        {{ $governancePaginator->withQueryString()->links() }}
                    </div>
                @endif
            </section>
        @endif

        <dialog
            class="fixed inset-0 m-auto w-[min(640px,calc(100vw-2rem))] max-w-none overflow-hidden overscroll-contain rounded-2xl border-0 bg-white p-0 text-left text-gray-900 shadow-[0_24px_72px_rgba(15,23,42,0.28)] backdrop:bg-[rgba(15,23,42,0.48)]"
            data-ai-model-test-dialog
            role="dialog"
            aria-modal="true"
            aria-labelledby="ai-model-test-title"
            aria-describedby="ai-model-test-summary"
        >
            <div class="flex max-h-[min(780px,calc(100dvh-2rem))] flex-col">
                <header class="flex items-start gap-4 px-6 pb-5 pt-6 max-[520px]:px-5">
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700" data-ai-model-test-icon-wrap aria-hidden="true">
                        <i data-lucide="activity" class="h-5 w-5" data-ai-model-test-icon></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500">{{ __('admin.ai_models.test_dialog.eyebrow') }}</p>
                        <h2 id="ai-model-test-title" class="mt-1 text-xl font-semibold leading-7 text-gray-900 text-balance" data-ai-model-test-title>{{ __('admin.ai_models.test_dialog.testing_title') }}</h2>
                        <p id="ai-model-test-summary" class="sr-only" data-ai-model-test-announcement aria-live="polite" role="status"></p>
                    </div>
                    <button type="button" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-gray-500 transition-[background-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 [@media(hover:hover)]:hover:text-gray-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 active:scale-[.96]" data-ai-model-test-close aria-label="{{ __('admin.ai_models.test_dialog.close') }}">
                        <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="grid grid-cols-2 gap-px border-y border-gray-200 bg-gray-200 max-[520px]:grid-cols-1">
                    <div class="min-w-0 bg-gray-50 px-6 py-3 max-[520px]:px-5">
                        <p class="text-[11px] font-medium leading-4 text-gray-500">{{ __('admin.ai_models.test_dialog.model_name') }}</p>
                        <p class="mt-0.5 truncate text-sm font-semibold text-gray-900" data-ai-model-test-model-name></p>
                    </div>
                    <div class="min-w-0 bg-gray-50 px-6 py-3 max-[520px]:px-5">
                        <p class="text-[11px] font-medium leading-4 text-gray-500">{{ __('admin.ai_models.test_dialog.model_id') }}</p>
                        <p class="mt-0.5 break-all font-mono text-xs leading-5 text-gray-700" data-ai-model-test-model-id></p>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-6 max-[520px]:px-5">
                    <section class="flex min-h-52 flex-col items-center justify-center text-center" data-ai-model-test-loading>
                        <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-sky-50 text-sky-700" aria-hidden="true">
                            <i data-lucide="loader-circle" class="h-7 w-7 animate-spin"></i>
                        </span>
                        <p class="mt-5 text-base font-semibold text-gray-900" data-ai-model-test-waiting-copy>{{ __('admin.ai_models.test_dialog.waiting_initial') }}</p>
                        <p class="mt-2 text-sm tabular-nums text-gray-500" data-ai-model-test-elapsed>{{ __('admin.ai_models.test_dialog.waiting_seconds', ['seconds' => 0]) }}</p>
                        <p class="mt-4 max-w-md text-xs leading-5 text-gray-400">{{ __('admin.ai_models.test_dialog.background_note') }}</p>
                    </section>

                    <section class="hidden" data-ai-model-test-success>
                        <div class="rounded-xl bg-emerald-50 px-4 py-4 text-emerald-950">
                            <p class="text-sm font-semibold">{{ __('admin.ai_models.test_dialog.success_summary') }}</p>
                            <p class="mt-1 text-sm leading-6" data-ai-model-test-success-message></p>
                        </div>
                        <dl class="mt-5 grid grid-cols-2 overflow-hidden rounded-xl border border-gray-200 max-[520px]:grid-cols-1">
                            @foreach ([
                                'http-status' => __('admin.ai_models.test_dialog.http_status'),
                                'duration' => __('admin.ai_models.test_dialog.duration'),
                                'model-type' => __('admin.ai_models.test_dialog.model_type'),
                                'workspace' => __('admin.ai_models.test_dialog.workspace_status'),
                            ] as $metric => $label)
                                <div class="border-b border-gray-200 px-4 py-3 odd:border-r last:border-b-0 max-[520px]:border-r-0">
                                    <dt class="text-xs text-gray-500">{{ $label }}</dt>
                                    <dd class="mt-1 text-sm font-semibold text-gray-900" data-ai-model-test-{{ $metric }}>-</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <section class="hidden" data-ai-model-test-failure>
                        <div class="rounded-xl bg-red-50 px-4 py-4 text-red-950">
                            <p class="text-sm font-semibold" data-ai-model-test-diagnosis-title></p>
                            <p class="mt-1 text-sm leading-6 text-pretty" data-ai-model-test-diagnosis-reason></p>
                        </div>
                        <div class="mt-5">
                            <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.ai_models.test_dialog.steps_title') }}</h3>
                            <ol class="mt-3 space-y-2 text-sm leading-6 text-gray-700" data-ai-model-test-steps></ol>
                        </div>
                        <details class="mt-5 rounded-xl border border-gray-200 bg-gray-50" open>
                            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500">{{ __('admin.ai_models.test_dialog.technical_log') }}</summary>
                            <div class="border-t border-gray-200 px-4 py-3">
                                <p class="text-xs leading-5 text-gray-500">{{ __('admin.ai_models.test_dialog.log_hint') }}</p>
                                <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-900 p-3 font-mono text-xs leading-5 text-slate-100" data-ai-model-test-log></pre>
                            </div>
                        </details>
                    </section>
                </div>

                <footer class="flex flex-wrap justify-end gap-2.5 border-t border-gray-100 bg-gray-50 px-6 py-4 max-[520px]:flex-col max-[520px]:px-5">
                    <a href="#" class="hidden min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-[background-color,border-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-ai-model-test-edit>{{ __('admin.ai_models.test_dialog.edit_configuration') }}</a>
                    <button type="button" class="hidden min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] disabled:cursor-not-allowed disabled:opacity-50 max-[520px]:w-full" data-ai-model-test-retry>{{ __('admin.ai_models.test_dialog.retest') }}</button>
                    <button type="button" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-[background-color,border-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-ai-model-test-close>{{ __('admin.ai_models.test_dialog.close') }}</button>
                </footer>
            </div>
        </dialog>

        @php
            $aiModelTestCopy = [
            'labels' => [
                'test' => __('admin.ai_models.test'),
                'testing' => __('admin.ai_models.testing'),
                'viewResult' => __('admin.ai_models.test_dialog.view_result'),
                'testingTitle' => __('admin.ai_models.test_dialog.testing_title'),
                'successTitle' => __('admin.ai_models.test_dialog.success_title'),
                'failureTitle' => __('admin.ai_models.test_dialog.failure_title'),
                'waitingSeconds' => __('admin.ai_models.test_dialog.waiting_seconds', ['seconds' => '__SECONDS__']),
                'waitingInitial' => __('admin.ai_models.test_dialog.waiting_initial'),
                'waitingChecking' => __('admin.ai_models.test_dialog.waiting_checking'),
                'waitingExtended' => __('admin.ai_models.test_dialog.waiting_extended'),
                'workspaceReady' => __('admin.ai_models.test_dialog.workspace_ready'),
                'workspaceBasic' => __('admin.ai_models.test_dialog.workspace_basic'),
                'chat' => __('admin.ai_models.test_dialog.chat_type'),
                'embedding' => __('admin.ai_models.test_dialog.embedding_type'),
                'milliseconds' => __('admin.ai_models.test_dialog.milliseconds', ['duration' => '__DURATION__']),
                'unknown' => __('admin.ai_models.test_dialog.unknown'),
            ],
            'clientDiagnoses' => [
                'session_expired' => __('admin.ai_models.test_dialog.client_diagnosis.session_expired'),
                'web_rate_limited' => __('admin.ai_models.test_dialog.client_diagnosis.web_rate_limited'),
                'invalid_json' => __('admin.ai_models.test_dialog.client_diagnosis.invalid_json'),
                'network_failed' => __('admin.ai_models.test_dialog.client_diagnosis.network_failed'),
                'client_timeout' => __('admin.ai_models.test_dialog.client_diagnosis.client_timeout'),
                'unexpected_error' => __('admin.ai_models.diagnosis.unexpected_error'),
            ],
            ];
        @endphp
        <script type="application/json" data-ai-model-test-copy>@json($aiModelTestCopy, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)</script>
    </div>

@endsection
