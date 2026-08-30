@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.ai_models.page_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_models.page_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.ai-models.create') }}" class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.ai_models.create') }}
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
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

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_models.list_title') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_models.list_desc') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200" data-sticky-actions>
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.info') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.version') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.usage') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.limit') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.status') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_models.column.actions') }}</th>
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
                                <td class="px-6 py-4">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <div class="text-sm font-medium text-gray-900">{{ $model['name'] }}</div>
                                            <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ $model['model_type'] === 'embedding' ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800' }}">
                                                {{ $model['model_type'] === 'embedding' ? __('admin.ai_models.type_embedding_option') : __('admin.ai_models.chat') }}
                                            </span>
                                            @if ($model['is_default_embedding'])
                                                <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800">{{ __('admin.ai_models.embedding_default') }}</span>
                                            @endif
                                        </div>
                                        <div class="text-sm text-gray-500">{{ $model['model_id'] }}</div>
                                        <div class="text-xs text-gray-400">{{ __('admin.ai_models.api_key_mask') }}: {{ $model['masked_api_key'] }}</div>
                                        <div class="text-xs text-gray-400">{{ __('admin.ai_models.failover_priority_label', ['priority' => (int) $model['failover_priority']]) }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
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
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex items-center gap-3">
                                        <button type="button" onclick="testModelConnection({{ (int) $model['id'] }}, this)" class="min-h-10 text-emerald-600 transition-[color,transform] duration-150 hover:text-emerald-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">{{ __('admin.ai_models.test') }}</button>
                                        <a href="{{ route('admin.ai-models.edit', ['modelId' => $model['id']]) }}" class="inline-flex min-h-10 items-center text-blue-600 transition-[color,transform] duration-150 hover:text-blue-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">{{ __('admin.ai_models.edit') }}</a>
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
                                    <div id="model-test-result-{{ (int) $model['id'] }}" class="mt-2 text-xs whitespace-normal max-w-xs"></div>
                                </td>
                            </tr>
                        @endforeach
                    @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        const AI_MODELS_I18N = {
            test: @json(__('admin.ai_models.test')),
            testing: @json(__('admin.ai_models.testing')),
            testSuccessPrefix: @json(__('admin.ai_models.test_success_prefix')),
            testFailedPrefix: @json(__('admin.ai_models.test_failed_prefix')),
            testNetworkError: @json(__('admin.ai_models.test_network_error')),
            readinessTitle: @json(__('admin.ai_models.readiness_title')),
            readinessReady: @json(__('admin.ai_models.readiness_status.ready')),
        };
        const TEST_URL_TEMPLATE = @json(\App\Support\AdminWeb::routePath('admin.ai-models.test', ['modelId' => '__MODEL_ID__']));

        async function testModelConnection(id, button) {
            const resultEl = document.getElementById(`model-test-result-${id}`);
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = AI_MODELS_I18N.testing;
            button.classList.add('opacity-60', 'cursor-not-allowed');
            setModelTestResult(resultEl, 'neutral', AI_MODELS_I18N.testing);

            try {
                const response = await fetch(TEST_URL_TEMPLATE.replace('__MODEL_ID__', String(id)), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': @json(csrf_token()),
                    },
                    body: JSON.stringify({}),
                });
                const data = await response.json().catch(() => ({}));
                const message = data.message || (response.ok ? AI_MODELS_I18N.testSuccessPrefix : AI_MODELS_I18N.testFailedPrefix);
                const duration = data.meta && data.meta.duration_ms ? ` · ${data.meta.duration_ms}ms` : '';
                const readiness = data.meta && data.meta.readiness_status === 'ready'
                    ? ` · ${AI_MODELS_I18N.readinessTitle}: ${AI_MODELS_I18N.readinessReady}`
                    : '';
                setModelTestResult(
                    resultEl,
                    response.ok && data.success ? 'success' : 'failed',
                    `${response.ok && data.success ? AI_MODELS_I18N.testSuccessPrefix : AI_MODELS_I18N.testFailedPrefix}${message}${duration}${readiness}`
                );
            } catch (error) {
                setModelTestResult(resultEl, 'failed', AI_MODELS_I18N.testNetworkError);
            } finally {
                button.disabled = false;
                button.textContent = originalText;
                button.classList.remove('opacity-60', 'cursor-not-allowed');
            }
        }

        function setModelTestResult(element, state, message) {
            if (!element) {
                return;
            }
            const classes = {
                neutral: 'text-slate-500',
                success: 'text-emerald-700',
                failed: 'text-red-700',
            };
            element.className = `mt-2 text-xs whitespace-normal max-w-xs ${classes[state] || classes.neutral}`;
            element.textContent = message;
        }

    </script>
@endpush
