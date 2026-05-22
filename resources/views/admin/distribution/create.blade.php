@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.distribution.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.distribution.create_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.create_subtitle') }}</p>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="px-6 py-6">
                <form method="POST" action="{{ route('admin.distribution.store') }}" class="space-y-6">
                    @csrf

                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.name') }} *</label>
                        <input id="name" name="name" type="text" required value="{{ old('name') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.distribution.placeholder.name') }}">
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="domain" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.domain') }} *</label>
                            <input id="domain" name="domain" type="text" required value="{{ old('domain') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="example.com">
                        </div>
                        <div>
                            <label for="endpoint_url" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.endpoint_url') }} *</label>
                            <input id="endpoint_url" name="endpoint_url" type="text" required value="{{ old('endpoint_url') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.distribution.placeholder.endpoint_url') }}">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.distribution.help.endpoint_url') }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="template_key" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.template_key') }}</label>
                            <input id="template_key" name="template_key" type="text" value="{{ old('template_key') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="default">
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.status') }}</label>
                            <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="active" @selected(old('status', 'active') === 'active')>{{ __('admin.distribution.status.active') }}</option>
                                <option value="paused" @selected(old('status') === 'paused')>{{ __('admin.distribution.status.paused') }}</option>
                            </select>
                        </div>
                    </div>

                    @php($frontMode = old('front_mode', 'static'))
                    <fieldset class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <legend class="text-sm font-medium text-gray-900">{{ __('admin.distribution.field.front_mode') }}</legend>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.help.front_mode') }}</p>
                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 bg-white p-4 hover:border-blue-300">
                                <input type="radio" name="front_mode" value="static" class="mt-1 text-blue-600 focus:ring-blue-500" @checked($frontMode === 'static')>
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900">{{ __('admin.distribution.front_mode.static') }}</span>
                                    <span class="mt-1 block text-sm text-gray-600">{{ __('admin.distribution.front_mode.static_desc') }}</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 bg-white p-4 hover:border-blue-300">
                                <input type="radio" name="front_mode" value="rewrite" class="mt-1 text-blue-600 focus:ring-blue-500" @checked($frontMode === 'rewrite')>
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900">{{ __('admin.distribution.front_mode.rewrite') }}</span>
                                    <span class="mt-1 block text-sm text-gray-600">{{ __('admin.distribution.front_mode.rewrite_desc') }}</span>
                                </span>
                            </label>
                        </div>
                    </fieldset>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700">{{ __('admin.common.description') }}</label>
                        <textarea id="description" name="description" rows="4" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.distribution.placeholder.description') }}">{{ old('description') }}</textarea>
                    </div>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.distribution.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.cancel') }}</a>
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="key-round" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.distribution.button.save_and_generate_secret') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
