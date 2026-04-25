@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.ai.configurator') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.ai_prompts.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_prompts.subtitle') }}</p>
                </div>
            </div>
            <button type="button" onclick="showCreatePromptModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.ai_prompts.add') }}
            </button>
        </div>

        <div class="mb-6 rounded-md border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
            {!! __('admin.ai_prompts.help_banner', ['url' => route('admin.ai-special-prompts')]) !!}
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_prompts.list_title') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_prompts.list_subtitle') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_prompts.column_info') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_prompts.column_type') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_prompts.column_usage') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.ai_prompts.column_created_at') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @if (empty($prompts))
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                    <i data-lucide="message-square" class="w-8 h-8 mx-auto mb-2 text-gray-400"></i>
                                    <p>{{ __('admin.ai_prompts.empty') }}</p>
                                    <button type="button" onclick="showCreatePromptModal()" class="mt-2 text-green-600 hover:text-green-800">
                                        {{ __('admin.ai_prompts.add_first') }}
                                    </button>
                                </td>
                            </tr>
                        @else
                            @foreach ($prompts as $prompt)
                                <tr>
                                    <td class="px-6 py-4">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">{{ $prompt['name'] }}</div>
                                            <div class="text-sm text-gray-500 max-w-xs truncate">
                                                {{ \Illuminate\Support\Str::limit($prompt['content'], 100) }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            {{ __('admin.ai_prompts.type_content') }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ __('admin.ai_prompts.task_usage', ['count' => $prompt['task_count']]) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $prompt['created_at'] ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                        <button type="button" onclick='editPrompt(@json($prompt, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP))' class="text-green-600 hover:text-green-900">
                                            {{ __('admin.button.edit') }}
                                        </button>
                                        <button type="button" onclick="deletePrompt({{ (int) $prompt['id'] }}, @js($prompt['name']))" class="text-red-600 hover:text-red-900">
                                            {{ __('admin.button.delete') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="promptModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-10 mx-auto p-5 border w-11/12 md:w-4/5 lg:w-3/4 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900" id="promptModalTitle">{{ __('admin.ai_prompts.modal_create') }}</h3>
                    <button type="button" onclick="closePromptModal()" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>

                <form id="promptForm" method="POST" action="{{ route('admin.ai-prompts.store') }}" class="space-y-6">
                    @csrf
                    <input type="hidden" name="_method" id="promptFormMethod" value="POST">

                    <div>
                        <label for="prompt_name" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_prompts.field_name') }}</label>
                        <input type="text" name="name" id="prompt_name" required
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm"
                               placeholder="{{ __('admin.ai_prompts.placeholder_name') }}">
                    </div>

                    <div>
                        <label for="prompt_content" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_prompts.field_content') }}</label>
                        <textarea name="content" id="prompt_content" required rows="12"
                                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm"
                                  placeholder="{{ __('admin.ai_prompts.placeholder_content') }}"></textarea>

                        <div class="mt-2 p-3 bg-blue-50 border border-blue-200 rounded-md">
                            <h4 class="text-sm font-medium text-blue-800 mb-2">{{ __('admin.ai_prompts.variable_title') }}</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-xs text-blue-700">
                                <div>{!! __('admin.ai_prompts.variable_title_label') !!}</div>
                                <div>{!! __('admin.ai_prompts.variable_keyword_label') !!}</div>
                                <div>{!! __('admin.ai_prompts.variable_knowledge_label') !!}</div>
                            </div>
                            <p class="mt-2 text-xs text-blue-600">{!! __('admin.ai_prompts.variable_help') !!}</p>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closePromptModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
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
        const createPromptTitle = @json(__('admin.ai_prompts.modal_create'));
        const editPromptTitle = @json(__('admin.ai_prompts.modal_edit'));
        const createPromptAction = @json(route('admin.ai-prompts.store'));
        const updateActionTemplate = @json(route('admin.ai-prompts.update', ['promptId' => '__ID__']));
        const deleteActionTemplate = @json(route('admin.ai-prompts.delete', ['promptId' => '__ID__']));
        const deletePromptTemplate = @json(__('admin.ai_prompts.confirm_delete', ['name' => '__NAME__']));

        function showCreatePromptModal() {
            document.getElementById('promptModalTitle').textContent = createPromptTitle;
            document.getElementById('promptForm').action = createPromptAction;
            document.getElementById('promptFormMethod').value = 'POST';
            document.getElementById('prompt_name').value = '';
            document.getElementById('prompt_content').value = '';
            document.getElementById('promptModal').classList.remove('hidden');
        }

        function editPrompt(prompt) {
            document.getElementById('promptModalTitle').textContent = editPromptTitle;
            document.getElementById('promptForm').action = updateActionTemplate.replace('__ID__', String(prompt.id));
            document.getElementById('promptFormMethod').value = 'PUT';
            document.getElementById('prompt_name').value = prompt.name ?? '';
            document.getElementById('prompt_content').value = prompt.content ?? '';
            document.getElementById('promptModal').classList.remove('hidden');
        }

        function closePromptModal() {
            document.getElementById('promptModal').classList.add('hidden');
        }

        function deletePrompt(id, name) {
            const message = deletePromptTemplate.replace('__NAME__', name);
            if (! window.confirm(message)) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = deleteActionTemplate.replace('__ID__', String(id));
            form.innerHTML = `
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
@endpush

