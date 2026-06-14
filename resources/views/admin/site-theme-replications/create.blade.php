@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.site-settings.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.theme_replication.create_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.create_subtitle') }}</p>
            </div>
        </div>

        @unless ($schemaReady ?? true)
            <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900">
                <div class="flex items-start gap-3">
                    <i data-lucide="triangle-alert" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600"></i>
                    <div>
                        <div class="font-semibold">{{ __('admin.theme_replication.message.migration_required_title') }}</div>
                        <div class="mt-1">{{ __('admin.theme_replication.message.migration_required') }}</div>
                    </div>
                </div>
            </div>
        @endunless

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
            <form method="POST" action="{{ route('admin.site-settings.theme-replications.store') }}" class="space-y-6">
                @csrf

                <div class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.form.basic_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.form.basic_desc') }}</p>
                    </div>
                    <div class="space-y-5 px-6 py-6">
                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <label for="name" class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.theme_replication.field.name') }}</label>
                                <input id="name" name="name" type="text" value="{{ old('name') }}" required class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.theme_replication.placeholder.name') }}">
                            </div>
                            <div>
                                <label for="theme_id" class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.theme_replication.field.theme_id') }}</label>
                                <input id="theme_id" name="theme_id" type="text" value="{{ old('theme_id') }}" required class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.theme_replication.placeholder.theme_id') }}">
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.theme_replication.help.theme_id') }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <label for="ai_model_id" class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.theme_replication.field.ai_model') }}</label>
                                <select id="ai_model_id" name="ai_model_id" required class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" @disabled($activeChatModels->isEmpty())>
                                    @forelse ($activeChatModels as $model)
                                        <option value="{{ $model->id }}" @selected((string) old('ai_model_id') === (string) $model->id)>{{ $model->name }} · {{ $model->model_id }}</option>
                                    @empty
                                        <option value="">{{ __('admin.theme_replication.empty.no_chat_model') }}</option>
                                    @endforelse
                                </select>
                            </div>
                            <div>
                                <label for="base_theme_id" class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.theme_replication.field.base_theme') }}</label>
                                <select id="base_theme_id" name="base_theme_id" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">{{ __('admin.theme_replication.option.no_base_theme') }}</option>
                                    @foreach ($availableThemes as $theme)
                                        <option value="{{ $theme['id'] }}" @selected(old('base_theme_id') === $theme['id'])>{{ $theme['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div>
                            <div class="mb-2 text-sm font-medium text-gray-700">{{ __('admin.theme_replication.field.style_preference') }}</div>
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                @foreach (['content_site', 'brand_site', 'news_site'] as $styleOption)
                                    <label class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50/70 p-4">
                                        <input type="radio" name="style_preference" value="{{ $styleOption }}" class="mt-1 text-blue-600 focus:ring-blue-500" @checked(old('style_preference', 'content_site') === $styleOption)>
                                        <span>
                                            <span class="block text-sm font-semibold text-gray-900">{{ __('admin.theme_replication.style.'.$styleOption) }}</span>
                                            <span class="mt-1 block text-xs text-gray-500">{{ __('admin.theme_replication.style_desc.'.$styleOption) }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.form.references_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.form.references_desc') }}</p>
                    </div>
                    <div class="space-y-5 px-6 py-6">
                        @foreach (['home_url', 'category_url', 'article_url'] as $urlField)
                            <div>
                                <label for="{{ $urlField }}" class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.theme_replication.field.'.$urlField) }}</label>
                                <input id="{{ $urlField }}" name="{{ $urlField }}" type="url" value="{{ old($urlField) }}" required class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="https://example.com">
                            </div>
                        @endforeach

                        <label class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                            <input type="checkbox" name="compliance_ack" value="1" class="mt-1 rounded border-amber-300 text-amber-600 focus:ring-amber-500" @checked(old('compliance_ack'))>
                            <span class="text-sm text-amber-900">{{ __('admin.theme_replication.compliance_ack') }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('admin.site-settings.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.cancel') }}</a>
                    <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300" @disabled($activeChatModels->isEmpty() || ! ($schemaReady ?? true))>
                        <i data-lucide="wand-sparkles" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.theme_replication.button.create') }}
                    </button>
                </div>
            </form>

            <aside class="space-y-6">
                <div class="rounded-lg bg-white p-6 shadow">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.guide.title') }}</h2>
                    <div class="mt-5 space-y-4">
                        @foreach (['collect', 'analyze', 'preview', 'publish'] as $guideStep)
                            <div class="flex gap-3">
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-50 text-sm font-semibold text-blue-600">{{ $loop->iteration }}</div>
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">{{ __('admin.theme_replication.guide.'.$guideStep.'_title') }}</div>
                                    <div class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.guide.'.$guideStep.'_desc') }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.deployment.title') }}</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-gray-500">{{ __('admin.theme_replication.deployment.views_path') }}</dt>
                            <dd class="font-medium {{ $deploymentDiagnostics['views_writable'] ? 'text-green-700' : 'text-amber-700' }}">{{ $deploymentDiagnostics['views_writable'] ? __('admin.common.yes') : __('admin.common.no') }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-gray-500">{{ __('admin.theme_replication.deployment.assets_path') }}</dt>
                            <dd class="font-medium {{ $deploymentDiagnostics['assets_writable'] ? 'text-green-700' : 'text-amber-700' }}">{{ $deploymentDiagnostics['assets_writable'] ? __('admin.common.yes') : __('admin.common.no') }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 rounded-lg bg-gray-50 p-3 text-xs leading-6 text-gray-600">{{ __('admin.theme_replication.deployment.hint') }}</p>
                </div>
            </aside>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const nameInput = document.getElementById('name');
            const themeIdInput = document.getElementById('theme_id');
            if (!nameInput || !themeIdInput) {
                return;
            }

            let userEditedThemeId = themeIdInput.value.trim() !== '';
            const fallbackThemeId = `theme-${Date.now().toString(36)}`;
            themeIdInput.addEventListener('input', () => {
                userEditedThemeId = true;
            });

            nameInput.addEventListener('input', () => {
                if (userEditedThemeId) {
                    return;
                }

                const normalizedThemeId = nameInput.value
                    .toLowerCase()
                    .trim()
                    .replace(/[^a-z0-9\u4e00-\u9fa5]+/g, '-')
                    .replace(/[\u4e00-\u9fa5]/g, '')
                    .replace(/^-+|-+$/g, '')
                    .slice(0, 72);

                themeIdInput.value = normalizedThemeId || fallbackThemeId;
            });
        });
    </script>
@endpush
