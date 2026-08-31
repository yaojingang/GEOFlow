@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.ai_prompts.heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_prompts.subtitle') }}</p>
            </div>
            <a href="{{ route('admin.ai-prompts.create') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.ai_prompts.add') }}
            </a>
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
                <table class="min-w-full divide-y divide-gray-200" data-sticky-actions>
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
                                    <a href="{{ route('admin.ai-prompts.create') }}" class="mt-2 inline-flex min-h-10 items-center justify-center rounded-lg px-3 text-sm font-semibold text-green-700 transition-[background-color,transform] duration-150 hover:bg-green-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                                        {{ __('admin.ai_prompts.add_first') }}
                                    </a>
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
                                        <span @class([
                                            'inline-flex px-2 py-1 text-xs font-semibold rounded-full',
                                            'bg-green-100 text-green-800' => $prompt['type'] === 'content',
                                            'bg-blue-100 text-blue-800' => $prompt['type'] === 'quality_check',
                                        ])>
                                            {{ $prompt['type'] === 'quality_check' ? 'AI 质检方案' : __('admin.ai_prompts.type_content') }}
                                        </span>
                                        @if ($prompt['system_managed'])
                                            <span class="ml-1 inline-flex rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700">系统内置 · v{{ $prompt['system_version'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ __('admin.ai_prompts.task_usage', ['count' => $prompt['task_count']]) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $prompt['created_at'] ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                        <form method="POST" action="{{ route('admin.ai-prompts.copy', ['promptId' => $prompt['id']]) }}" class="inline-flex">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-10 items-center text-blue-600 transition-[color,transform] duration-150 hover:text-blue-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                                复制
                                            </button>
                                        </form>
                                        <a href="{{ route('admin.ai-prompts.edit', ['promptId' => $prompt['id']]) }}" class="inline-flex min-h-10 items-center text-green-600 transition-[color,transform] duration-150 hover:text-green-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                                            {{ __('admin.button.edit') }}
                                        </a>
                                        @if (! $prompt['system_managed'])
                                            <button type="button" data-ai-prompt-delete data-prompt-id="{{ (int) $prompt['id'] }}" data-prompt-name="{{ $prompt['name'] }}" class="min-h-10 text-red-600 transition-[color,transform] duration-150 hover:text-red-900 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">
                                                {{ __('admin.button.delete') }}
                                            </button>
                                        @endif
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
        const deleteActionTemplate = @json(route('admin.ai-prompts.delete', ['promptId' => '__ID__']));
        const deletePromptTemplate = @json(__('admin.ai_prompts.confirm_delete', ['name' => '__NAME__']));

        document.addEventListener('click', async (event) => {
            if (! (event.target instanceof Element)) return;
            const trigger = event.target.closest('[data-ai-prompt-delete]');
            if (! trigger) return;

            const id = trigger.dataset.promptId || '';
            const name = trigger.dataset.promptName || '';
            const message = deletePromptTemplate.replace('__NAME__', name);
            const confirmed = await window.AdminActionDialog?.confirm?.({
                title: message,
                message: @json(__('admin.action_dialog.generic_impact')),
                tone: 'danger',
                confirmLabel: @json(__('admin.button.delete')),
                opener: trigger,
            });
            if (confirmed !== true) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = deleteActionTemplate.replace('__ID__', String(id));
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = @json(csrf_token());
            form.appendChild(csrfInput);
            document.body.appendChild(form);
            form.submit();
        });

        document.addEventListener('DOMContentLoaded', function () {
            if (! window.GeoFlowAdminUi?.refreshIcons && typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
@endpush
