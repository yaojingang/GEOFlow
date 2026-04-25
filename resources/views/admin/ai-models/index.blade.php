@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.ai.configurator') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.ai_models.page_title') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_models.page_subtitle') }}</p>
                </div>
            </div>
            <button type="button" onclick="showCreateModelModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.ai_models.create') }}
            </button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
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
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_models.list_title') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_models.list_desc') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
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
                                <button type="button" onclick="showCreateModelModal()" class="mt-2 text-blue-600 hover:text-blue-800">
                                    {{ __('admin.ai_models.add_first') }}
                                </button>
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
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                    <button type="button" onclick='editModel(@json($model, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP))' class="text-blue-600 hover:text-blue-900">{{ __('admin.ai_models.edit') }}</button>
                                    <button type="button" onclick="deleteModel({{ (int) $model['id'] }}, @js($model['name']))" class="text-red-600 hover:text-red-900">{{ __('admin.ai_models.delete') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="modelModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900" id="modalTitle">{{ __('admin.ai_models.modal_create') }}</h3>
                    <button type="button" onclick="closeModelModal()" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>

                <form id="modelForm" method="POST" action="{{ route('admin.ai-models.store') }}" class="space-y-6">
                    @csrf
                    <input type="hidden" name="_method" id="formMethod" value="POST">
                    <input type="hidden" name="id" id="modelId" value="">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.ai_models.quick_chat') }}</label>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" onclick="fillPreset('minimax')" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">MiniMax</button>
                            <button type="button" onclick="fillPreset('minimax_highspeed')" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">MiniMax Highspeed</button>
                            <button type="button" onclick="fillPreset('openai')" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">OpenAI</button>
                            <button type="button" onclick="fillPreset('deepseek')" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">DeepSeek</button>
                            <button type="button" onclick="fillPreset('zhipu')" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">Zhipu GLM</button>
                            <button type="button" onclick="fillPreset('volcengine_ark')" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">Volcengine Ark</button>
                        </div>
                        <label class="block text-sm font-medium text-gray-700 mt-4 mb-2">{{ __('admin.ai_models.quick_embedding') }}</label>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" onclick="fillPreset('openai_embedding')" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">OpenAI Embedding</button>
                            <button type="button" onclick="fillPreset('zhipu_embedding')" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">Zhipu Embedding</button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.ai_models.quick_help') }}</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_name') }}</label>
                            <input type="text" name="name" id="name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_name') }}">
                        </div>
                        <div>
                            <label for="version" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_version') }}</label>
                            <input type="text" name="version" id="version" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_version') }}">
                        </div>
                    </div>

                    <div>
                        <label for="model_type" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_type') }}</label>
                        <select name="model_type" id="model_type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="chat">{{ __('admin.ai_models.type_chat_option') }}</option>
                            <option value="embedding">{{ __('admin.ai_models.type_embedding_option') }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.ai_models.type_help') }}</p>
                    </div>

                    <div>
                        <label for="model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_model_id') }}</label>
                        <input type="text" name="model_id" id="model_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_model_id') }}">
                    </div>

                    <div>
                        <label for="api_key" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_api_key') }}</label>
                        <input type="password" name="api_key" id="api_key" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_api_key') }}">
                        <p id="apiKeyHelp" class="mt-1 text-xs text-gray-500">{{ __('admin.ai_models.api_key_help_create') }}</p>
                    </div>

                    <div>
                        <label for="api_url" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_api_url') }}</label>
                        <input type="url" name="api_url" id="api_url" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" value="https://api.deepseek.com" placeholder="{{ __('admin.ai_models.placeholder_api_url') }}">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.ai_models.api_url_help') }}</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="failover_priority" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_failover_priority') }}</label>
                            <input type="number" name="failover_priority" id="failover_priority" min="1" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" value="100">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.ai_models.failover_priority_help') }}</p>
                        </div>
                        <div>
                            <label for="daily_limit" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_daily_limit') }}</label>
                            <input type="number" name="daily_limit" id="daily_limit" min="0" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="0">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.ai_models.limit_help') }}</p>
                        </div>
                        <div id="statusField" class="hidden">
                            <label for="status" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_status') }}</label>
                            <select name="status" id="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="active">{{ __('admin.ai_models.status_active') }}</option>
                                <option value="inactive">{{ __('admin.ai_models.status_inactive') }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closeModelModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            {{ __('admin.button.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const AI_MODELS_I18N = {
            modalCreate: @json(__('admin.ai_models.modal_create')),
            modalEdit: @json(__('admin.ai_models.modal_edit')),
            apiKeyPlaceholder: @json(__('admin.ai_models.placeholder_api_key')),
            apiKeyPlaceholderKeep: @json(__('admin.ai_models.placeholder_api_key_keep')),
            apiKeyHelpCreate: @json(__('admin.ai_models.api_key_help_create')),
            apiKeyHelpEdit: @json(__('admin.ai_models.api_key_help_edit')),
            confirmDelete: @json(__('admin.ai_models.confirm_delete', ['name' => '__NAME__'])),
        };
        const UPDATE_URL_TEMPLATE = @json(route('admin.ai-models.update', ['modelId' => '__MODEL_ID__']));
        const DELETE_URL_TEMPLATE = @json(route('admin.ai-models.delete', ['modelId' => '__MODEL_ID__']));

        const PROVIDER_PRESETS = {
            minimax: {name: 'MiniMax M2.7', version: 'M2.7', model_id: 'MiniMax-M2.7', api_url: 'https://api.minimax.io', model_type: 'chat'},
            minimax_highspeed: {name: 'MiniMax M2.7 Highspeed', version: 'M2.7', model_id: 'MiniMax-M2.7-highspeed', api_url: 'https://api.minimax.io', model_type: 'chat'},
            openai: {name: 'GPT-4o', version: '', model_id: 'gpt-4o', api_url: 'https://api.openai.com', model_type: 'chat'},
            deepseek: {name: 'DeepSeek Chat', version: '', model_id: 'deepseek-chat', api_url: 'https://api.deepseek.com', model_type: 'chat'},
            zhipu: {name: '智谱 GLM-4.6', version: 'v4', model_id: 'glm-4.6', api_url: 'https://open.bigmodel.cn/api/paas/v4', model_type: 'chat'},
            volcengine_ark: {name: '火山方舟 Chat', version: 'v3', model_id: '', api_url: 'https://ark.cn-beijing.volces.com/api/v3', model_type: 'chat'},
            openai_embedding: {name: 'OpenAI Embedding 3 Small', version: '', model_id: 'text-embedding-3-small', api_url: 'https://api.openai.com', model_type: 'embedding'},
            zhipu_embedding: {name: '智谱 Embedding-3', version: 'v4', model_id: 'embedding-3', api_url: 'https://open.bigmodel.cn/api/paas/v4', model_type: 'embedding'},
        };

        function showCreateModelModal() {
            document.getElementById('modalTitle').textContent = AI_MODELS_I18N.modalCreate;
            document.getElementById('modelForm').action = @json(route('admin.ai-models.store'));
            document.getElementById('formMethod').value = 'POST';
            document.getElementById('modelId').value = '';
            document.getElementById('statusField').classList.add('hidden');
            document.getElementById('modelForm').reset();
            document.getElementById('model_type').value = 'chat';
            document.getElementById('api_key').required = true;
            document.getElementById('api_key').placeholder = AI_MODELS_I18N.apiKeyPlaceholder;
            document.getElementById('apiKeyHelp').textContent = AI_MODELS_I18N.apiKeyHelpCreate;
            document.getElementById('api_url').value = 'https://api.deepseek.com';
            document.getElementById('failover_priority').value = 100;
            document.getElementById('modelModal').classList.remove('hidden');
        }

        function editModel(model) {
            document.getElementById('modalTitle').textContent = AI_MODELS_I18N.modalEdit;
            document.getElementById('modelForm').action = UPDATE_URL_TEMPLATE.replace('__MODEL_ID__', String(model.id));
            document.getElementById('formMethod').value = 'PUT';
            document.getElementById('modelId').value = model.id;
            document.getElementById('name').value = model.name;
            document.getElementById('version').value = model.version || '';
            document.getElementById('model_id').value = model.model_id;
            document.getElementById('model_type').value = model.model_type || 'chat';
            document.getElementById('api_key').value = '';
            document.getElementById('api_key').required = false;
            document.getElementById('api_key').placeholder = AI_MODELS_I18N.apiKeyPlaceholderKeep;
            document.getElementById('apiKeyHelp').textContent = AI_MODELS_I18N.apiKeyHelpEdit;
            document.getElementById('api_url').value = model.api_url || '';
            document.getElementById('failover_priority').value = model.failover_priority || 100;
            document.getElementById('daily_limit').value = model.daily_limit || 0;
            document.getElementById('status').value = model.status || 'active';
            document.getElementById('statusField').classList.remove('hidden');
            document.getElementById('modelModal').classList.remove('hidden');
        }

        function closeModelModal() {
            document.getElementById('modelModal').classList.add('hidden');
        }

        function deleteModel(id, name) {
            if (!confirm(AI_MODELS_I18N.confirmDelete.replace('__NAME__', name))) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = DELETE_URL_TEMPLATE.replace('__MODEL_ID__', String(id));
            form.innerHTML = `
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function fillPreset(provider) {
            const preset = PROVIDER_PRESETS[provider];
            if (!preset) {
                return;
            }
            document.getElementById('name').value = preset.name;
            document.getElementById('version').value = preset.version;
            document.getElementById('model_id').value = preset.model_id;
            document.getElementById('api_url').value = preset.api_url;
            document.getElementById('model_type').value = preset.model_type;
        }

        window.addEventListener('click', function (event) {
            const modal = document.getElementById('modelModal');
            if (event.target === modal) {
                closeModelModal();
            }
        });
    </script>
@endpush
