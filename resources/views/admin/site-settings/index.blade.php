@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.site_settings.page_title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.site_settings.page_subtitle') }}</p>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <a href="{{ route('admin.site-settings.sensitive-words') }}" class="group rounded-lg border border-gray-200 bg-white p-5 shadow hover:border-blue-200 hover:bg-blue-50/40">
                <div class="flex items-start gap-3">
                    <span class="inline-flex rounded-lg bg-red-50 p-2 text-red-600 group-hover:bg-red-100">
                        <i data-lucide="shield-alert" class="h-5 w-5"></i>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-base font-semibold text-gray-900">{{ __('admin.site_settings.module_sensitive_words') }}</span>
                        <span class="mt-1 block text-sm leading-6 text-gray-600">{{ __('admin.site_settings.module_sensitive_words_desc') }}</span>
                    </span>
                </div>
            </a>
        </div>

        <details class="mb-6 bg-white shadow rounded-lg overflow-hidden group">
            <summary class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.site_settings.section_basic') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.site_settings.page_subtitle') }}</p>
                </div>
                <i data-lucide="chevron-down" class="w-5 h-5 shrink-0 text-gray-400 transition-transform duration-200 group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <div class="px-6 py-6">
                <form method="POST" action="{{ route('admin.site-settings.update') }}" class="space-y-6">
                    @csrf

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_site_name') }}</label>
                            <input type="text" name="site_name" required
                                   value="{{ $settings['site_name'] }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="{{ __('admin.site_settings.placeholder_site_name') }}">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_logo') }}</label>
                            <input type="url" name="site_logo"
                                   value="{{ $settings['site_logo'] }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="https://example.com/logo.png">
                        </div>
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">{{ __('admin.site_settings.field_admin_base_path') }}</label>
                        <div class="flex rounded-md shadow-sm">
                            <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-white px-3 text-sm text-gray-500">{{ rtrim(url('/'), '/') }}/</span>
                            <input type="text" name="admin_base_path" required
                                   value="{{ $settings['admin_base_path'] }}"
                                   class="w-full min-w-0 flex-1 rounded-none rounded-r-md border border-gray-300 px-3 py-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="{{ __('admin.site_settings.placeholder_admin_base_path') }}">
                        </div>
                        <p class="mt-2 text-xs leading-5 text-amber-800">{{ __('admin.site_settings.admin_base_path_help') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_description') }}</label>
                        <textarea name="site_description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="{{ __('admin.site_settings.placeholder_description') }}">{{ $settings['site_description'] }}</textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_subtitle') }}</label>
                        <input type="text" name="site_subtitle"
                               value="{{ $settings['site_subtitle'] }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                               placeholder="{{ __('admin.site_settings.placeholder_subtitle') }}">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_keywords') }}</label>
                        <input type="text" name="site_keywords"
                               value="{{ $settings['site_keywords'] }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                               placeholder="{{ __('admin.site_settings.placeholder_keywords') }}">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.keywords_help') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_copyright') }}</label>
                        <input type="text" name="copyright_info"
                               value="{{ $settings['copyright_info'] }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                               placeholder="© 2024 Site Name. All rights reserved.">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_featured_limit') }}</label>
                            <input type="number" name="featured_limit" min="1"
                                   value="{{ $settings['featured_limit'] }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="6">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_per_page') }}</label>
                            <input type="number" name="per_page" min="1"
                                   value="{{ $settings['per_page'] }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="12">
                        </div>
                    </div>

                    <div class="border-t border-gray-200 pt-6">
                        <div class="mb-4">
                            <h4 class="text-lg font-medium text-gray-900">{{ __('admin.site_settings.section_home_carousel') }}</h4>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.site_settings.home_carousel_desc') }}</p>
                        </div>
                        @php
                            $carouselSlides = $homeCarouselSlides ?? [];
                            for ($slideIndex = count($carouselSlides); $slideIndex < 3; $slideIndex++) {
                                $carouselSlides[] = [
                                    'image_url' => '',
                                    'title' => '',
                                    'link_url' => '',
                                    'enabled' => false,
                                ];
                            }
                        @endphp
                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            @foreach(array_slice($carouselSlides, 0, 3) as $slideIndex => $slide)
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                                    <div class="mb-3 flex items-center justify-between gap-3">
                                        <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.home_carousel_slide', ['index' => $slideIndex + 1]) }}</div>
                                        <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                                            <input type="checkbox" name="home_carousel_slides[{{ $slideIndex }}][enabled]" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" @checked(!empty($slide['enabled']))>
                                            {{ __('admin.site_settings.field_home_carousel_enabled') }}
                                        </label>
                                    </div>
                                    <div class="space-y-3">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('admin.site_settings.field_home_carousel_image') }}</label>
                                            <input type="text" name="home_carousel_slides[{{ $slideIndex }}][image_url]"
                                                   value="{{ $slide['image_url'] ?? '' }}"
                                                   class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                   placeholder="{{ __('admin.site_settings.placeholder_home_carousel_image') }}">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('admin.site_settings.field_home_carousel_title') }}</label>
                                            <input type="text" name="home_carousel_slides[{{ $slideIndex }}][title]"
                                                   value="{{ $slide['title'] ?? '' }}"
                                                   class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                   placeholder="{{ __('admin.site_settings.placeholder_home_carousel_title') }}">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('admin.site_settings.field_home_carousel_link') }}</label>
                                            <input type="text" name="home_carousel_slides[{{ $slideIndex }}][link_url]"
                                                   value="{{ $slide['link_url'] ?? '' }}"
                                                   class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                   placeholder="{{ __('admin.site_settings.placeholder_home_carousel_link') }}">
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="border-t border-gray-200 pt-6">
                        <h4 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.site_settings.section_seo') }}</h4>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_seo_title_template') }}</label>
                                <input type="text" name="seo_title_template"
                                       value="{{ $settings['seo_title_template'] }}"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="{title} - {site_name}">
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.seo_title_help') }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_seo_description_template') }}</label>
                                <input type="text" name="seo_description_template"
                                       value="{{ $settings['seo_description_template'] }}"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="{description}">
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.seo_description_help') }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_favicon') }}</label>
                                <input type="url" name="site_favicon"
                                       value="{{ $settings['site_favicon'] }}"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="https://example.com/favicon.ico">
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 pt-6">
                        <h4 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.site_settings.section_analytics') }}</h4>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.field_analytics') }}</label>
                            <textarea name="analytics_code" rows="4"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm disabled:bg-gray-100 disabled:cursor-not-allowed"
                                      @disabled(!($canEditAnalytics ?? false))
                                      placeholder="{{ __('admin.site_settings.placeholder_analytics') }}">{{ $settings['analytics_code'] }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">{{ ($canEditAnalytics ?? false) ? __('admin.site_settings.analytics_help') : __('admin.site_settings.analytics_super_admin_only') }}</p>
                        </div>
                    </div>

                    <div class="flex justify-end pt-6 border-t border-gray-200">
                        <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <i data-lucide="save" class="w-5 h-5 mr-2"></i>
                            {{ __('admin.site_settings.save_settings') }}
                        </button>
                    </div>
                </form>
            </div>
        </details>

        <details class="mb-6 bg-white shadow rounded-lg overflow-hidden group">
            <summary class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.site_settings.theme.section_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.site_settings.theme.section_desc') }}</p>
                </div>
                <i data-lucide="chevron-down" class="w-5 h-5 shrink-0 text-gray-400 transition-transform duration-200 group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <div class="px-6 py-6">
                <form method="POST" action="{{ route('admin.site-settings.theme') }}" class="space-y-5">
                    @csrf

                    @php
                        $currentThemeLabel = __('admin.site_settings.theme.default_name');
                        foreach ($availableThemes as $themeOption) {
                            if ($themeOption['id'] === $settings['active_theme']) {
                                $currentThemeLabel = $themeOption['name'];
                                break;
                            }
                        }
                    @endphp

                    <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-4 flex flex-col gap-1">
                        <div class="text-sm font-medium text-gray-900">{{ __('admin.site_settings.theme.current_label') }}</div>
                        <div class="text-base font-semibold text-gray-900">{{ $currentThemeLabel }}</div>
                        <div class="text-xs text-gray-500">{{ __('admin.site_settings.theme.current_help') }}</div>
                    </div>

                    <div class="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-100">
                                    <i data-lucide="wand-sparkles" class="mr-1.5 h-3.5 w-3.5"></i>
                                    {{ __('admin.theme_replication.entry_badge') }}
                                </div>
                                <h4 class="mt-3 text-base font-semibold text-gray-900">{{ __('admin.theme_replication.entry_title') }}</h4>
                                <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.entry_desc') }}</p>
                                @if (! ($themeReplicationDeployment['can_publish_directly'] ?? false))
                                    <p class="mt-2 text-xs text-amber-700">{{ __('admin.theme_replication.deployment.readonly_hint') }}</p>
                                @endif
                            </div>
                            <a href="{{ route('admin.site-settings.theme-replications.create') }}" class="inline-flex shrink-0 items-center justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                                <i data-lucide="copy-plus" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.theme_replication.button.start') }}
                            </a>
                        </div>

                        @if (($recentThemeReplications ?? collect())->isNotEmpty())
                            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                                @foreach ($recentThemeReplications as $replication)
                                    <a href="{{ route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]) }}" class="rounded-xl border border-indigo-100 bg-white p-3 hover:border-indigo-200 hover:shadow-sm">
                                        <div class="truncate text-sm font-semibold text-gray-900">{{ $replication->name }}</div>
                                        <div class="mt-1 flex items-center justify-between gap-2 text-xs text-gray-500">
                                            <span class="truncate font-mono">{{ $replication->theme_id }}</span>
                                            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5">{{ __('admin.theme_replication.status.'.$replication->status) }}</span>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="space-y-4">
                        <label class="flex items-start gap-4 rounded-2xl border border-gray-200 bg-gray-50/70 p-4">
                            <input type="radio" name="active_theme" value="" class="mt-1 text-blue-600 focus:ring-blue-500" @checked($settings['active_theme'] === '')>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.theme.default_name') }}</div>
                                <div class="mt-1 text-sm text-gray-600">{{ __('admin.site_settings.theme.default_desc') }}</div>
                            </div>
                        </label>

                        @foreach ($availableThemes as $themeOption)
                            <label class="flex items-start gap-4 rounded-2xl border border-gray-200 bg-white p-4">
                                <input type="radio" name="active_theme" value="{{ $themeOption['id'] }}" class="mt-1 text-blue-600 focus:ring-blue-500" @checked($settings['active_theme'] === $themeOption['id'])>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="text-sm font-semibold text-gray-900">{{ $themeOption['name'] }}</div>
                                        @if ($themeOption['version'] !== '')
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ __('admin.site_settings.theme.version_badge', ['version' => $themeOption['version']]) }}</span>
                                        @endif
                                        @if ($settings['active_theme'] === $themeOption['id'])
                                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">{{ __('admin.site_settings.theme.active_badge') }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 text-sm text-gray-600">
                                        {{ $themeOption['description'] !== '' ? $themeOption['description'] : __('admin.site_settings.theme.no_description') }}
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <span class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-500">{{ __('admin.site_settings.theme.preview_home') }}</span>
                                        <span class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-500">{{ __('admin.site_settings.theme.preview_category') }}</span>
                                        <span class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-500">{{ __('admin.site_settings.theme.preview_article') }}</span>
                                        <span class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-500">{{ __('admin.site_settings.theme.preview_archive') }}</span>
                                    </div>
                                </div>
                            </label>
                        @endforeach
                    </div>

                    <div class="flex justify-end pt-2 border-t border-gray-200">
                        <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="layout-template" class="w-5 h-5 mr-2"></i>
                            {{ __('admin.site_settings.theme.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </details>

        <details class="mb-6 bg-white shadow rounded-lg overflow-hidden group">
            <summary class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.site_settings.ads.section_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.site_settings.ads.section_desc') }}</p>
                </div>
                <i data-lucide="chevron-down" class="w-5 h-5 shrink-0 text-gray-400 transition-transform duration-200 group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <div class="px-6 py-6">
                <form method="POST" action="{{ route('admin.site-settings.ads') }}" id="article-ad-form" class="space-y-6">
                    <div class="flex justify-end">
                        <button type="button" id="add-article-ad" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.site_settings.ads.add') }}
                        </button>
                    </div>
                    @csrf

                    <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-4">
                        <div class="text-sm font-medium text-gray-900">{{ __('admin.site_settings.ads.preview_title') }}</div>
                        <div class="mt-3 rounded-2xl border border-blue-200 bg-white p-4 shadow-sm">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700">{{ __('admin.site_settings.ads.preview_badge') }}</div>
                                    <div class="mt-3 text-base font-semibold text-gray-900">{{ __('admin.site_settings.ads.preview_heading') }}</div>
                                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.site_settings.ads.preview_copy') }}</p>
                                </div>
                                <button type="button" class="shrink-0 inline-flex items-center rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white">{{ __('admin.site_settings.ads.preview_cta') }}</button>
                            </div>
                        </div>
                    </div>

                    <div id="article-ad-list" class="space-y-5">
                        @foreach ($articleDetailAds as $index => $ad)
                            <div class="article-ad-item rounded-2xl border border-gray-200 bg-gray-50/70 p-5" data-ad-index="{{ $index }}">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">{{ $ad['name'] !== '' ? $ad['name'] : __('admin.site_settings.ads.default_name', ['index' => $index + 1]) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.ads.position_label') }}</div>
                                    </div>
                                    <button type="button" class="remove-article-ad inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                                        {{ __('admin.button.delete') }}
                                    </button>
                                </div>

                                <input type="hidden" name="ads[{{ $index }}][id]" value="{{ $ad['id'] }}">

                                <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_name') }}</label>
                                        <input type="text" name="ads[{{ $index }}][name]" value="{{ $ad['name'] }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_name') }}">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_badge') }}</label>
                                        <input type="text" name="ads[{{ $index }}][badge]" value="{{ $ad['badge'] }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_badge') }}">
                                    </div>
                                </div>

                                <div class="mt-5">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_title') }}</label>
                                    <input type="text" name="ads[{{ $index }}][title]" value="{{ $ad['title'] }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_title') }}">
                                </div>

                                <div class="mt-5">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_copy') }}</label>
                                    <textarea name="ads[{{ $index }}][copy]" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_copy') }}">{{ $ad['copy'] }}</textarea>
                                </div>

                                <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_button_text') }}</label>
                                        <input type="text" name="ads[{{ $index }}][button_text]" value="{{ $ad['button_text'] }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_button_text') }}">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_button_url') }}</label>
                                        <input type="text" name="ads[{{ $index }}][button_url]" value="{{ $ad['button_url'] }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_button_url') }}">
                                    </div>
                                </div>

                                <div class="mt-5 flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">{{ __('admin.site_settings.ads.field_enabled') }}</div>
                                        <div class="text-xs text-gray-500">{{ __('admin.site_settings.ads.enabled_help') }}</div>
                                    </div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="ads[{{ $index }}][enabled]" value="1" @checked($ad['enabled']) class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div id="article-ad-empty" class="{{ !empty($articleDetailAds) ? 'hidden ' : '' }}rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center">
                        <div class="text-base font-medium text-gray-900">{{ __('admin.site_settings.ads.empty_title') }}</div>
                        <div class="mt-2 text-sm text-gray-500">{{ __('admin.site_settings.ads.empty_desc') }}</div>
                    </div>

                    <div class="flex justify-end pt-2 border-t border-gray-200">
                        <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="save" class="w-5 h-5 mr-2"></i>
                            {{ __('admin.site_settings.ads.save') }}
                        </button>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.site-settings.text-ads') }}" id="article-text-ad-form" class="mt-8 space-y-6 border-t border-gray-200 pt-8">
                    @csrf
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h4 class="text-base font-semibold text-gray-900">{{ __('admin.site_settings.ads.text_section_title') }}</h4>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.site_settings.ads.text_section_desc') }}</p>
                        </div>
                        <button type="button" id="add-article-text-ad" class="inline-flex items-center justify-center rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">
                            <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.site_settings.ads.text_add') }}
                        </button>
                    </div>

                    <div id="article-text-ad-list" class="space-y-5">
                        @foreach ($articleDetailTextAds as $index => $textAd)
                            <div class="article-text-ad-item rounded-2xl border border-gray-200 bg-white p-5 shadow-sm" data-text-ad-index="{{ $index }}">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">{{ $textAd['name'] !== '' ? $textAd['name'] : __('admin.site_settings.ads.text_default_name', ['index' => $index + 1]) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.ads.text_card_desc') }}</div>
                                    </div>
                                    <button type="button" class="remove-article-text-ad inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                                        <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                                        {{ __('admin.button.delete') }}
                                    </button>
                                </div>

                                <input type="hidden" name="text_ads[{{ $index }}][id]" value="{{ $textAd['id'] }}">

                                <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-4">
                                    <div class="lg:col-span-2">
                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_name') }}</label>
                                        <input type="text" name="text_ads[{{ $index }}][name]" value="{{ $textAd['name'] }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_name') }}">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_position') }}</label>
                                        <select name="text_ads[{{ $index }}][placement]" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            <option value="content_top" @selected($textAd['placement'] === 'content_top')>{{ __('admin.site_settings.ads.text_position_top') }}</option>
                                            <option value="content_bottom" @selected($textAd['placement'] === 'content_bottom')>{{ __('admin.site_settings.ads.text_position_bottom') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_sort') }}</label>
                                        <input type="number" min="0" max="10000" name="text_ads[{{ $index }}][sort_order]" value="{{ $textAd['sort_order'] }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                </div>

                                <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-4">
                                    <div class="lg:col-span-2">
                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_text') }}</label>
                                        <input type="text" name="text_ads[{{ $index }}][text]" value="{{ $textAd['text'] }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_text') }}">
                                    </div>
                                    <div class="lg:col-span-2">
                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_url') }}</label>
                                        <input type="text" name="text_ads[{{ $index }}][url]" value="{{ $textAd['url'] }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_url') }}">
                                    </div>
                                </div>

                                <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-4">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_color') }}</label>
                                        <div class="flex overflow-hidden rounded-md border border-gray-300 bg-white shadow-sm focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500">
                                            <input type="color" value="{{ $textAd['text_color'] }}" class="h-10 w-12 border-0 bg-white p-1" aria-label="{{ __('admin.site_settings.ads.text_field_color') }}">
                                            <input type="text" name="text_ads[{{ $index }}][text_color]" value="{{ $textAd['text_color'] }}" class="min-w-0 flex-1 border-0 px-3 py-2 focus:ring-0" placeholder="#2563eb">
                                        </div>
                                    </div>
                                    <div class="lg:col-span-2">
                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_tracking') }}</label>
                                        <input type="text" name="text_ads[{{ $index }}][tracking_param]" value="{{ $textAd['tracking_param'] }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="utm_source=geoflow&utm_medium=article_text_ad">
                                    </div>
                                    <div class="flex items-end">
                                        <div class="grid w-full grid-cols-3 gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                                            <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                                                <input type="checkbox" name="text_ads[{{ $index }}][open_new_tab]" value="1" @checked($textAd['open_new_tab']) class="rounded border-gray-300 text-blue-600">
                                                {{ __('admin.site_settings.ads.text_open_new_tab') }}
                                            </label>
                                            <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                                                <input type="checkbox" name="text_ads[{{ $index }}][tracking_enabled]" value="1" @checked($textAd['tracking_enabled']) class="rounded border-gray-300 text-blue-600">
                                                {{ __('admin.site_settings.ads.text_tracking_enabled') }}
                                            </label>
                                            <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                                                <input type="checkbox" name="text_ads[{{ $index }}][enabled]" value="1" @checked($textAd['enabled']) class="rounded border-gray-300 text-blue-600">
                                                {{ __('admin.site_settings.ads.text_enabled') }}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div id="article-text-ad-empty" class="{{ !empty($articleDetailTextAds) ? 'hidden ' : '' }}rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center">
                        <div class="text-base font-medium text-gray-900">{{ __('admin.site_settings.ads.text_empty_title') }}</div>
                        <div class="mt-2 text-sm text-gray-500">{{ __('admin.site_settings.ads.text_empty_desc') }}</div>
                    </div>

                    <div class="flex justify-end border-t border-gray-200 pt-2">
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-6 py-3 text-base font-medium text-white hover:bg-blue-700">
                            <i data-lucide="save" class="mr-2 h-5 w-5"></i>
                            {{ __('admin.site_settings.ads.text_save') }}
                        </button>
                    </div>
                </form>
            </div>
        </details>
    </div>
@endsection

@push('scripts')
    <template id="article-ad-template">
        <div class="article-ad-item rounded-2xl border border-gray-200 bg-gray-50/70 p-5" data-ad-index="__INDEX__">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.ads.new_slot') }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.ads.position_label') }}</div>
                </div>
                <button type="button" class="remove-article-ad inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.button.delete') }}
                </button>
            </div>

            <input type="hidden" name="ads[__INDEX__][id]" value="">

            <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_name') }}</label>
                    <input type="text" name="ads[__INDEX__][name]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_name') }}">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_badge') }}</label>
                    <input type="text" name="ads[__INDEX__][badge]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_badge') }}">
                </div>
            </div>

            <div class="mt-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_title') }}</label>
                <input type="text" name="ads[__INDEX__][title]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_title') }}">
            </div>

            <div class="mt-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_copy') }}</label>
                <textarea name="ads[__INDEX__][copy]" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_copy') }}"></textarea>
            </div>

            <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_button_text') }}</label>
                    <input type="text" name="ads[__INDEX__][button_text]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_button_text') }}">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.site_settings.ads.field_button_url') }}</label>
                    <input type="text" name="ads[__INDEX__][button_url]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.site_settings.ads.placeholder_button_url') }}">
                </div>
            </div>

            <div class="mt-5 flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3">
                <div>
                    <div class="text-sm font-medium text-gray-900">{{ __('admin.site_settings.ads.field_enabled') }}</div>
                    <div class="text-xs text-gray-500">{{ __('admin.site_settings.ads.enabled_help') }}</div>
                </div>
                <label class="inline-flex items-center">
                    <input type="checkbox" name="ads[__INDEX__][enabled]" value="1" checked class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                </label>
            </div>
        </div>
    </template>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            const adList = document.getElementById('article-ad-list');
            const emptyState = document.getElementById('article-ad-empty');
            const addButton = document.getElementById('add-article-ad');
            const template = document.getElementById('article-ad-template');

            if (!adList || !emptyState || !addButton || !template) {
                return;
            }

            let adIndex = adList.querySelectorAll('.article-ad-item').length;

            function refreshState() {
                emptyState.classList.toggle('hidden', adList.querySelectorAll('.article-ad-item').length > 0);
            }

            function bindRemove(scope) {
                const removeButton = scope.querySelector('.remove-article-ad');
                if (!removeButton) {
                    return;
                }

                removeButton.addEventListener('click', function () {
                    scope.remove();
                    refreshState();
                });
            }

            addButton.addEventListener('click', function () {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(adIndex)).trim();
                adIndex += 1;

                const adItem = wrapper.firstElementChild;
                if (!adItem) {
                    return;
                }

                adList.appendChild(adItem);
                bindRemove(adItem);
                refreshState();

                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });

            adList.querySelectorAll('.article-ad-item').forEach(bindRemove);
            refreshState();
        });
    </script>

    <template id="article-text-ad-template">
        <div class="article-text-ad-item rounded-2xl border border-gray-200 bg-white p-5 shadow-sm" data-text-ad-index="__INDEX__">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.ads.text_new_slot') }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.ads.text_card_desc') }}</div>
                </div>
                <button type="button" class="remove-article-text-ad inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.button.delete') }}
                </button>
            </div>

            <input type="hidden" name="text_ads[__INDEX__][id]" value="">

            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_name') }}</label>
                    <input type="text" name="text_ads[__INDEX__][name]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_name') }}">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_position') }}</label>
                    <select name="text_ads[__INDEX__][placement]" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="content_top">{{ __('admin.site_settings.ads.text_position_top') }}</option>
                        <option value="content_bottom">{{ __('admin.site_settings.ads.text_position_bottom') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_sort') }}</label>
                    <input type="number" min="0" max="10000" name="text_ads[__INDEX__][sort_order]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_text') }}</label>
                    <input type="text" name="text_ads[__INDEX__][text]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_text') }}">
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_url') }}</label>
                    <input type="text" name="text_ads[__INDEX__][url]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_url') }}">
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-4">
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_color') }}</label>
                    <div class="flex overflow-hidden rounded-md border border-gray-300 bg-white shadow-sm focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500">
                        <input type="color" value="#2563eb" class="h-10 w-12 border-0 bg-white p-1" aria-label="{{ __('admin.site_settings.ads.text_field_color') }}">
                        <input type="text" name="text_ads[__INDEX__][text_color]" value="#2563eb" class="min-w-0 flex-1 border-0 px-3 py-2 focus:ring-0" placeholder="#2563eb">
                    </div>
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_tracking') }}</label>
                    <input type="text" name="text_ads[__INDEX__][tracking_param]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="utm_source=geoflow&utm_medium=article_text_ad">
                </div>
                <div class="flex items-end">
                    <div class="grid w-full grid-cols-3 gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                            <input type="checkbox" name="text_ads[__INDEX__][open_new_tab]" value="1" checked class="rounded border-gray-300 text-blue-600">
                            {{ __('admin.site_settings.ads.text_open_new_tab') }}
                        </label>
                        <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                            <input type="checkbox" name="text_ads[__INDEX__][tracking_enabled]" value="1" checked class="rounded border-gray-300 text-blue-600">
                            {{ __('admin.site_settings.ads.text_tracking_enabled') }}
                        </label>
                        <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                            <input type="checkbox" name="text_ads[__INDEX__][enabled]" value="1" checked class="rounded border-gray-300 text-blue-600">
                            {{ __('admin.site_settings.ads.text_enabled') }}
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const textAdList = document.getElementById('article-text-ad-list');
            const textAdEmptyState = document.getElementById('article-text-ad-empty');
            const textAdAddButton = document.getElementById('add-article-text-ad');
            const textAdTemplate = document.getElementById('article-text-ad-template');

            if (!textAdList || !textAdEmptyState || !textAdAddButton || !textAdTemplate) {
                return;
            }

            let textAdIndex = textAdList.querySelectorAll('.article-text-ad-item').length;

            function refreshTextAdState() {
                textAdEmptyState.classList.toggle('hidden', textAdList.querySelectorAll('.article-text-ad-item').length > 0);
            }

            function bindTextAdRemove(scope) {
                const removeButton = scope.querySelector('.remove-article-text-ad');
                if (!removeButton) {
                    return;
                }

                removeButton.addEventListener('click', function () {
                    scope.remove();
                    refreshTextAdState();
                });
            }

            function bindColorPicker(scope) {
                scope.querySelectorAll('input[type="color"]').forEach(function (picker) {
                    const textInput = picker.parentElement ? picker.parentElement.querySelector('input[type="text"]') : null;
                    if (!textInput) {
                        return;
                    }

                    picker.addEventListener('input', function () {
                        textInput.value = picker.value;
                    });

                    textInput.addEventListener('input', function () {
                        if (/^#[0-9a-fA-F]{6}$/.test(textInput.value)) {
                            picker.value = textInput.value;
                        }
                    });
                });
            }

            textAdAddButton.addEventListener('click', function () {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = textAdTemplate.innerHTML.replaceAll('__INDEX__', String(textAdIndex)).trim();
                textAdIndex += 1;

                const textAdItem = wrapper.firstElementChild;
                if (!textAdItem) {
                    return;
                }

                textAdList.appendChild(textAdItem);
                bindTextAdRemove(textAdItem);
                bindColorPicker(textAdItem);
                refreshTextAdState();

                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });

            textAdList.querySelectorAll('.article-text-ad-item').forEach(function (item) {
                bindTextAdRemove(item);
                bindColorPicker(item);
            });
            refreshTextAdState();
        });
    </script>
@endpush
