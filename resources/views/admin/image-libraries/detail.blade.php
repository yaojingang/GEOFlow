@extends('admin.layouts.app')

@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator<\App\Models\Image> $images */
    $formatSize = static function (int $bytes): string {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    };
    $urlLabel = __('admin.image_detail.url_label');
    if ($urlLabel === 'admin.image_detail.url_label') {
        $urlLabel = 'URL';
    }
@endphp

@section('content')
    <div class="px-4 sm:px-0" data-materials-standalone data-image-library-detail data-image-dimensions-label="{{ __('admin.image_detail.dimensions_label') }}" data-image-size-label="{{ __('admin.image_detail.size_label') }}">
        <div class="mb-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex min-w-0 items-start gap-4">
                    <a href="{{ route('admin.image-libraries.index') }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[background-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 [@media(hover:hover)]:hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </a>
                    <div class="min-w-0">
                        <h1 class="break-words text-2xl font-bold text-gray-900">{{ $library->name }}</h1>
                        <p class="mt-1 break-words text-sm text-gray-600">{{ $library->description !== '' ? $library->description : __('admin.common.none_desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.image-libraries.edit', ['libraryId' => (int) $library->id, 'context' => 'detail']) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        <i data-lucide="edit" class="w-4 h-4 mr-1"></i>
                        {{ __('admin.button.edit') }}
                    </a>
                    <a href="{{ route('admin.image-libraries.images.create', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                        <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.upload') }}
                    </a>
                </div>
            </div>
        </div>

        <div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4 lg:gap-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <i data-lucide="image" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.image_detail.total_images') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) $totalImages }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.common.usage') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) $usageTotal }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <i data-lucide="calendar" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.common.created_at') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ optional($library->created_at)->format('m-d') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <i data-lucide="clock" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.common.updated_at') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ optional($library->updated_at)->format('m-d') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <form method="GET" class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1">
                            <input type="text" name="search" value="{{ $search }}"
                                placeholder="{{ __('admin.image_detail.search_placeholder') }}"
                                class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm">
                        </div>
                        <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.search') }}
                        </button>
                        <a href="{{ route('admin.image-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.clear') }}
                        </a>
                    </form>
                    <div class="flex gap-2">
                        <button type="button" onclick="toggleBatchActions()" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.button.bulk_actions') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        {{ __('admin.image_detail.list_title') }}
                        <span class="text-sm text-gray-500">({{ __('admin.image_detail.total_images_count', ['count' => (int) $totalImages]) }})</span>
                    </h3>
                </div>
            </div>

            @if ($images->isEmpty())
                <div class="px-6 py-8 text-center">
                    <i data-lucide="image" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.image_detail.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ $search !== '' ? __('admin.image_detail.empty_search') : __('admin.image_detail.empty_desc') }}</p>
                    @if ($search === '')
                        <a href="{{ route('admin.image-libraries.images.create', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.upload') }}
                        </a>
                    @endif
                </div>
            @else
                <div id="batch-actions" class="hidden px-6 py-3 bg-gray-50 border-b border-gray-200">
                    <form method="POST" action="{{ route('admin.image-libraries.images.delete', ['libraryId' => (int) $library->id]) }}" id="batch-form">
                        @csrf
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm text-gray-600" id="selected-count-wrap">{{ __('admin.image_detail.selected_count', ['count' => 0]) }}</span>
                            <button type="submit" disabled aria-disabled="true" data-image-delete-submit class="inline-flex min-h-10 items-center justify-center rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-red-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100">
                                <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                {{ __('admin.image_detail.delete_selected') }}
                            </button>
                            <button type="button" onclick="toggleBatchActions()" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                {{ __('admin.button.cancel') }}
                            </button>
                        </div>
                    </form>
                </div>

                <div class="p-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                        @foreach ($images as $image)
                            @php
                                $imageUrl = \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl((string) ($image->file_path ?? ''));
                            @endphp
                            <div class="image-item relative overflow-hidden rounded-lg border-2 border-transparent transition-[border-color,transform] duration-150 [@media(hover:hover)]:hover:scale-[1.02] [@media(hover:hover)]:hover:border-blue-500" data-image-id="{{ (int) $image->id }}">
                                <input type="checkbox" form="batch-form" name="image_ids[]" value="{{ (int) $image->id }}" class="image-checkbox absolute top-2 left-2 z-10 hidden rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200/50">
                                <button
                                    type="button"
                                    aria-label="{{ __('admin.button.view') }}: {{ (string) ($image->original_name ?? '') }}"
                                    class="group relative block w-full overflow-hidden bg-gray-100 p-0 text-left transition-transform duration-150 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500"
                                    data-image-preview-trigger
                                    data-image-src="{{ $imageUrl }}"
                                    data-image-name="{{ (string) ($image->original_name ?? '') }}"
                                    data-image-dimensions="{{ (int) ($image->width ?? 0) }}x{{ (int) ($image->height ?? 0) }}"
                                    data-image-size="{{ $formatSize((int) ($image->file_size ?? 0)) }}"
                                    data-image-url="{{ $imageUrl }}"
                                >
                                    <img src="{{ $imageUrl }}" alt="" aria-hidden="true" class="pointer-events-none aspect-square w-full object-cover">
                                    <span class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center bg-black/70 px-2 text-white opacity-0 transition-opacity duration-150 group-focus-visible:opacity-100 [@media(hover:hover)]:group-hover:opacity-100">
                                        <span class="mb-2 break-all text-center text-xs">{{ (string) ($image->original_name ?? '') }}</span>
                                        <span class="text-xs text-gray-300">{{ (int) ($image->width ?? 0) }}x{{ (int) ($image->height ?? 0) }}</span>
                                        <span class="text-xs text-gray-300">{{ $formatSize((int) ($image->file_size ?? 0)) }}</span>
                                    </span>
                                </button>
                                <div class="relative z-10 border-t border-gray-100 bg-white p-2">
                                    <div class="text-[11px] font-medium text-gray-500">{{ $urlLabel }}</div>
                                    <a href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer" class="mt-1 block truncate text-xs text-blue-600 transition-colors [@media(hover:hover)]:hover:text-blue-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2" title="{{ $imageUrl }}" data-image-card-url>
                                        {{ $imageUrl }}
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($images->lastPage() > 1)
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                {{ __('admin.image_detail.pagination_summary', ['from' => $images->firstItem(), 'to' => $images->lastItem(), 'total' => $images->total()]) }}
                            </div>
                            <div>
                                {{ $images->links() }}
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <div class="gf-modal-backdrop" data-gf-modal="image-preview" hidden>
        <section id="image-modal" class="gf-modal gf-modal--community max-h-[calc(100vh-2rem)] overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="image-title">
            <header class="gf-modal__header">
                <h2 id="image-title" data-image-preview-title></h2>
                <button type="button" data-dialog-close aria-label="{{ __('admin.common.close') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-gray-400 transition-[background-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 [@media(hover:hover)]:hover:text-gray-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <i data-lucide="x" class="h-6 w-6"></i>
                </button>
            </header>
            <div class="gf-modal__body text-center">
                <img alt="" class="mx-auto max-h-96 max-w-full rounded-lg" data-image-preview-image>
                <div class="mt-4 text-sm text-gray-600" data-image-preview-info></div>
                <div class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-left">
                    <div class="text-xs font-medium text-gray-500">{{ $urlLabel }}</div>
                    <a href="#" target="_blank" rel="noopener noreferrer" class="mt-1 block break-all text-sm text-blue-600 transition-colors [@media(hover:hover)]:hover:text-blue-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2" data-image-preview-url></a>
                </div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        function toggleBatchActions() {
            const batchActions = document.getElementById('batch-actions');
            const checkboxes = document.querySelectorAll('.image-checkbox');
            const isHidden = batchActions.classList.contains('hidden');

            if (isHidden) {
                batchActions.classList.remove('hidden');
                checkboxes.forEach((checkbox) => checkbox.classList.remove('hidden'));
            } else {
                batchActions.classList.add('hidden');
                checkboxes.forEach((checkbox) => {
                    checkbox.classList.add('hidden');
                    checkbox.checked = false;
                });
                document.querySelectorAll('.image-item').forEach((item) => {
                    item.classList.remove('selected');
                });
                updateSelectedCount();
            }
        }

        function updateSelectedCount() {
            const selected = document.querySelectorAll('.image-checkbox:checked').length;
            const text = @json(__('admin.image_detail.selected_count', ['count' => '{count}'])).replace('{count}', String(selected));
            const countWrap = document.getElementById('selected-count-wrap');
            if (countWrap) {
                countWrap.textContent = text;
            }
        }

        document.querySelectorAll('.image-checkbox').forEach((checkbox) => {
            checkbox.addEventListener('change', function () {
                const imageItem = this.closest('.image-item');
                if (this.checked) {
                    imageItem?.classList.add('selected');
                } else {
                    imageItem?.classList.remove('selected');
                }
                updateSelectedCount();
            });
        });

        const batchForm = document.getElementById('batch-form');
        const batchDeleteSubmit = batchForm?.querySelector('[data-image-delete-submit]');
        if (batchForm && batchDeleteSubmit) {
            batchForm.addEventListener('submit', async function (event) {
                if (batchForm.dataset.imageDeleteConfirmed === 'true') {
                    delete batchForm.dataset.imageDeleteConfirmed;
                    return;
                }
                event.preventDefault();
                const selected = document.querySelectorAll('.image-checkbox:checked').length;
                if (selected === 0) {
                    window.AdminActionDialog?.notice?.({
                        tone: 'info',
                        title: @json(__('admin.action_dialog.info_title')),
                        message: @json(__('admin.image_detail.error.select_delete')),
                    });
                    return;
                }
                const title = @json(__('admin.image_detail.confirm_delete_selected_prefix')) + ' ' + selected + ' ' + @json(__('admin.image_detail.confirm_delete_selected_suffix'));
                const confirmed = await window.AdminActionDialog?.confirm?.({
                    title,
                    message: @json(__('admin.action_dialog.generic_impact')),
                    tone: 'danger',
                    confirmLabel: @json(__('admin.image_detail.delete_selected')),
                    opener: event.submitter,
                });
                if (confirmed !== true) return;
                batchForm.dataset.imageDeleteConfirmed = 'true';
                batchForm.requestSubmit(event.submitter instanceof HTMLButtonElement ? event.submitter : undefined);
            });
            batchDeleteSubmit.disabled = false;
            batchDeleteSubmit.removeAttribute('aria-disabled');
        }

    </script>
@endpush
