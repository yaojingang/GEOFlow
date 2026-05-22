@if ($jobs->isEmpty())
    <div class="px-6 py-8 text-sm text-gray-500">{{ __('admin.distribution.empty_jobs') }}</div>
@else
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.article') }}</th>
                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.channel') }}</th>
                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.action') }}</th>
                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.remote_url') }}</th>
                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.attempt_count') }}</th>
                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.last_error') }}</th>
                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.common.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @foreach ($jobs as $job)
                    @php($statusClasses = [
                        'queued' => 'bg-blue-100 text-blue-800',
                        'sending' => 'bg-amber-100 text-amber-800',
                        'synced' => 'bg-green-100 text-green-800',
                        'failed' => 'bg-red-100 text-red-800',
                    ])
                    @php($jobActionKey = 'admin.distribution.action.'.(string) $job->action)
                    @php($jobActionLabel = trans()->has($jobActionKey) ? __($jobActionKey) : (string) $job->action)
                    @php($jobStatusKey = 'admin.distribution.job_status.'.(string) $job->status)
                    @php($jobStatusLabel = trans()->has($jobStatusKey) ? __($jobStatusKey) : (string) $job->status)
                    @php($isDeletedRemoteCopy = (string) $job->action === 'delete' && (string) $job->status === 'synced')
                    <tr>
                        <td class="min-w-[28rem] max-w-[42rem] break-words px-6 py-4 text-sm font-medium text-gray-900">{{ $job->article?->title ?? __('admin.common.none') }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-600">{{ $job->channel?->name ?? __('admin.common.none') }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-600">{{ $jobActionLabel }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-sm">
                            <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $statusClasses[$job->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $jobStatusLabel }}</span>
                        </td>
                        <td class="min-w-[18rem] max-w-[30rem] px-4 py-4 text-sm">
                            @if ($job->remote_url)
                                <a href="{{ $job->remote_url }}" target="_blank" rel="noopener noreferrer" class="break-all text-blue-600 hover:text-blue-800">{{ $job->remote_url }}</a>
                            @else
                                <span class="text-gray-400">{{ __('admin.common.none') }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-600">{{ (int) $job->attempt_count }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-600">{{ $job->last_error_message ?: __('admin.common.none') }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-600" data-distribution-delete-status>
                            <div class="flex flex-nowrap items-center gap-3">
                            @if ($isDeletedRemoteCopy)
                                <span class="text-gray-400">{{ __('admin.distribution.job_state.remote_copy_deleted') }}</span>
                            @elseif ($job->article)
                                <a href="{{ route('admin.distribution.article.edit', ['distributionId' => (int) $job->id]) }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.distribution.button.edit_remote_article') }}</a>
                                <form method="POST" action="{{ route('admin.distribution.article.delete', ['distributionId' => (int) $job->id]) }}" data-distribution-delete-form data-confirm-message="{{ __('admin.articles.confirm.delete_title') }}" data-deleting-label="{{ __('admin.distribution.job_state.remote_copy_deleting') }}" data-deleted-label="{{ __('admin.distribution.job_state.remote_copy_deleted') }}">
                                    @csrf
                                    <button type="submit" class="text-red-600 hover:text-red-800">{{ __('admin.distribution.button.delete_remote_article') }}</button>
                                </form>
                            @endif
                            @if ($job->status === 'failed')
                                <form method="POST" action="{{ route('admin.distribution.retry', ['distributionId' => (int) $job->id]) }}">
                                    @csrf
                                    <button type="submit" class="text-blue-600 hover:text-blue-800">{{ __('admin.distribution.button.retry') }}</button>
                                </form>
                            @endif
                            @if (! $job->article && $job->status !== 'failed')
                                <span class="text-gray-400">{{ __('admin.common.none') }}</span>
                            @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if (method_exists($jobs, 'links') && ($jobs->lastPage() ?? 1) > 1)
        <div class="border-t border-gray-200 px-6 py-4">
            {{ $jobs->links() }}
        </div>
    @endif
@endif

@once
    @push('scripts')
        <script>
            document.addEventListener('submit', async (event) => {
                const form = event.target.closest('[data-distribution-delete-form]');
                if (! form) return;

                event.preventDefault();

                const confirmMessage = form.dataset.confirmMessage || '';
                if (confirmMessage && ! window.confirm(confirmMessage)) return;

                const button = form.querySelector('button[type="submit"]');
                const statusCell = form.closest('[data-distribution-delete-status]');
                const deletedLabel = form.dataset.deletedLabel || '';
                const deletingLabel = form.dataset.deletingLabel || '';
                if (button) {
                    button.disabled = true;
                    button.classList.add('opacity-50', 'cursor-not-allowed');
                    if (deletingLabel) button.textContent = deletingLabel;
                }

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: new FormData(form),
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (! response.ok || ! payload.ok) {
                        throw new Error(payload.message || 'delete failed');
                    }

                    if (statusCell && deletedLabel) {
                        statusCell.innerHTML = `<span class="text-gray-400">${deletedLabel}</span>`;
                    }
                } catch (error) {
                    if (button) {
                        button.disabled = false;
                        button.classList.remove('opacity-50', 'cursor-not-allowed');
                        button.textContent = @js(__('admin.distribution.button.delete_remote_article'));
                    }
                    console.error(error);
                }
            });
        </script>
    @endpush
@endonce
