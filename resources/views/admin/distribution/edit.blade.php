@extends('admin.layouts.app')

@php
    $remoteSettings = $remoteSiteSettings ?? $channel->resolvedSiteSettings();
    $themes = $availableThemes ?? [];
    $selectedTheme = old('template_key', (string) ($channel->template_key ?? ''));
    $frontMode = old('front_mode', method_exists($channel, 'frontMode') ? $channel->frontMode() : ((string) ($channel->front_mode ?? 'static')));
    $channelType = $channel->channelType();
    $channelConfig = $channel->resolvedChannelConfig();
    $genericConfig = $channel->resolvedGenericHttpConfig();
    $articleTextAds = $articleDetailTextAds ?? [];
    $articleTextAdPolicy = \App\Models\DistributionChannel::normalizeArticleTextAdPolicy(old('article_text_ad_policy', $articleTextAdPolicy ?? $channel->resolvedArticleTextAdPolicy()));
    $articleTextAdsByPlacement = collect($articleTextAds)->groupBy('placement');
    $articleTextAdPlacements = [
        'content_top' => __('admin.distribution.article_text_ads.placement_top'),
        'content_bottom' => __('admin.distribution.article_text_ads.placement_bottom'),
    ];
    $genericEndpointMethods = [
        'health' => ['GET', 'POST'],
        'publish' => ['POST', 'PUT', 'PATCH'],
        'update' => ['POST', 'PUT', 'PATCH'],
        'delete' => ['DELETE', 'POST'],
        'settings' => ['POST', 'PUT', 'PATCH'],
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.distribution.show', ['channelId' => (int) $channel->id]) }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.distribution.edit_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.edit_subtitle') }}</p>
            </div>
        </div>

        <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 px-5 py-4">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-blue-950">{{ __('admin.distribution.target_update.title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-blue-800">{{ __('admin.distribution.target_update.desc') }}</p>
                </div>
                <form method="POST" action="{{ route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]) }}" class="flex-none">
                    @csrf
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-blue-300 bg-white px-4 py-2 text-sm font-medium text-blue-800 shadow-sm hover:bg-blue-50 md:w-auto">
                        <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.distribution.button.update_target_site') }}
                    </button>
                </form>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="px-6 py-6">
                <form method="POST" action="{{ route('admin.distribution.update', ['channelId' => (int) $channel->id]) }}" class="space-y-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="channel_type" value="{{ $channelType }}">

                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.name') }} *</label>
                        <input id="name" name="name" type="text" required value="{{ old('name', (string) $channel->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.distribution.placeholder.name') }}">
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-900">{{ __('admin.distribution.field.channel_type') }}</div>
                        <div class="mt-2 inline-flex rounded-full bg-white px-3 py-1 text-sm font-medium text-gray-700 ring-1 ring-gray-200">
                            {{ __('admin.distribution.channel_type.'.$channelType) }}
                        </div>
                        <p class="mt-2 text-sm text-gray-600">{{ __('admin.distribution.help.channel_type_locked') }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="domain" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.domain') }} *</label>
                            <input id="domain" name="domain" type="text" required value="{{ old('domain', (string) $channel->domain) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="example.com">
                        </div>
                        <div>
                            <label for="endpoint_url" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.endpoint_url') }} *</label>
                            <input id="endpoint_url" name="endpoint_url" type="text" required value="{{ old('endpoint_url', (string) $channel->endpoint_url) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.distribution.placeholder.endpoint_url') }}">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.distribution.help.endpoint_url') }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.status') }}</label>
                            <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="active" @selected(old('status', (string) $channel->status) === 'active')>{{ __('admin.distribution.status.active') }}</option>
                                <option value="paused" @selected(old('status', (string) $channel->status) === 'paused')>{{ __('admin.distribution.status.paused') }}</option>
                            </select>
                        </div>
                    </div>

                    @if ($channel->isWordPressRest())
                        <div class="rounded-lg border border-blue-100 bg-blue-50 p-5">
                            <div class="mb-5">
                                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.wordpress.section_title') }}</h2>
                                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.distribution.wordpress.edit_section_desc') }}</p>
                            </div>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label for="wordpress_username" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.wordpress.username') }}</label>
                                    <input id="wordpress_username" name="wordpress_username" type="text" value="{{ old('wordpress_username', $channelConfig['wordpress_username']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="editor">
                                </div>
                                <div>
                                    <label for="wordpress_application_password" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.wordpress.application_password') }}</label>
                                    <input id="wordpress_application_password" name="wordpress_application_password" type="password" value="{{ old('wordpress_application_password') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" autocomplete="new-password" placeholder="{{ __('admin.distribution.wordpress.application_password_placeholder') }}">
                                    <p class="mt-1 text-xs text-gray-500">{{ __('admin.distribution.wordpress.application_password_update_help') }}</p>
                                </div>
                            </div>
                            <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label for="wordpress_post_status" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.wordpress.post_status') }}</label>
                                    <select id="wordpress_post_status" name="wordpress_post_status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        @foreach (['publish', 'draft', 'pending', 'private'] as $status)
                                            <option value="{{ $status }}" @selected(old('wordpress_post_status', $channelConfig['wordpress_post_status']) === $status)>{{ __('admin.distribution.wordpress.post_status_'.$status) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="wordpress_image_strategy" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.wordpress.image_strategy') }}</label>
                                    <select id="wordpress_image_strategy" name="wordpress_image_strategy" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="upload_to_media" @selected(old('wordpress_image_strategy', $channelConfig['wordpress_image_strategy']) === 'upload_to_media')>{{ __('admin.distribution.wordpress.image_upload_to_media') }}</option>
                                        <option value="keep_original" @selected(old('wordpress_image_strategy', $channelConfig['wordpress_image_strategy']) === 'keep_original')>{{ __('admin.distribution.wordpress.image_keep_original') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-3">
                                <div>
                                    <label for="wordpress_category_strategy" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.wordpress.category_strategy') }}</label>
                                    <select id="wordpress_category_strategy" name="wordpress_category_strategy" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="match_or_create" @selected(old('wordpress_category_strategy', $channelConfig['wordpress_category_strategy']) === 'match_or_create')>{{ __('admin.distribution.wordpress.category_match_or_create') }}</option>
                                        <option value="match_only" @selected(old('wordpress_category_strategy', $channelConfig['wordpress_category_strategy']) === 'match_only')>{{ __('admin.distribution.wordpress.category_match_only') }}</option>
                                        <option value="fixed" @selected(old('wordpress_category_strategy', $channelConfig['wordpress_category_strategy']) === 'fixed')>{{ __('admin.distribution.wordpress.category_fixed') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="wordpress_fixed_category" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.wordpress.fixed_category') }}</label>
                                    <input id="wordpress_fixed_category" name="wordpress_fixed_category" type="text" value="{{ old('wordpress_fixed_category', $channelConfig['wordpress_fixed_category']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="1 或 News">
                                </div>
                                <div>
                                    <label for="wordpress_tag_strategy" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.wordpress.tag_strategy') }}</label>
                                    <select id="wordpress_tag_strategy" name="wordpress_tag_strategy" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="keywords_to_tags" @selected(old('wordpress_tag_strategy', $channelConfig['wordpress_tag_strategy']) === 'keywords_to_tags')>{{ __('admin.distribution.wordpress.tag_keywords_to_tags') }}</option>
                                        <option value="disabled" @selected(old('wordpress_tag_strategy', $channelConfig['wordpress_tag_strategy']) === 'disabled')>{{ __('admin.distribution.wordpress.tag_disabled') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    @elseif ($channel->isGenericHttpApi())
                        <div class="rounded-lg border border-indigo-100 bg-indigo-50 p-5">
                            <div class="mb-5">
                                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.generic.section_title') }}</h2>
                                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.distribution.generic.edit_section_desc') }}</p>
                            </div>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                                <div>
                                    <label for="generic_auth_type" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.generic.auth_type') }}</label>
                                    <select id="generic_auth_type" name="generic_auth_type" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        @foreach (['bearer', 'none', 'basic', 'header_key', 'hmac'] as $authType)
                                            <option value="{{ $authType }}" @selected(old('generic_auth_type', $genericConfig['generic_auth_type']) === $authType)>{{ __('admin.distribution.generic.auth_'.$authType) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div data-generic-auth-row="basic">
                                    <label for="generic_basic_username" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.generic.basic_username') }}</label>
                                    <input id="generic_basic_username" name="generic_basic_username" type="text" value="{{ old('generic_basic_username', $genericConfig['generic_basic_username']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="api-user">
                                </div>
                                <div data-generic-auth-secret>
                                    <label for="generic_secret" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.generic.secret') }}</label>
                                    <input id="generic_secret" name="generic_secret" type="password" value="{{ old('generic_secret') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" autocomplete="new-password" placeholder="{{ __('admin.distribution.generic.secret_placeholder') }}">
                                    <p class="mt-1 text-xs text-gray-500">{{ __('admin.distribution.generic.secret_update_help') }}</p>
                                </div>
                            </div>

                            <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-3">
                                <div data-generic-auth-row="header_key">
                                    <label for="generic_header_name" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.generic.header_name') }}</label>
                                    <input id="generic_header_name" name="generic_header_name" type="text" value="{{ old('generic_header_name', $genericConfig['generic_header_name']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="generic_timeout_seconds" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.generic.timeout_seconds') }}</label>
                                    <input id="generic_timeout_seconds" name="generic_timeout_seconds" type="number" min="5" max="120" value="{{ old('generic_timeout_seconds', $genericConfig['generic_timeout_seconds']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="generic_success_statuses" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.generic.success_statuses') }}</label>
                                    <input id="generic_success_statuses" name="generic_success_statuses" type="text" value="{{ old('generic_success_statuses', implode(',', $genericConfig['generic_success_statuses'])) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                            </div>

                            <div class="mt-6 rounded-lg border border-indigo-100 bg-white p-4">
                                <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.distribution.generic.endpoint_section') }}</h3>
                                <p class="mt-1 text-xs leading-5 text-gray-500">{{ __('admin.distribution.generic.endpoint_help') }}</p>
                                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                                    @foreach ([
                                        ['generic_health_method', 'generic_health_path', 'health'],
                                        ['generic_publish_method', 'generic_publish_path', 'publish'],
                                        ['generic_update_method', 'generic_update_path', 'update'],
                                        ['generic_delete_method', 'generic_delete_path', 'delete'],
                                        ['generic_settings_method', 'generic_settings_path', 'settings'],
                                    ] as [$methodName, $pathName, $labelKey])
                                        <div class="grid grid-cols-3 gap-3">
                                            <div>
                                                <label for="{{ $methodName }}" class="block text-xs font-medium text-gray-600">{{ __('admin.distribution.generic.endpoint_'.$labelKey) }}</label>
                                                <select id="{{ $methodName }}" name="{{ $methodName }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @foreach ($genericEndpointMethods[$labelKey] as $method)
                                                    <option value="{{ $method }}" @selected(old($methodName, $genericConfig[$methodName]) === $method)>{{ $method }}</option>
                                                @endforeach
                                                </select>
                                            </div>
                                            <div class="col-span-2">
                                                <label for="{{ $pathName }}" class="block text-xs font-medium text-gray-600">{{ __('admin.distribution.generic.path') }}</label>
                                                <input id="{{ $pathName }}" name="{{ $pathName }}" type="text" value="{{ old($pathName, $genericConfig[$pathName]) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-3">
                                <div>
                                    <label for="generic_payload_wrapper" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.generic.payload_wrapper') }}</label>
                                    <select id="generic_payload_wrapper" name="generic_payload_wrapper" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="none" @selected(old('generic_payload_wrapper', $genericConfig['generic_payload_wrapper']) === 'none')>{{ __('admin.distribution.generic.wrapper_none') }}</option>
                                        <option value="data" @selected(old('generic_payload_wrapper', $genericConfig['generic_payload_wrapper']) === 'data')>{{ __('admin.distribution.generic.wrapper_data') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="generic_remote_id_path" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.generic.remote_id_path') }}</label>
                                    <input id="generic_remote_id_path" name="generic_remote_id_path" type="text" value="{{ old('generic_remote_id_path', $genericConfig['generic_remote_id_path']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="data.id">
                                </div>
                                <div>
                                    <label for="generic_remote_url_path" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.generic.remote_url_path') }}</label>
                                    <input id="generic_remote_url_path" name="generic_remote_url_path" type="text" value="{{ old('generic_remote_url_path', $genericConfig['generic_remote_url_path']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="data.url">
                                </div>
                            </div>
                        </div>
                    @elseif ($channel->isGeoFlowAgent())
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

                        @include('admin.distribution._rewrite-rules', ['channel' => $channel])
                    @endif

                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-5">
                        <div class="mb-5">
                            <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.remote_site.section_title') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.distribution.remote_site.section_desc') }}</p>
                        </div>

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="site_name" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_site_name') }}</label>
                                <input id="site_name" name="site_name" type="text" value="{{ old('site_name', $remoteSettings['site_name']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.placeholder_site_name') }}">
                            </div>
                            <div>
                                <label for="site_subtitle" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_subtitle') }}</label>
                                <input id="site_subtitle" name="site_subtitle" type="text" value="{{ old('site_subtitle', $remoteSettings['site_subtitle']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.placeholder_subtitle') }}">
                            </div>
                        </div>

                        <div class="mt-6">
                            <label for="site_description" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_description') }}</label>
                            <textarea id="site_description" name="site_description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.placeholder_description') }}">{{ old('site_description', $remoteSettings['site_description']) }}</textarea>
                        </div>

                        <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="site_keywords" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_keywords') }}</label>
                                <input id="site_keywords" name="site_keywords" type="text" value="{{ old('site_keywords', $remoteSettings['site_keywords']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.site_settings.placeholder_keywords') }}">
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.keywords_help') }}</p>
                            </div>
                            <div>
                                <label for="copyright_info" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_copyright') }}</label>
                                <input id="copyright_info" name="copyright_info" type="text" value="{{ old('copyright_info', $remoteSettings['copyright_info']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="© 2026 Site Name">
                            </div>
                        </div>

                        <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="site_logo" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_logo') }}</label>
                                <input id="site_logo" name="site_logo" type="url" value="{{ old('site_logo', $remoteSettings['site_logo']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="https://example.com/logo.png">
                            </div>
                            <div>
                                <label for="site_favicon" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_favicon') }}</label>
                                <input id="site_favicon" name="site_favicon" type="url" value="{{ old('site_favicon', $remoteSettings['site_favicon']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="https://example.com/favicon.ico">
                            </div>
                        </div>

                        <div class="mt-6 border-t border-gray-200 pt-5">
                            <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.section_seo') }}</h3>
                            <div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label for="seo_title_template" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_seo_title_template') }}</label>
                                    <input id="seo_title_template" name="seo_title_template" type="text" value="{{ old('seo_title_template', $remoteSettings['seo_title_template']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{title} - {site_name}">
                                    <p class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.seo_title_help') }}</p>
                                </div>
                                <div>
                                    <label for="seo_description_template" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_seo_description_template') }}</label>
                                    <input id="seo_description_template" name="seo_description_template" type="text" value="{{ old('seo_description_template', $remoteSettings['seo_description_template']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{description}">
                                    <p class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.seo_description_help') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="featured_limit" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_featured_limit') }}</label>
                                <input id="featured_limit" name="featured_limit" type="number" min="1" max="100" value="{{ old('featured_limit', $remoteSettings['featured_limit']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="per_page" class="block text-sm font-medium text-gray-700">{{ __('admin.site_settings.field_per_page') }}</label>
                                <input id="per_page" name="per_page" type="number" min="1" max="200" value="{{ old('per_page', $remoteSettings['per_page']) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>

                        @if ($channel->isGeoFlowAgent())
                            <div class="mt-6 border-t border-gray-200 pt-5">
                                <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.theme.section_title') }}</h3>
                                <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.remote_site.theme_help') }}</p>
                                <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                                    <label class="flex cursor-pointer gap-3 rounded-lg border border-gray-200 bg-white p-4 hover:border-blue-200">
                                        <input type="radio" name="template_key" value="" class="mt-1 text-blue-600 focus:ring-blue-500" @checked($selectedTheme === '')>
                                        <span>
                                            <span class="block text-sm font-semibold text-gray-900">{{ __('admin.site_settings.theme.default_name') }}</span>
                                            <span class="mt-1 block text-sm text-gray-600">{{ __('admin.site_settings.theme.default_desc') }}</span>
                                        </span>
                                    </label>
                                    @foreach ($themes as $themeOption)
                                        <label class="flex cursor-pointer gap-3 rounded-lg border border-gray-200 bg-white p-4 hover:border-blue-200">
                                            <input type="radio" name="template_key" value="{{ $themeOption['id'] }}" class="mt-1 text-blue-600 focus:ring-blue-500" @checked($selectedTheme === $themeOption['id'])>
                                            <span class="min-w-0">
                                                <span class="flex flex-wrap items-center gap-2">
                                                    <span class="text-sm font-semibold text-gray-900">{{ $themeOption['name'] }}</span>
                                                    @if ($themeOption['version'] !== '')
                                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ __('admin.site_settings.theme.version_badge', ['version' => $themeOption['version']]) }}</span>
                                                    @endif
                                                </span>
                                                <span class="mt-1 block text-sm leading-6 text-gray-600">{{ $themeOption['description'] !== '' ? $themeOption['description'] : __('admin.site_settings.theme.no_description') }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <div class="mb-5">
                            <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.article_text_ads.section_title') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.distribution.article_text_ads.section_desc') }}</p>
                        </div>

                        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                            @foreach ($articleTextAdPlacements as $placement => $placementLabel)
                                @php
                                    $placementPolicy = $articleTextAdPolicy[$placement] ?? ['mode' => 'inherit', 'ad_ids' => []];
                                    $placementMode = (string) ($placementPolicy['mode'] ?? 'inherit');
                                    $selectedAdIds = $placementPolicy['ad_ids'] ?? [];
                                    $placementAds = $articleTextAdsByPlacement->get($placement, collect())->values();
                                @endphp
                                <fieldset class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <legend class="text-sm font-semibold text-gray-900">{{ $placementLabel }}</legend>
                                    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                                        @foreach (['inherit', 'selected', 'disabled'] as $mode)
                                            <label class="flex cursor-pointer items-start gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm hover:border-blue-200">
                                                <input
                                                    type="radio"
                                                    name="article_text_ad_policy[{{ $placement }}][mode]"
                                                    value="{{ $mode }}"
                                                    class="mt-0.5 text-blue-600 focus:ring-blue-500"
                                                    data-article-text-ad-mode="{{ $placement }}"
                                                    @checked($placementMode === $mode)
                                                >
                                                <span class="font-medium text-gray-800">{{ __('admin.distribution.article_text_ads.mode_'.$mode) }}</span>
                                            </label>
                                        @endforeach
                                    </div>

                                    <div class="mt-4 {{ $placementMode === 'selected' ? '' : 'hidden' }}" data-article-text-ad-selected="{{ $placement }}">
                                        @if ($placementAds->isEmpty())
                                            <div class="rounded-md border border-dashed border-gray-300 bg-white px-4 py-4 text-sm text-gray-500">
                                                {{ __('admin.distribution.article_text_ads.empty') }}
                                            </div>
                                        @else
                                            <div class="space-y-2">
                                                @foreach ($placementAds as $textAd)
                                                    @php
                                                        $textAdId = (string) ($textAd['id'] ?? '');
                                                        $enabled = ! empty($textAd['enabled']);
                                                    @endphp
                                                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 bg-white px-3 py-3 hover:border-blue-200">
                                                        <input
                                                            type="checkbox"
                                                            name="article_text_ad_policy[{{ $placement }}][ad_ids][]"
                                                            value="{{ $textAdId }}"
                                                            class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                            @checked(in_array($textAdId, $selectedAdIds, true))
                                                            @disabled(! $enabled)
                                                        >
                                                        <span class="min-w-0">
                                                            <span class="flex flex-wrap items-center gap-2">
                                                                <span class="text-sm font-semibold text-gray-900">{{ $textAd['name'] !== '' ? $textAd['name'] : $textAd['text'] }}</span>
                                                                @unless ($enabled)
                                                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">{{ __('admin.distribution.article_text_ads.disabled_badge') }}</span>
                                                                @endunless
                                                            </span>
                                                            <span class="mt-1 block truncate text-sm text-gray-600">{{ $textAd['text'] }}</span>
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>

                        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                            {{ __('admin.distribution.article_text_ads.package_hint') }}
                        </div>
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700">{{ __('admin.common.description') }}</label>
                        <textarea id="description" name="description" rows="4" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.distribution.placeholder.description') }}">{{ old('description', (string) ($channel->description ?? '')) }}</textarea>
                    </div>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.distribution.show', ['channelId' => (int) $channel->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.cancel') }}</a>
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.distribution.button.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        function toggleGenericAuthFields() {
            var select = document.getElementById('generic_auth_type');
            if (!select) {
                return;
            }
            var authType = select.value;
            document.querySelectorAll('[data-generic-auth-row]').forEach(function (field) {
                field.classList.toggle('hidden', field.dataset.genericAuthRow !== authType);
            });
            document.querySelectorAll('[data-generic-auth-secret]').forEach(function (field) {
                field.classList.toggle('hidden', authType === 'none');
            });
        }
        document.addEventListener('change', function (event) {
            if (event.target.matches('#generic_auth_type')) {
                toggleGenericAuthFields();
            }
            if (event.target.matches('[data-article-text-ad-mode]')) {
                toggleArticleTextAdPolicyFields();
            }
        });
        toggleGenericAuthFields();

        function toggleArticleTextAdPolicyFields() {
            document.querySelectorAll('[data-article-text-ad-selected]').forEach(function (panel) {
                var placement = panel.getAttribute('data-article-text-ad-selected');
                var selectedMode = document.querySelector('[data-article-text-ad-mode="' + placement + '"]:checked');
                panel.classList.toggle('hidden', !selectedMode || selectedMode.value !== 'selected');
            });
        }

        toggleArticleTextAdPolicyFields();
    </script>
@endsection
