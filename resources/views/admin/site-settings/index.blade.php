@extends('admin.layouts.app')

@php
    $siteSettingsGroupRows = [
        [
            [
                'title' => __('admin.site_settings.group_basic'),
                'desc' => __('admin.site_settings.group_basic_desc'),
                'columns' => 'md:grid-cols-2',
                'items' => [
                    [
                        'title' => __('admin.site_settings.section_basic'),
                        'desc' => __('admin.site_settings.module_basic_desc'),
                        'href' => '#site-settings-basic',
                        'target' => 'site-settings-basic',
                        'icon' => 'settings',
                        'iconClass' => 'bg-blue-50 text-blue-600 ring-blue-100',
                        'action' => __('admin.site_settings.open_section'),
                    ],
                    [
                        'title' => __('admin.site_settings.theme.section_title'),
                        'desc' => __('admin.site_settings.module_theme_desc'),
                        'href' => '#site-settings-theme',
                        'target' => 'site-settings-theme',
                        'icon' => 'layout-template',
                        'iconClass' => 'bg-indigo-50 text-indigo-600 ring-indigo-100',
                        'action' => __('admin.site_settings.open_section'),
                    ],
                ],
            ],
        ],
        [
            [
                'title' => __('admin.site_settings.group_operations'),
                'desc' => __('admin.site_settings.group_operations_desc'),
                'columns' => '',
                'items' => [
                    [
                        'title' => __('admin.site_settings.ads.section_title'),
                        'desc' => __('admin.site_settings.module_ads_desc'),
                        'href' => '#site-settings-ads',
                        'target' => 'site-settings-ads',
                        'icon' => 'megaphone',
                        'iconClass' => 'bg-emerald-50 text-emerald-600 ring-emerald-100',
                        'action' => __('admin.site_settings.open_section'),
                    ],
                ],
            ],
            [
                'title' => __('admin.site_settings.group_security'),
                'desc' => __('admin.site_settings.group_security_desc'),
                'columns' => '',
                'items' => [
                    [
                        'title' => __('admin.site_settings.module_sensitive_words'),
                        'desc' => __('admin.site_settings.module_sensitive_words_desc'),
                        'href' => route('admin.site-settings.sensitive-words'),
                        'target' => null,
                        'icon' => 'shield-alert',
                        'iconClass' => 'bg-red-50 text-red-600 ring-red-100',
                        'action' => __('admin.site_settings.manage_module'),
                    ],
                ],
            ],
        ],
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.site_settings.page_title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.site_settings.page_subtitle') }}</p>
        </div>

        <div class="mb-8 space-y-6">
            @foreach ($siteSettingsGroupRows as $settingsGroupRow)
                <div class="{{ count($settingsGroupRow) > 1 ? 'grid grid-cols-1 gap-6 lg:grid-cols-2' : 'space-y-6' }}">
                    @foreach ($settingsGroupRow as $settingsGroup)
                        <section>
                            <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <h2 class="text-sm font-semibold text-gray-900">{{ $settingsGroup['title'] }}</h2>
                                    <p class="mt-1 text-sm leading-6 text-gray-500">{{ $settingsGroup['desc'] }}</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 {{ $settingsGroup['columns'] }}">
                                @foreach ($settingsGroup['items'] as $settingsItem)
                                    <a href="{{ $settingsItem['href'] }}"
                                       @if ($settingsItem['target'] !== null) data-site-settings-target="{{ $settingsItem['target'] }}" @endif
                                       class="group flex min-h-36 flex-col justify-between rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                        <span class="flex items-start gap-4">
                                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md ring-1 {{ $settingsItem['iconClass'] }}">
                                                <i data-lucide="{{ $settingsItem['icon'] }}" class="h-5 w-5"></i>
                                            </span>
                                            <span class="min-w-0">
                                                <span class="block text-base font-semibold text-gray-900">{{ $settingsItem['title'] }}</span>
                                                <span class="mt-1 block text-sm leading-6 text-gray-600">{{ $settingsItem['desc'] }}</span>
                                            </span>
                                        </span>
                                        <span class="mt-4 inline-flex items-center text-sm font-semibold text-blue-700">
                                            {{ $settingsItem['action'] }}
                                            <i data-lucide="arrow-right" class="ml-1.5 h-4 w-4 transition-transform group-hover:translate-x-0.5"></i>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            @endforeach
        </div>

        <details id="site-settings-basic" class="mb-6 bg-white shadow rounded-lg overflow-hidden group">
            <summary class="px-6 py-5 border-b border-gray-200 flex items-center justify-between gap-4 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                <div class="flex min-w-0 items-start gap-4">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-md bg-blue-50 text-blue-600 ring-1 ring-blue-100 sm:inline-flex">
                        <i data-lucide="settings" class="h-5 w-5"></i>
                    </span>
                    <div class="min-w-0 max-w-3xl">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.site_settings.section_basic') }}</h3>
                        <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.site_settings.module_basic_desc') }}</p>
                    </div>
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

        <details id="site-settings-theme" class="mb-6 bg-white shadow rounded-lg overflow-hidden group">
            <summary class="px-6 py-5 border-b border-gray-200 flex items-center justify-between gap-4 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                <div class="flex min-w-0 items-start gap-4">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-md bg-indigo-50 text-indigo-600 ring-1 ring-indigo-100 sm:inline-flex">
                        <i data-lucide="layout-template" class="h-5 w-5"></i>
                    </span>
                    <div class="min-w-0 max-w-3xl">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.site_settings.theme.section_title') }}</h3>
                        <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.site_settings.module_theme_desc') }}</p>
                    </div>
                </div>
                <i data-lucide="chevron-down" class="w-5 h-5 shrink-0 text-gray-400 transition-transform duration-200 group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <div class="px-6 py-6">
                @php
                    $homepageFormModules = old('homepage_modules', $homepageModules ?? []);
                    $homepageFormStyle = old('homepage_style', $homepageStyle ?? []);
                @endphp

                <form method="POST" action="{{ route('admin.site-settings.homepage-modules.preset') }}" class="mb-5 rounded-2xl border border-indigo-100 bg-white p-5">
                    @csrf
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-100">
                                <i data-lucide="sparkles" class="mr-1.5 h-3.5 w-3.5"></i>
                                {{ __('admin.site_settings.homepage.preset_title') }}
                            </div>
                            <p class="mt-3 text-sm leading-6 text-gray-600">{{ __('admin.site_settings.homepage.preset_desc') }}</p>
                        </div>
                        <div class="grid w-full grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_180px_auto] xl:max-w-3xl">
                            <div>
                                <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.preset_field') }}</label>
                                <select name="homepage_preset" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    @foreach ($homepagePresets as $preset)
                                        <option value="{{ $preset }}">{{ __('admin.site_settings.homepage.preset_'.$preset) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.preset_mode') }}</label>
                                <select name="preset_mode" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    @foreach ($homepagePresetModes as $mode)
                                        <option value="{{ $mode }}">{{ __('admin.site_settings.homepage.preset_mode_'.$mode) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                    <i data-lucide="wand-sparkles" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.site_settings.homepage.preset_apply') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.site-settings.homepage-modules.import') }}" class="mb-5 rounded-2xl border border-blue-100 bg-white p-5">
                    @csrf
                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_240px]">
                        <div class="min-w-0">
                            <div class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 ring-1 ring-blue-100">
                                <i data-lucide="braces" class="mr-1.5 h-3.5 w-3.5"></i>
                                {{ __('admin.site_settings.homepage.import_title') }}
                            </div>
                            <p class="mt-3 text-sm leading-6 text-gray-600">{{ __('admin.site_settings.homepage.import_desc') }}</p>
                            <label class="mt-4 mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.import_field') }}</label>
                            <textarea name="homepage_design_json" rows="5" class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-xs leading-5 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.homepage.import_placeholder') }}">{{ old('homepage_design_json') }}</textarea>
                            @error('homepage_design_json')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex flex-col justify-end gap-3">
                            <div>
                                <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.import_mode') }}</label>
                                <select name="import_mode" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    @foreach ($homepagePresetModes as $mode)
                                        <option value="{{ $mode }}" @selected(old('import_mode', 'replace') === $mode)>{{ __('admin.site_settings.homepage.preset_mode_'.$mode) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                                <i data-lucide="upload" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.site_settings.homepage.import_apply') }}
                            </button>
                        </div>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.site-settings.homepage-modules') }}" id="homepage-module-form" class="mb-8 rounded-2xl border border-gray-200 bg-gray-50/70 p-5">
                    @csrf
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div class="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs font-semibold text-blue-700 ring-1 ring-blue-100">
                                <i data-lucide="layout-dashboard" class="mr-1.5 h-3.5 w-3.5"></i>
                                {{ __('admin.site_settings.homepage.badge') }}
                            </div>
                            <h4 class="mt-3 text-base font-semibold text-gray-900">{{ __('admin.site_settings.homepage.section_title') }}</h4>
                            <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.site_settings.homepage.section_desc') }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <button type="button" id="add-homepage-module" class="inline-flex items-center rounded-md border border-blue-200 bg-white px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50">
                                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.site_settings.homepage.add_module') }}
                            </button>
                            <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                                <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.site_settings.homepage.save') }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 rounded-2xl border border-blue-100 bg-white p-4">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                <i data-lucide="info" class="h-4 w-4"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.homepage.scope_notice_title') }}</div>
                                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.site_settings.homepage.scope_notice_desc') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 rounded-2xl border border-gray-200 bg-white p-4">
                        <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.homepage.style_title') }}</div>
                        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                            @foreach (['accent_color', 'background_color', 'surface_color', 'text_color'] as $styleField)
                                <div>
                                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.style_'.$styleField) }}</label>
                                    <input type="text" name="homepage_style[{{ $styleField }}]" value="{{ $homepageFormStyle[$styleField] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="#2563eb">
                                </div>
                            @endforeach
                            <div>
                                <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.style_muted_color') }}</label>
                                <input type="text" name="homepage_style[muted_color]" value="{{ $homepageFormStyle['muted_color'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="#6b7280">
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.container_width') }}</label>
                                <select name="homepage_style[container_width]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    @foreach ($homepageContainerWidths as $option)
                                        <option value="{{ $option }}" @selected(($homepageFormStyle['container_width'] ?? 'default') === $option)>{{ __('admin.site_settings.homepage.container_width_'.$option) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.section_spacing') }}</label>
                                <select name="homepage_style[section_spacing]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    @foreach ($homepageSpacings as $option)
                                        <option value="{{ $option }}" @selected(($homepageFormStyle['section_spacing'] ?? 'normal') === $option)>{{ __('admin.site_settings.homepage.spacing_'.$option) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.radius') }}</label>
                                <select name="homepage_style[radius]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    @foreach ($homepageRadii as $option)
                                        <option value="{{ $option }}" @selected(($homepageFormStyle['radius'] ?? 'soft') === $option)>{{ __('admin.site_settings.homepage.radius_'.$option) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="homepage-module-list" class="mt-5 space-y-4">
                        @foreach ($homepageFormModules as $index => $module)
                            <div class="homepage-module-item rounded-2xl border border-gray-200 bg-white p-4" data-homepage-module-index="{{ $index }}">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.homepage.module_title', ['index' => $index + 1]) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.homepage.module_desc') }}</div>
                                    </div>
                                    <button type="button" class="remove-homepage-module inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                                        <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                                        {{ __('admin.button.delete') }}
                                    </button>
                                </div>
                                <input type="hidden" name="homepage_modules[{{ $index }}][id]" value="{{ $module['id'] ?? '' }}">
                                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
                                    <div>
                                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_type') }}</label>
                                        <select name="homepage_modules[{{ $index }}][type]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            @foreach ($homepageModuleTypes as $type)
                                                <option value="{{ $type }}" @selected(($module['type'] ?? 'rich_text') === $type)>{{ __('admin.site_settings.homepage.type_'.$type) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_layout') }}</label>
                                        <select name="homepage_modules[{{ $index }}][layout]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            @foreach ($homepageModuleLayouts as $layout)
                                                <option value="{{ $layout }}" @selected(($module['layout'] ?? 'single') === $layout)>{{ __('admin.site_settings.homepage.layout_'.$layout) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_source') }}</label>
                                        <select name="homepage_modules[{{ $index }}][data_source]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            @foreach ($homepageArticleSources as $source)
                                                <option value="{{ $source }}" @selected(($module['data_source'] ?? 'latest') === $source)>{{ __('admin.site_settings.homepage.source_'.$source) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_limit') }}</label>
                                            <input type="number" min="1" max="12" name="homepage_modules[{{ $index }}][limit]" value="{{ $module['limit'] ?? 4 }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_sort') }}</label>
                                            <input type="number" min="0" max="10000" name="homepage_modules[{{ $index }}][sort_order]" value="{{ $module['sort_order'] ?? (($index + 1) * 10) }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                    </div>
                                </div>
                                <details class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                                    <summary class="cursor-pointer text-sm font-semibold text-gray-700">{{ __('admin.site_settings.homepage.module_style_title') }}</summary>
                                    <p class="mt-2 text-xs text-gray-500">{{ __('admin.site_settings.homepage.module_style_desc') }}</p>
                                    <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-5">
                                        <div>
                                            <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_alignment') }}</label>
                                            <select name="homepage_modules[{{ $index }}][alignment]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @foreach ($homepageAlignments as $alignment)
                                                    <option value="{{ $alignment }}" @selected(($module['alignment'] ?? 'left') === $alignment)>{{ __('admin.site_settings.homepage.alignment_'.$alignment) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_accent_color') }}</label>
                                            <input type="text" name="homepage_modules[{{ $index }}][accent_color]" value="{{ $module['accent_color'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="#2563eb">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_surface_color') }}</label>
                                            <input type="text" name="homepage_modules[{{ $index }}][surface_color]" value="{{ $module['surface_color'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="#ffffff">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_text_color') }}</label>
                                            <input type="text" name="homepage_modules[{{ $index }}][text_color]" value="{{ $module['text_color'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="#111827">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_muted_color') }}</label>
                                            <input type="text" name="homepage_modules[{{ $index }}][muted_color]" value="{{ $module['muted_color'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="#6b7280">
                                        </div>
                                    </div>
                                </details>
                                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_title') }}</label>
                                        <input type="text" name="homepage_modules[{{ $index }}][title]" value="{{ $module['title'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_subtitle') }}</label>
                                        <input type="text" name="homepage_modules[{{ $index }}][subtitle]" value="{{ $module['subtitle'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_body') }}</label>
                                    <textarea name="homepage_modules[{{ $index }}][body]" rows="3" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.homepage.placeholder_body') }}">{{ $module['body'] ?? '' }}</textarea>
                                </div>
                                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                                    <div>
                                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_image_url') }}</label>
                                        <input type="text" name="homepage_modules[{{ $index }}][image_url]" value="{{ $module['image_url'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="/storage/hero.jpg">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_link_text') }}</label>
                                        <input type="text" name="homepage_modules[{{ $index }}][link_text]" value="{{ $module['link_text'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_link_url') }}</label>
                                        <input type="text" name="homepage_modules[{{ $index }}][link_url]" value="{{ $module['link_url'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="/category/demo">
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_lead_form') }}</label>
                                    <select name="homepage_modules[{{ $index }}][lead_form_slug]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">{{ __('admin.site_settings.homepage.lead_form_none') }}</option>
                                        @foreach ($leadForms as $leadForm)
                                            <option value="{{ $leadForm->slug }}" @selected(($module['lead_form_slug'] ?? '') === $leadForm->slug)>{{ $leadForm->name }} (/forms/{{ $leadForm->slug }})</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.homepage.lead_form_help') }}</p>
                                </div>
                                <div class="mt-4">
                                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_custom_html') }}</label>
                                    <textarea name="homepage_modules[{{ $index }}][custom_html]" rows="3" class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="<p>HTML snippet</p>">{{ $module['custom_html'] ?? '' }}</textarea>
                                </div>
                                <label class="mt-4 flex items-center gap-2 text-sm font-medium text-gray-700">
                                    <input type="checkbox" name="homepage_modules[{{ $index }}][enabled]" value="1" @checked(!empty($module['enabled'])) class="rounded border-gray-300 text-blue-600">
                                    {{ __('admin.site_settings.homepage.field_enabled') }}
                                </label>
                            </div>
                        @endforeach
                    </div>

                    <div id="homepage-module-empty" class="{{ !empty($homepageFormModules) ? 'hidden ' : '' }}mt-5 rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-8 text-center">
                        <div class="text-base font-medium text-gray-900">{{ __('admin.site_settings.homepage.empty_title') }}</div>
                        <div class="mt-2 text-sm text-gray-500">{{ __('admin.site_settings.homepage.empty_desc') }}</div>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.site-settings.theme') }}" class="space-y-5">
                    @csrf

                    @php
                        $currentThemeLabel = __('admin.site_settings.theme.default_name');
                        $canEditThemeFiles = auth('admin')->user()?->isSuperAdmin() === true;
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
                                        @if ($canEditThemeFiles)
                                            <a href="{{ route('admin.site-settings.theme-editor.preview', ['themeId' => $themeOption['id'], 'page' => 'home'], false) }}" target="_blank" rel="noopener" onclick="event.stopPropagation();" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-600 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700">{{ __('admin.site_settings.theme.preview_home') }}</a>
                                            <a href="{{ route('admin.site-settings.theme-editor.preview', ['themeId' => $themeOption['id'], 'page' => 'category'], false) }}" target="_blank" rel="noopener" onclick="event.stopPropagation();" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-600 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700">{{ __('admin.site_settings.theme.preview_category') }}</a>
                                            <a href="{{ route('admin.site-settings.theme-editor.preview', ['themeId' => $themeOption['id'], 'page' => 'article'], false) }}" target="_blank" rel="noopener" onclick="event.stopPropagation();" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-600 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700">{{ __('admin.site_settings.theme.preview_article') }}</a>
                                            <a href="{{ route('admin.site-settings.theme-editor.edit', ['themeId' => $themeOption['id'], 'page' => 'home'], false) }}" onclick="event.stopPropagation();" class="inline-flex items-center rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100">{{ __('admin.site_settings.theme.editor_home') }}</a>
                                            <a href="{{ route('admin.site-settings.theme-editor.edit', ['themeId' => $themeOption['id'], 'page' => 'category'], false) }}" onclick="event.stopPropagation();" class="inline-flex items-center rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100">{{ __('admin.site_settings.theme.editor_category') }}</a>
                                            <a href="{{ route('admin.site-settings.theme-editor.edit', ['themeId' => $themeOption['id'], 'page' => 'article'], false) }}" onclick="event.stopPropagation();" class="inline-flex items-center rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100">{{ __('admin.site_settings.theme.editor_article') }}</a>
                                        @else
                                            <span class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-500">{{ __('admin.site_settings.theme.preview_home') }}</span>
                                            <span class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-500">{{ __('admin.site_settings.theme.preview_category') }}</span>
                                            <span class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-500">{{ __('admin.site_settings.theme.preview_article') }}</span>
                                        @endif
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

        <details id="site-settings-ads" class="mb-6 bg-white shadow rounded-lg overflow-hidden group">
            <summary class="px-6 py-5 border-b border-gray-200 flex items-center justify-between gap-4 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                <div class="flex min-w-0 items-start gap-4">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-md bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100 sm:inline-flex">
                        <i data-lucide="megaphone" class="h-5 w-5"></i>
                    </span>
                    <div class="min-w-0 max-w-3xl">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.site_settings.ads.section_title') }}</h3>
                        <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.site_settings.module_ads_desc') }}</p>
                    </div>
                </div>
                <i data-lucide="chevron-down" class="w-5 h-5 shrink-0 text-gray-400 transition-transform duration-200 group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <div class="px-6 py-6">
                <form method="POST" action="{{ route('admin.site-settings.ads') }}" id="article-ad-form" class="space-y-6">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h4 class="text-base font-semibold text-gray-900">{{ __('admin.site_settings.ads.sticky_section_title') }}</h4>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.site_settings.ads.sticky_section_desc') }}</p>
                        </div>
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

                @php($textAdModules = is_array(old('text_ad_modules')) ? old('text_ad_modules') : $articleDetailTextAds)
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
                        @foreach ($textAdModules as $index => $textAd)
                            @php($links = is_array($textAd['links'] ?? null) ? $textAd['links'] : [])
                            <div class="article-text-ad-item rounded-2xl border border-gray-200 bg-white p-5 shadow-sm" data-text-ad-index="{{ $index }}">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">{{ $textAd['name'] !== '' ? $textAd['name'] : __('admin.site_settings.ads.text_default_name', ['index' => $index + 1]) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            {{ __('admin.site_settings.ads.text_card_desc') }}
                                            <span class="ml-2 rounded-full bg-blue-50 px-2 py-0.5 text-blue-700" data-text-ad-link-count>{{ __('admin.site_settings.ads.text_link_count', ['count' => count($links), 'max' => 10]) }}</span>
                                        </div>
                                    </div>
                                    <button type="button" class="remove-article-text-ad inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                                        <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                                        {{ __('admin.button.delete') }}
                                    </button>
                                </div>

                                <input type="hidden" name="text_ad_modules[{{ $index }}][id]" value="{{ $textAd['id'] ?? '' }}">

                                <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-4">
                                    <div class="lg:col-span-2">
                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_name') }}</label>
                                        <input type="text" name="text_ad_modules[{{ $index }}][name]" value="{{ $textAd['name'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_name') }}">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_position') }}</label>
                                        <select name="text_ad_modules[{{ $index }}][placement]" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            <option value="content_top" @selected(($textAd['placement'] ?? 'content_top') === 'content_top')>{{ __('admin.site_settings.ads.text_position_top') }}</option>
                                            <option value="content_bottom" @selected(($textAd['placement'] ?? 'content_top') === 'content_bottom')>{{ __('admin.site_settings.ads.text_position_bottom') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_sort') }}</label>
                                        <input type="number" min="0" max="10000" name="text_ad_modules[{{ $index }}][sort_order]" value="{{ $textAd['sort_order'] ?? (($index + 1) * 10) }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                </div>

                                <div class="mt-5 flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">{{ __('admin.site_settings.ads.text_enabled') }}</div>
                                        <div class="text-xs text-gray-500">{{ __('admin.site_settings.ads.text_module_enabled_help') }}</div>
                                    </div>
                                    <input type="checkbox" name="text_ad_modules[{{ $index }}][enabled]" value="1" @checked(!empty($textAd['enabled'])) class="rounded border-gray-300 text-blue-600">
                                </div>

                                <div class="mt-5 rounded-2xl border border-gray-200 bg-gray-50/70 p-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.ads.text_link_section') }}</div>
                                            <div class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.ads.text_link_section_desc') }}</div>
                                        </div>
                                        <button type="button" class="add-article-text-ad-link inline-flex items-center rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50">
                                            <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                                            {{ __('admin.site_settings.ads.text_add_link') }}
                                        </button>
                                    </div>

                                    <div class="mt-4 space-y-4" data-text-ad-links>
                                        @foreach ($links as $linkIndex => $link)
                                            <div class="article-text-ad-link-item rounded-xl border border-gray-200 bg-white p-4" data-text-ad-link-index="{{ $linkIndex }}">
                                                <div class="flex items-center justify-between gap-3">
                                                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.site_settings.ads.text_link_item', ['index' => $linkIndex + 1]) }}</div>
                                                    <button type="button" class="remove-article-text-ad-link text-sm font-medium text-red-600 hover:text-red-700">{{ __('admin.button.delete') }}</button>
                                                </div>
                                                <input type="hidden" name="text_ad_modules[{{ $index }}][links][{{ $linkIndex }}][id]" value="{{ $link['id'] ?? '' }}">
                                                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
                                                    <div class="lg:col-span-2">
                                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_text') }}</label>
                                                        <input type="text" name="text_ad_modules[{{ $index }}][links][{{ $linkIndex }}][text]" value="{{ $link['text'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_text') }}">
                                                    </div>
                                                    <div class="lg:col-span-2">
                                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_url') }}</label>
                                                        <input type="text" name="text_ad_modules[{{ $index }}][links][{{ $linkIndex }}][url]" value="{{ $link['url'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_url') }}">
                                                    </div>
                                                </div>
                                                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
                                                    <div>
                                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_color') }}</label>
                                                        <div class="flex overflow-hidden rounded-md border border-gray-300 bg-white shadow-sm focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500">
                                                            <input type="color" value="{{ $link['text_color'] ?? '#2563eb' }}" class="h-10 w-12 border-0 bg-white p-1" aria-label="{{ __('admin.site_settings.ads.text_field_color') }}">
                                                            <input type="text" name="text_ad_modules[{{ $index }}][links][{{ $linkIndex }}][text_color]" value="{{ $link['text_color'] ?? '#2563eb' }}" class="min-w-0 flex-1 border-0 px-3 py-2 focus:ring-0" placeholder="#2563eb">
                                                        </div>
                                                    </div>
                                                    <div class="lg:col-span-2">
                                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_tracking') }}</label>
                                                        <input type="text" name="text_ad_modules[{{ $index }}][links][{{ $linkIndex }}][tracking_param]" value="{{ $link['tracking_param'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="utm_source=geoflow&utm_medium=article_text_ad">
                                                    </div>
                                                    <div>
                                                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_sort') }}</label>
                                                        <input type="number" min="0" max="10000" name="text_ad_modules[{{ $index }}][links][{{ $linkIndex }}][sort_order]" value="{{ $link['sort_order'] ?? (($linkIndex + 1) * 10) }}" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                    </div>
                                                </div>
                                                <div class="mt-4 grid grid-cols-3 gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                                                    <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                                                        <input type="checkbox" name="text_ad_modules[{{ $index }}][links][{{ $linkIndex }}][open_new_tab]" value="1" @checked(!empty($link['open_new_tab'])) class="rounded border-gray-300 text-blue-600">
                                                        {{ __('admin.site_settings.ads.text_open_new_tab') }}
                                                    </label>
                                                    <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                                                        <input type="checkbox" name="text_ad_modules[{{ $index }}][links][{{ $linkIndex }}][tracking_enabled]" value="1" @checked(!empty($link['tracking_enabled'])) class="rounded border-gray-300 text-blue-600">
                                                        {{ __('admin.site_settings.ads.text_tracking_enabled') }}
                                                    </label>
                                                    <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                                                        <input type="checkbox" name="text_ad_modules[{{ $index }}][links][{{ $linkIndex }}][enabled]" value="1" @checked(!empty($link['enabled'])) class="rounded border-gray-300 text-blue-600">
                                                        {{ __('admin.site_settings.ads.text_enabled') }}
                                                    </label>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div id="article-text-ad-empty" class="{{ !empty($textAdModules) ? 'hidden ' : '' }}rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center">
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
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-site-settings-target]').forEach(function (trigger) {
                trigger.addEventListener('click', function (event) {
                    const targetId = trigger.getAttribute('data-site-settings-target');
                    const target = targetId ? document.getElementById(targetId) : null;

                    if (!target) {
                        return;
                    }

                    event.preventDefault();
                    target.open = true;
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });

                    if (window.history && window.history.pushState) {
                        window.history.pushState(null, '', '#' + targetId);
                    }
                });
            });
        });
    </script>

    <template id="homepage-module-template">
        <div class="homepage-module-item rounded-2xl border border-gray-200 bg-white p-4" data-homepage-module-index="__INDEX__">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.homepage.module_title', ['index' => '__NUMBER__']) }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.homepage.module_desc') }}</div>
                </div>
                <button type="button" class="remove-homepage-module inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.button.delete') }}
                </button>
            </div>
            <input type="hidden" name="homepage_modules[__INDEX__][id]" value="">
            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_type') }}</label>
                    <select name="homepage_modules[__INDEX__][type]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @foreach ($homepageModuleTypes as $type)
                            <option value="{{ $type }}" @selected($type === 'rich_text')>{{ __('admin.site_settings.homepage.type_'.$type) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_layout') }}</label>
                    <select name="homepage_modules[__INDEX__][layout]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @foreach ($homepageModuleLayouts as $layout)
                            <option value="{{ $layout }}" @selected($layout === 'single')>{{ __('admin.site_settings.homepage.layout_'.$layout) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_source') }}</label>
                    <select name="homepage_modules[__INDEX__][data_source]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @foreach ($homepageArticleSources as $source)
                            <option value="{{ $source }}" @selected($source === 'latest')>{{ __('admin.site_settings.homepage.source_'.$source) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_limit') }}</label>
                        <input type="number" min="1" max="12" name="homepage_modules[__INDEX__][limit]" value="4" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_sort') }}</label>
                        <input type="number" min="0" max="10000" name="homepage_modules[__INDEX__][sort_order]" value="__SORT__" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                </div>
            </div>
            <details class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-gray-700">{{ __('admin.site_settings.homepage.module_style_title') }}</summary>
                <p class="mt-2 text-xs text-gray-500">{{ __('admin.site_settings.homepage.module_style_desc') }}</p>
                <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-5">
                    <div>
                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_alignment') }}</label>
                        <select name="homepage_modules[__INDEX__][alignment]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach ($homepageAlignments as $alignment)
                                <option value="{{ $alignment }}" @selected($alignment === 'left')>{{ __('admin.site_settings.homepage.alignment_'.$alignment) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_accent_color') }}</label>
                        <input type="text" name="homepage_modules[__INDEX__][accent_color]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="#2563eb">
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_surface_color') }}</label>
                        <input type="text" name="homepage_modules[__INDEX__][surface_color]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="#ffffff">
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_text_color') }}</label>
                        <input type="text" name="homepage_modules[__INDEX__][text_color]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="#111827">
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_muted_color') }}</label>
                        <input type="text" name="homepage_modules[__INDEX__][muted_color]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="#6b7280">
                    </div>
                </div>
            </details>
            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_title') }}</label>
                    <input type="text" name="homepage_modules[__INDEX__][title]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_subtitle') }}</label>
                    <input type="text" name="homepage_modules[__INDEX__][subtitle]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
            </div>
            <div class="mt-4">
                <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_body') }}</label>
                <textarea name="homepage_modules[__INDEX__][body]" rows="3" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.homepage.placeholder_body') }}"></textarea>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_image_url') }}</label>
                    <input type="text" name="homepage_modules[__INDEX__][image_url]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="/storage/hero.jpg">
                </div>
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_link_text') }}</label>
                    <input type="text" name="homepage_modules[__INDEX__][link_text]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_link_url') }}</label>
                    <input type="text" name="homepage_modules[__INDEX__][link_url]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="/category/demo">
                </div>
            </div>
            <div class="mt-4">
                <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_lead_form') }}</label>
                <select name="homepage_modules[__INDEX__][lead_form_slug]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.site_settings.homepage.lead_form_none') }}</option>
                    @foreach ($leadForms as $leadForm)
                        <option value="{{ $leadForm->slug }}">{{ $leadForm->name }} (/forms/{{ $leadForm->slug }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.homepage.lead_form_help') }}</p>
            </div>
            <div class="mt-4">
                <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_custom_html') }}</label>
                <textarea name="homepage_modules[__INDEX__][custom_html]" rows="3" class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="<p>HTML snippet</p>"></textarea>
            </div>
            <label class="mt-4 flex items-center gap-2 text-sm font-medium text-gray-700">
                <input type="checkbox" name="homepage_modules[__INDEX__][enabled]" value="1" checked class="rounded border-gray-300 text-blue-600">
                {{ __('admin.site_settings.homepage.field_enabled') }}
            </label>
        </div>
    </template>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const list = document.getElementById('homepage-module-list');
            const emptyState = document.getElementById('homepage-module-empty');
            const addButton = document.getElementById('add-homepage-module');
            const template = document.getElementById('homepage-module-template');

            if (!list || !emptyState || !addButton || !template) {
                return;
            }

            let index = list.querySelectorAll('.homepage-module-item').length;
            const maxModules = 30;

            function refreshState() {
                const count = list.querySelectorAll('.homepage-module-item').length;
                emptyState.classList.toggle('hidden', count > 0);
                addButton.disabled = count >= maxModules;
                addButton.classList.toggle('opacity-50', count >= maxModules);
                addButton.classList.toggle('cursor-not-allowed', count >= maxModules);
            }

            function bindRemove(scope) {
                const button = scope.querySelector('.remove-homepage-module');
                if (!button) {
                    return;
                }

                button.addEventListener('click', function () {
                    scope.remove();
                    refreshState();
                });
            }

            addButton.addEventListener('click', function () {
                if (list.querySelectorAll('.homepage-module-item').length >= maxModules) {
                    return;
                }

                const wrapper = document.createElement('div');
                wrapper.innerHTML = template.innerHTML
                    .replaceAll('__INDEX__', String(index))
                    .replaceAll('__NUMBER__', String(index + 1))
                    .replaceAll('__SORT__', String((index + 1) * 10))
                    .trim();
                index += 1;

                const item = wrapper.firstElementChild;
                if (!item) {
                    return;
                }

                list.appendChild(item);
                bindRemove(item);
                refreshState();

                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });

            list.querySelectorAll('.homepage-module-item').forEach(bindRemove);
            refreshState();
        });
    </script>

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

    <template id="article-text-ad-link-template">
        <div class="article-text-ad-link-item rounded-xl border border-gray-200 bg-white p-4" data-text-ad-link-index="__LINK_INDEX__">
            <div class="flex items-center justify-between gap-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.site_settings.ads.text_link_item', ['index' => '__LINK_NUMBER__']) }}</div>
                <button type="button" class="remove-article-text-ad-link text-sm font-medium text-red-600 hover:text-red-700">{{ __('admin.button.delete') }}</button>
            </div>
            <input type="hidden" name="text_ad_modules[__INDEX__][links][__LINK_INDEX__][id]" value="">
            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_text') }}</label>
                    <input type="text" name="text_ad_modules[__INDEX__][links][__LINK_INDEX__][text]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_text') }}">
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_url') }}</label>
                    <input type="text" name="text_ad_modules[__INDEX__][links][__LINK_INDEX__][url]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_url') }}">
                </div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_color') }}</label>
                    <div class="flex overflow-hidden rounded-md border border-gray-300 bg-white shadow-sm focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500">
                        <input type="color" value="#2563eb" class="h-10 w-12 border-0 bg-white p-1" aria-label="{{ __('admin.site_settings.ads.text_field_color') }}">
                        <input type="text" name="text_ad_modules[__INDEX__][links][__LINK_INDEX__][text_color]" value="#2563eb" class="min-w-0 flex-1 border-0 px-3 py-2 focus:ring-0" placeholder="#2563eb">
                    </div>
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_tracking') }}</label>
                    <input type="text" name="text_ad_modules[__INDEX__][links][__LINK_INDEX__][tracking_param]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="utm_source=geoflow&utm_medium=article_text_ad">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_sort') }}</label>
                    <input type="number" min="0" max="10000" name="text_ad_modules[__INDEX__][links][__LINK_INDEX__][sort_order]" value="__LINK_SORT__" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
            </div>
            <div class="mt-4 grid grid-cols-3 gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                    <input type="checkbox" name="text_ad_modules[__INDEX__][links][__LINK_INDEX__][open_new_tab]" value="1" checked class="rounded border-gray-300 text-blue-600">
                    {{ __('admin.site_settings.ads.text_open_new_tab') }}
                </label>
                <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                    <input type="checkbox" name="text_ad_modules[__INDEX__][links][__LINK_INDEX__][tracking_enabled]" value="1" checked class="rounded border-gray-300 text-blue-600">
                    {{ __('admin.site_settings.ads.text_tracking_enabled') }}
                </label>
                <label class="flex items-center gap-2 text-xs font-medium text-gray-700">
                    <input type="checkbox" name="text_ad_modules[__INDEX__][links][__LINK_INDEX__][enabled]" value="1" checked class="rounded border-gray-300 text-blue-600">
                    {{ __('admin.site_settings.ads.text_enabled') }}
                </label>
            </div>
        </div>
    </template>

    <template id="article-text-ad-template">
        <div class="article-text-ad-item rounded-2xl border border-gray-200 bg-white p-5 shadow-sm" data-text-ad-index="__INDEX__">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.ads.text_new_slot') }}</div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ __('admin.site_settings.ads.text_card_desc') }}
                        <span class="ml-2 rounded-full bg-blue-50 px-2 py-0.5 text-blue-700" data-text-ad-link-count>{{ __('admin.site_settings.ads.text_link_count', ['count' => 1, 'max' => 10]) }}</span>
                    </div>
                </div>
                <button type="button" class="remove-article-text-ad inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.button.delete') }}
                </button>
            </div>

            <input type="hidden" name="text_ad_modules[__INDEX__][id]" value="">

            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_name') }}</label>
                    <input type="text" name="text_ad_modules[__INDEX__][name]" value="" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.ads.text_placeholder_name') }}">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_position') }}</label>
                    <select name="text_ad_modules[__INDEX__][placement]" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="content_top">{{ __('admin.site_settings.ads.text_position_top') }}</option>
                        <option value="content_bottom">{{ __('admin.site_settings.ads.text_position_bottom') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_sort') }}</label>
                    <input type="number" min="0" max="10000" name="text_ad_modules[__INDEX__][sort_order]" value="__SORT__" class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
            </div>

            <div class="mt-5 flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                <div>
                    <div class="text-sm font-medium text-gray-900">{{ __('admin.site_settings.ads.text_enabled') }}</div>
                    <div class="text-xs text-gray-500">{{ __('admin.site_settings.ads.text_module_enabled_help') }}</div>
                </div>
                <input type="checkbox" name="text_ad_modules[__INDEX__][enabled]" value="1" checked class="rounded border-gray-300 text-blue-600">
            </div>

            <div class="mt-5 rounded-2xl border border-gray-200 bg-gray-50/70 p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.ads.text_link_section') }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.ads.text_link_section_desc') }}</div>
                    </div>
                    <button type="button" class="add-article-text-ad-link inline-flex items-center rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50">
                        <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.site_settings.ads.text_add_link') }}
                    </button>
                </div>
                <div class="mt-4 space-y-4" data-text-ad-links>
                    __DEFAULT_LINK__
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
            const textAdLinkTemplate = document.getElementById('article-text-ad-link-template');

            if (!textAdList || !textAdEmptyState || !textAdAddButton || !textAdTemplate || !textAdLinkTemplate) {
                return;
            }

            let textAdIndex = textAdList.querySelectorAll('.article-text-ad-item').length;
            const maxLinks = 10;

            function refreshTextAdState() {
                textAdEmptyState.classList.toggle('hidden', textAdList.querySelectorAll('.article-text-ad-item').length > 0);
            }

            function textAdLinkCountLabel(count) {
                return @json(__('admin.site_settings.ads.text_link_count', ['count' => '__COUNT__', 'max' => 10])).replace('__COUNT__', String(count));
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

            function nextLinkIndex(scope) {
                let max = -1;
                scope.querySelectorAll('.article-text-ad-link-item').forEach(function (item) {
                    max = Math.max(max, Number(item.getAttribute('data-text-ad-link-index') || -1));
                });

                return max + 1;
            }

            function refreshTextAdLinks(scope) {
                const links = scope.querySelectorAll('.article-text-ad-link-item');
                const addLinkButton = scope.querySelector('.add-article-text-ad-link');
                const countBadge = scope.querySelector('[data-text-ad-link-count]');
                if (countBadge) {
                    countBadge.textContent = textAdLinkCountLabel(links.length);
                }
                if (addLinkButton) {
                    addLinkButton.disabled = links.length >= maxLinks;
                    addLinkButton.classList.toggle('opacity-50', links.length >= maxLinks);
                    addLinkButton.classList.toggle('cursor-not-allowed', links.length >= maxLinks);
                }
            }

            function buildTextAdLink(moduleIndex, linkIndex) {
                const linkSort = (linkIndex + 1) * 10;

                return textAdLinkTemplate.innerHTML
                    .replaceAll('__INDEX__', String(moduleIndex))
                    .replaceAll('__LINK_INDEX__', String(linkIndex))
                    .replaceAll('__LINK_NUMBER__', String(linkIndex + 1))
                    .replaceAll('__LINK_SORT__', String(linkSort))
                    .trim();
            }

            function bindTextAdLinkRemove(scope, link) {
                const removeButton = link.querySelector('.remove-article-text-ad-link');
                if (!removeButton) {
                    return;
                }

                removeButton.addEventListener('click', function () {
                    link.remove();
                    refreshTextAdLinks(scope);
                });
            }

            function bindTextAdAddLink(scope) {
                const addLinkButton = scope.querySelector('.add-article-text-ad-link');
                const linksContainer = scope.querySelector('[data-text-ad-links]');
                if (!addLinkButton || !linksContainer) {
                    return;
                }

                addLinkButton.addEventListener('click', function () {
                    if (scope.querySelectorAll('.article-text-ad-link-item').length >= maxLinks) {
                        return;
                    }

                    const moduleIndex = scope.getAttribute('data-text-ad-index') || '0';
                    const linkIndex = nextLinkIndex(scope);
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = buildTextAdLink(moduleIndex, linkIndex);
                    const linkItem = wrapper.firstElementChild;
                    if (!linkItem) {
                        return;
                    }

                    linksContainer.appendChild(linkItem);
                    bindTextAdLinkRemove(scope, linkItem);
                    bindColorPicker(linkItem);
                    refreshTextAdLinks(scope);

                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                });
            }

            function bindTextAdModule(scope) {
                bindTextAdRemove(scope);
                bindTextAdAddLink(scope);
                bindColorPicker(scope);
                scope.querySelectorAll('.article-text-ad-link-item').forEach(function (link) {
                    bindTextAdLinkRemove(scope, link);
                });
                refreshTextAdLinks(scope);
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
                const defaultLink = buildTextAdLink(textAdIndex, 0);
                wrapper.innerHTML = textAdTemplate.innerHTML
                    .replaceAll('__INDEX__', String(textAdIndex))
                    .replaceAll('__SORT__', String((textAdIndex + 1) * 10))
                    .replace('__DEFAULT_LINK__', defaultLink)
                    .trim();
                textAdIndex += 1;

                const textAdItem = wrapper.firstElementChild;
                if (!textAdItem) {
                    return;
                }

                textAdList.appendChild(textAdItem);
                bindTextAdModule(textAdItem);
                refreshTextAdState();

                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });

            textAdList.querySelectorAll('.article-text-ad-item').forEach(function (item) {
                bindTextAdModule(item);
            });
            refreshTextAdState();
        });
    </script>
@endpush
