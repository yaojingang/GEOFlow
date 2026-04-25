@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.materials.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.authors.page_title') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.authors.page_subtitle') }}</p>
                </div>
            </div>
            <button type="button" onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.authors.create') }}
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="users" class="h-6 w-6 text-indigo-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.authors.stats_total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_authors'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="user-check" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.authors.stats_active') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['active_authors'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.authors.stats_average') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (float) ($stats['avg_articles'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <form method="GET" class="flex items-center gap-4">
                    <div class="flex-1 min-w-0">
                        <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('admin.authors.search_placeholder') }}" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                    <button type="submit" class="inline-flex shrink-0 whitespace-nowrap items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                        <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.search') }}
                    </button>
                    <a href="{{ route('admin.authors.index') }}" class="inline-flex shrink-0 whitespace-nowrap items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.clear') }}
                    </a>
                </form>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ __('admin.authors.list_title') }}
                    <span class="text-sm text-gray-500">({{ (int) ($authorsPagination?->total() ?? 0) }})</span>
                </h3>
            </div>
            @if (empty($authors))
                <div class="px-6 py-8 text-center">
                    <i data-lucide="user-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.authors.empty_title') }}</h3>
                    <p class="text-gray-500 mb-4">{{ $search !== '' ? __('admin.authors.empty_search') : __('admin.authors.empty_desc') }}</p>
                    @if ($search === '')
                        <button type="button" onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.authors.create') }}
                        </button>
                    @endif
                </div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($authors as $author)
                        <div class="px-6 py-6">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-4">
                                    <div class="flex-shrink-0">
                                        <div class="w-12 h-12 bg-indigo-100 rounded-full flex items-center justify-center">
                                            <i data-lucide="user" class="w-6 h-6 text-indigo-600"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="text-lg font-medium text-gray-900">{{ $author['name'] }}</h4>
                                        @if ($author['email'] !== '')
                                            <p class="text-sm text-gray-600">{{ $author['email'] }}</p>
                                        @endif
                                        @if ($author['bio'] !== '')
                                            <p class="text-sm text-gray-500 mt-1">
                                                {{ \Illuminate\Support\Str::limit($author['bio'], 100, '...') }}
                                            </p>
                                        @endif
                                        <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                            <span>{{ __('admin.authors.article_count', ['count' => (int) $author['article_count']]) }}</span>
                                            <span>{{ __('admin.authors.published_count', ['count' => (int) $author['published_count']]) }}</span>
                                            @if ((int) $author['trashed_count'] > 0)
                                                <span>{{ __('admin.authors.trashed_count', ['count' => (int) $author['trashed_count']]) }}</span>
                                            @endif
                                            <span>{{ __('admin.authors.created_prefix', ['date' => $author['created_at'] ? \Illuminate\Support\Carbon::parse($author['created_at'])->format('Y-m-d') : '-']) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2">
                                    <button
                                        type="button"
                                        onclick="showEditModal(this)"
                                        data-author-id="{{ (int) $author['id'] }}"
                                        data-author-name="{{ $author['name'] }}"
                                        data-author-email="{{ $author['email'] }}"
                                        data-author-bio="{{ $author['bio'] }}"
                                        data-author-website="{{ $author['website'] }}"
                                        data-author-social-links="{{ $author['social_links'] }}"
                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50"
                                    >
                                        <i data-lucide="pencil" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.authors.edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        onclick="deleteAuthor(this)"
                                        data-author-id="{{ (int) $author['id'] }}"
                                        data-author-name="{{ $author['name'] }}"
                                        data-trashed-count="{{ (int) $author['trashed_count'] }}"
                                        class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700"
                                    >
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.authors.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if (($authorsPagination?->lastPage() ?? 1) > 1)
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                {{ __('admin.articles.pagination.summary', [
                                    'from' => (string) ($authorsPagination?->firstItem() ?? 0),
                                    'to' => (string) ($authorsPagination?->lastItem() ?? 0),
                                    'total' => (string) ($authorsPagination?->total() ?? 0),
                                ]) }}
                            </div>
                            <div class="flex space-x-1">
                                @if (($authorsPagination?->currentPage() ?? 1) > 1)
                                    <a href="{{ $authorsPagination?->url(($authorsPagination?->currentPage() ?? 2) - 1) }}" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        {{ __('admin.articles.pagination.prev') }}
                                    </a>
                                @endif

                                @php
                                    $currentPage = (int) ($authorsPagination?->currentPage() ?? 1);
                                    $lastPage = (int) ($authorsPagination?->lastPage() ?? 1);
                                @endphp
                                @for ($i = max(1, $currentPage - 2); $i <= min($lastPage, $currentPage + 2); $i++)
                                    <a href="{{ $authorsPagination?->url($i) }}"
                                       class="px-3 py-2 text-sm font-medium {{ $i === $currentPage ? 'text-indigo-600 bg-indigo-50 border-indigo-500' : 'text-gray-500 bg-white border-gray-300' }} border rounded-md hover:bg-gray-50">
                                        {{ $i }}
                                    </a>
                                @endfor

                                @if (($authorsPagination?->currentPage() ?? 1) < ($authorsPagination?->lastPage() ?? 1))
                                    <a href="{{ $authorsPagination?->url(($authorsPagination?->currentPage() ?? 0) + 1) }}" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        {{ __('admin.articles.pagination.next') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <div id="create-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.authors.modal_create') }}</h3>
                <form method="POST" action="{{ route('admin.authors.store') }}">
                    @csrf

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_name') }}</label>
                            <input type="text" name="name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="{{ __('admin.authors.placeholder_name') }}">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_email') }}</label>
                            <input type="email" name="email" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="{{ __('admin.authors.placeholder_email') }}">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_bio') }}</label>
                            <textarea name="bio" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="{{ __('admin.authors.placeholder_bio') }}"></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_website') }}</label>
                            <input type="url" name="website" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="https://example.com">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_social') }}</label>
                            <textarea name="social_links" rows="2" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="{{ __('admin.authors.placeholder_social') }}"></textarea>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                            {{ __('admin.authors.save_create') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="edit-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.authors.modal_edit') }}</h3>
                <form method="POST" id="edit-form">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="author_id" id="edit-author-id" value="">

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_name') }}</label>
                            <input type="text" name="name" id="edit-author-name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="{{ __('admin.authors.placeholder_name') }}">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_email') }}</label>
                            <input type="email" name="email" id="edit-author-email" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="{{ __('admin.authors.placeholder_email') }}">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_bio') }}</label>
                            <textarea name="bio" id="edit-author-bio" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="{{ __('admin.authors.placeholder_bio') }}"></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_website') }}</label>
                            <input type="url" name="website" id="edit-author-website" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="https://example.com">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_social') }}</label>
                            <textarea name="social_links" id="edit-author-social-links" rows="2" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="{{ __('admin.authors.placeholder_social') }}"></textarea>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                            {{ __('admin.authors.save_edit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const AUTHORS_I18N = {
            confirmDelete: @json(__('admin.authors.confirm_delete', ['name' => '__NAME__'])),
            confirmDeleteTrashed: @json(__('admin.authors.confirm_delete_trashed', ['name' => '__NAME__', 'count' => '__COUNT__'])),
        };
        const AUTHOR_UPDATE_URL_TEMPLATE = @json(route('admin.authors.update', ['authorId' => '__AUTHOR_ID__']));
        const AUTHOR_DELETE_URL_TEMPLATE = @json(route('admin.authors.delete', ['authorId' => '__AUTHOR_ID__']));

        function showCreateModal() {
            document.getElementById('create-modal').classList.remove('hidden');
        }

        function hideCreateModal() {
            document.getElementById('create-modal').classList.add('hidden');
        }

        function showEditModal(button) {
            document.getElementById('edit-author-id').value = button.dataset.authorId || '';
            document.getElementById('edit-author-name').value = button.dataset.authorName || '';
            document.getElementById('edit-author-email').value = button.dataset.authorEmail || '';
            document.getElementById('edit-author-bio').value = button.dataset.authorBio || '';
            document.getElementById('edit-author-website').value = button.dataset.authorWebsite || '';
            document.getElementById('edit-author-social-links').value = button.dataset.authorSocialLinks || '';

            const editForm = document.getElementById('edit-form');
            editForm.action = AUTHOR_UPDATE_URL_TEMPLATE.replace('__AUTHOR_ID__', button.dataset.authorId || '');
            document.getElementById('edit-modal').classList.remove('hidden');
        }

        function hideEditModal() {
            document.getElementById('edit-modal').classList.add('hidden');
        }

        function deleteAuthor(button) {
            const authorId = button.dataset.authorId || '';
            const authorName = button.dataset.authorName || '';
            const trashedCount = Number(button.dataset.trashedCount || 0);
            const warning = trashedCount > 0
                ? AUTHORS_I18N.confirmDeleteTrashed.replace('__NAME__', authorName).replace('__COUNT__', trashedCount)
                : AUTHORS_I18N.confirmDelete.replace('__NAME__', authorName);

            if (!confirm(warning)) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = AUTHOR_DELETE_URL_TEMPLATE.replace('__AUTHOR_ID__', authorId);

            form.innerHTML = `
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        window.addEventListener('click', function (event) {
            const createModal = document.getElementById('create-modal');
            const editModal = document.getElementById('edit-modal');

            if (event.target === createModal) {
                hideCreateModal();
            }
            if (event.target === editModal) {
                hideEditModal();
            }
        });
    </script>
@endpush
