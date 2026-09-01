@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-0" data-materials-standalone data-library-detail-actions>
        <header class="mb-6 flex flex-col gap-5 sm:mb-8 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 items-start gap-3 sm:gap-4">
                <a href="{{ route('admin.title-libraries.index') }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-gray-400 transition-[background-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-white [@media(hover:hover)]:hover:text-gray-700 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div class="min-w-0">
                    <h1 class="break-words text-2xl font-bold text-gray-900">{{ $library->name }}</h1>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.title_detail.subtitle') }}</p>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2 lg:justify-end">
                <a href="{{ route('admin.title-libraries.edit', ['libraryId' => (int) $library->id, 'context' => 'detail']) }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-gray-400 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                    <i data-lucide="pencil" class="h-4 w-4"></i>
                    {{ __('admin.button.edit') }}
                </a>
                <a href="{{ route('admin.title-libraries.import.create', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm font-semibold text-green-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-green-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                    <i data-lucide="upload" class="h-4 w-4"></i>
                    {{ __('admin.title_detail.import_batch') }}
                </a>
                <a href="{{ route('admin.title-libraries.titles.create', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-gray-400 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                    <i data-lucide="plus" class="h-4 w-4"></i>
                    {{ __('admin.title_detail.add_title') }}
                </a>
                <a href="{{ route('admin.title-libraries.ai-generate', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-green-600 bg-green-600 px-4 py-2 text-sm font-semibold text-white transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-green-700 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                    <i data-lucide="zap" class="h-4 w-4"></i>
                    {{ __('admin.title_detail.ai_generate') }}
                </a>
            </div>
        </header>

        <div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:gap-6 [&>*:last-child]:col-span-2 md:[&>*:last-child]:col-span-1">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="list" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.title_detail.total_titles') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $titles->total() }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="calendar" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.title_detail.created_date') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ optional($library->created_at)->format('Y-m-d') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.title_detail.usage_total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $usageTotal }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($generationRun)
            <section
                class="mb-8 overflow-hidden rounded-lg border border-purple-200 bg-white shadow"
                data-title-generation-progress
                data-active="{{ $generationRun->isActive() ? 'true' : 'false' }}"
                data-status-url="{{ route('admin.title-libraries.ai-generate.status', ['libraryId' => (int) $library->id, 'runId' => (int) $generationRun->id]) }}"
                data-status-queued="{{ __('admin.title_detail.generation.status.queued') }}"
                data-status-running="{{ __('admin.title_detail.generation.status.running') }}"
                data-status-completed="{{ __('admin.title_detail.generation.status.completed') }}"
                data-status-partial="{{ __('admin.title_detail.generation.status.partial') }}"
                data-status-failed="{{ __('admin.title_detail.generation.status.failed') }}"
                data-status-cancelled="{{ __('admin.title_detail.generation.status.cancelled') }}"
                data-load-unavailable="{{ __('admin.title_detail.generation.load_unavailable') }}"
                data-poll-unavailable="{{ __('admin.title_detail.generation.poll_unavailable') }}"
                data-session-expired="{{ __('admin.title_detail.generation.session_expired') }}"
                aria-busy="{{ $generationRun->isActive() ? 'true' : 'false' }}"
            >
                <div class="flex flex-col gap-3 border-b border-purple-100 bg-purple-50 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-purple-950">{{ __('admin.title_detail.generation.title') }}</h2>
                        <p class="mt-1 text-sm text-purple-800">{{ __('admin.title_detail.generation.description') }}</p>
                    </div>
                    <span class="inline-flex w-fit items-center rounded-full bg-white px-3 py-1 text-xs font-semibold text-purple-700 shadow-sm" role="status" aria-live="polite" aria-atomic="true" data-generation-status>
                        {{ __('admin.title_detail.generation.status.'.(string) $generationRun->status) }}
                    </span>
                </div>
                <div class="px-6 py-5">
                    <div class="flex items-center gap-3">
                        <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-200">
                            <div
                                class="h-full rounded-full bg-purple-600 transition-[width] duration-300"
                                style="width: {{ $generationRun->progressPercent() }}%"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="{{ $generationRun->progressPercent() }}"
                                data-generation-progress-bar
                            ></div>
                        </div>
                        <span class="w-12 text-right text-sm font-semibold text-gray-700" data-generation-progress-label>{{ $generationRun->progressPercent() }}%</span>
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-5">
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('admin.title_detail.generation.target') }}</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900" data-generation-requested-count>{{ number_format((int) $generationRun->requested_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('admin.title_detail.generation.saved') }}</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900" data-generation-saved-count>{{ number_format((int) $generationRun->saved_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('admin.title_detail.generation.generated') }}</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900" data-generation-generated-count>{{ number_format((int) $generationRun->generated_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('admin.title_detail.generation.duplicates') }}</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900" data-generation-duplicate-count>{{ number_format((int) $generationRun->duplicate_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('admin.title_detail.generation.batches') }}</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900" data-generation-batch-count>{{ number_format((int) $generationRun->batch_count) }}</dd>
                        </div>
                    </dl>
                    <p class="{{ $generationStatus['notice'] ? '' : 'hidden' }} mt-4 rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-700" role="status" aria-live="polite" aria-atomic="true" data-generation-notice>{{ $generationStatus['notice'] }}</p>
                    <p class="{{ $generationStatus['last_error'] ? '' : 'hidden' }} mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700" role="alert" aria-live="assertive" aria-atomic="true" data-generation-error>{{ $generationStatus['last_error'] }}</p>
                    <div class="{{ $generationRun->isRetryable() ? '' : 'hidden' }} mt-4" data-generation-retry>
                        <form method="POST" action="{{ route('admin.title-libraries.ai-generate.retry', ['libraryId' => (int) $library->id, 'runId' => (int) $generationRun->id]) }}">
                            @csrf
                            <button type="submit" class="inline-flex min-h-10 items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                                <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.title_detail.generation.retry') }}
                            </button>
                        </form>
                    </div>
                    <div class="{{ $generationRun->isActive() ? '' : 'hidden' }} mt-4" data-generation-cancel>
                        <form method="POST" action="{{ route('admin.title-libraries.ai-generate.cancel', ['libraryId' => (int) $library->id, 'runId' => (int) $generationRun->id]) }}" data-library-confirm-form data-admin-confirm-form data-admin-confirm-tone="warning" data-admin-confirm-title="{{ __('admin.title_detail.generation.cancel_confirm') }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.title_detail.generation.cancel') }}">
                            @csrf
                            <button type="submit" disabled aria-disabled="true" data-library-detail-destructive-submit class="inline-flex min-h-10 items-center rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-red-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100">
                                <i data-lucide="square" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.title_detail.generation.cancel') }}
                            </button>
                        </form>
                    </div>
                </div>
            </section>
        @endif

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.title_detail.list_title') }}</h3>
            </div>

            @if ($titles->isEmpty())
                <div class="px-6 py-8 text-center">
                    <i data-lucide="list" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.title_detail.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ __('admin.title_detail.empty_desc') }}</p>
                    <div class="flex justify-center space-x-2">
                        <a href="{{ route('admin.title-libraries.titles.create', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.title_detail.add_title') }}
                        </a>
                        <a href="{{ route('admin.title-libraries.import.create', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.title_detail.import_batch') }}
                        </a>
                    </div>
                </div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($titles as $title)
                        <div class="px-6 py-4">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900 break-all">{{ $title->title }}</h4>
                                        @if ((bool) $title->is_ai_generated)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                <i data-lucide="zap" class="w-3 h-3 mr-1"></i>
                                                {{ __('admin.title_detail.ai_badge') }}
                                            </span>
                                        @endif
                                        @if ((string) ($title->keyword ?? '') !== '')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                                {{ $title->keyword }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span>{{ __('admin.title_detail.usage_count', ['count' => (int) ($title->used_count ?? 0)]) }}</span>
                                        <span>{{ __('admin.title_detail.created_at', ['value' => optional($title->created_at)->format('Y-m-d H:i') ?? '-']) }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('admin.title-libraries.titles.delete', ['libraryId' => (int) $library->id]) }}" data-material-delete-form data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.title_detail.confirm_delete', ['name' => $title->title]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.button.delete') }}">
                                        @csrf
                                        <input type="hidden" name="title_ids[]" value="{{ (int) $title->id }}">
                                        <button type="submit" disabled aria-disabled="true" data-material-delete-submit class="inline-flex min-h-10 items-center rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-red-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100">
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($titles->lastPage() > 1)
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                {{ __('admin.title_detail.pagination', ['start' => $titles->firstItem(), 'end' => $titles->lastItem(), 'total' => $titles->total()]) }}
                            </div>
                            <div>
                                {{ $titles->links() }}
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

@endsection
