@extends('admin.layouts.app')

@php
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
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.materials.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.image_libraries.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.image_libraries.subtitle') }}</p>
                </div>
            </div>
            <button type="button" onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.image_libraries.create') }}
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="folder" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.image_libraries.total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_libraries'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="image" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.image_libraries.total_images') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_images'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="hard-drive" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.image_libraries.storage') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $formatSize((int) ($stats['total_size'] ?? 0)) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.common.avg_per_library') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (float) ($stats['avg_images'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.image_libraries.list_title') }}</h3>
            </div>

            @if (empty($libraries))
                <div class="px-6 py-8 text-center">
                    <i data-lucide="folder-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.image_libraries.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ __('admin.image_libraries.empty_desc') }}</p>
                    <button type="button" onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.image_libraries.create') }}
                    </button>
                </div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($libraries as $library)
                        <div class="px-6 py-6">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="{{ route('admin.image-libraries.detail', ['libraryId' => (int) $library['id']]) }}" class="hover:text-purple-600">
                                                {{ $library['name'] }}
                                            </a>
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                            {{ __('admin.image_libraries.image_count', ['count' => (int) $library['actual_count']]) }}
                                        </span>
                                        @if ((int) ($library['total_size'] ?? 0) > 0)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                {{ $formatSize((int) $library['total_size']) }}
                                            </span>
                                        @endif
                                    </div>
                                    @if ($library['description'] !== '')
                                        <p class="mt-1 text-sm text-gray-600">{{ $library['description'] }}</p>
                                    @endif
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span>{{ __('admin.image_libraries.created_at', ['value' => $library['created_at'] ? \Illuminate\Support\Carbon::parse($library['created_at'])->format('Y-m-d H:i') : '-']) }}</span>
                                        <span>{{ __('admin.image_libraries.updated_at', ['value' => $library['updated_at'] ? \Illuminate\Support\Carbon::parse($library['updated_at'])->format('Y-m-d H:i') : '-']) }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <a href="{{ route('admin.image-libraries.detail', ['libraryId' => (int) $library['id']]) }}" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                                        <i data-lucide="upload" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.image_libraries.upload_images') }}
                                    </a>
                                    <a href="{{ route('admin.image-libraries.detail', ['libraryId' => (int) $library['id']]) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.view') }}
                                    </a>
                                    <form method="POST" action="{{ route('admin.image-libraries.delete', ['libraryId' => (int) $library['id']]) }}" onsubmit="return confirm(@js(__('admin.image_libraries.confirm_delete', ['name' => $library['name']])));" class="inline-block">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                            <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                            {{ __('admin.button.delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div id="create-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.image_libraries.modal_create') }}</h3>
                <form method="POST" action="{{ route('admin.image-libraries.store') }}">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.image_libraries.field_name') }}</label>
                            <input type="text" name="name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm" placeholder="{{ __('admin.image_libraries.placeholder_name') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.image_libraries.field_description') }}</label>
                            <textarea name="description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm" placeholder="{{ __('admin.image_libraries.placeholder_description') }}"></textarea>
                        </div>
                        <div class="text-sm text-gray-500">
                            <p class="mb-2">{{ __('admin.image_libraries.supported_formats') }}</p>
                            <ul class="list-disc list-inside space-y-1">
                                <li>JPEG/JPG</li>
                                <li>PNG</li>
                                <li>GIF</li>
                                <li>WebP</li>
                            </ul>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-purple-600 hover:bg-purple-700">
                            {{ __('admin.button.create') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function showCreateModal() {
            document.getElementById('create-modal').classList.remove('hidden');
        }

        function hideCreateModal() {
            document.getElementById('create-modal').classList.add('hidden');
        }

        window.onclick = function (event) {
            const createModal = document.getElementById('create-modal');
            if (event.target === createModal) {
                hideCreateModal();
            }
        };
    </script>
@endpush
