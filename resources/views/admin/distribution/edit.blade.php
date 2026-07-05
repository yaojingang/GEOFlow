@extends('admin.layouts.app')

@php
    $remoteSettings = $remoteSiteSettings ?? $channel->resolvedSiteSettings();
    $themes = $availableThemes ?? [];
    $selectedTheme = old('template_key', (string) ($channel->template_key ?? ''));
    $visibleThemeLimit = 6;
    $themeOptions = array_merge([[
        'id' => '',
        'name' => __('admin.site_settings.theme.default_name'),
        'version' => '',
        'description' => __('admin.site_settings.theme.default_desc'),
    ]], $themes);
    $collapsedThemeCount = collect($themeOptions)
        ->filter(fn (array $themeOption, int $themeIndex): bool => $themeIndex >= $visibleThemeLimit && $selectedTheme !== $themeOption['id'])
        ->count();
    $frontMode = old('front_mode', method_exists($channel, 'frontMode') ? $channel->frontMode() : ((string) ($channel->front_mode ?? 'static')));
    $channelType = $channel->channelType();
    $channelConfig = $channel->resolvedChannelConfig();
    $genericConfig = $channel->resolvedGenericHttpConfig();
    $frontendExperienceMode = old('frontend_experience_mode', $frontendExperienceMode ?? $channel->frontendExperienceMode());
    $frontendExperienceModes = $frontendExperienceModes ?? \App\Models\DistributionChannel::frontendExperienceModes();
    $frontendExperienceReport = $frontendExperienceReport ?? [];
    $channelFrontendReport = $frontendExperienceReport['channel'] ?? [];
    $targetPackageCapabilities = $frontendExperienceReport['target_package'] ?? [];
    $remoteTargetCapabilities = $frontendExperienceReport['remote_target'] ?? [];
    $frontendSyncSummary = $channelFrontendReport['sync_summary'] ?? [];
    $frontendDifferences = $frontendExperienceReport['differences'] ?? [];
    $supportedFrontendModules = $targetPackageCapabilities['supported_modules'] ?? \App\Support\Site\HomepageModuleBuilder::TYPES;
    $remoteSupportedModules = is_array($remoteTargetCapabilities['supported_modules'] ?? null) ? $remoteTargetCapabilities['supported_modules'] : [];
    $remoteSupportedRoutes = is_array($remoteTargetCapabilities['supported_routes'] ?? null) ? $remoteTargetCapabilities['supported_routes'] : [];
    $remoteStatus = (string) ($remoteTargetCapabilities['status'] ?? 'not_checked');
    $remoteStatusCopy = [
        'ok' => ['label' => '已检查', 'class' => 'border-emerald-200 bg-emerald-50 text-emerald-800'],
        'missing_secret' => ['label' => '缺少密钥', 'class' => 'border-amber-200 bg-amber-50 text-amber-800'],
        'unsupported_or_not_found' => ['label' => '旧包或未暴露', 'class' => 'border-amber-200 bg-amber-50 text-amber-800'],
        'unavailable' => ['label' => '不可达', 'class' => 'border-red-200 bg-red-50 text-red-800'],
        'not_applicable' => ['label' => '不适用', 'class' => 'border-gray-200 bg-gray-50 text-gray-700'],
        'not_checked' => ['label' => '未检查', 'class' => 'border-gray-200 bg-gray-50 text-gray-700'],
    ][$remoteStatus] ?? ['label' => $remoteStatus, 'class' => 'border-gray-200 bg-gray-50 text-gray-700'];
    $homepageStyleJson = old('homepage_style_json', json_encode($remoteSettings['homepage_style'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $homepageModulesJson = old('homepage_modules_json', json_encode($remoteSettings['homepage_modules'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $homeCarouselSlidesJson = old('home_carousel_slides_json', json_encode($remoteSettings['home_carousel_slides'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $articleTextAds = $articleDetailTextAds ?? [];
    $articleTextAdPolicy = \App\Models\DistributionChannel::normalizeArticleTextAdPolicy(old('article_text_ad_policy', $articleTextAdPolicy ?? $channel->resolvedArticleTextAdPolicy()));
    $articleTextAdsByPlacement = collect($articleTextAds)->groupBy('placement');
    $articleTextAdCustomModuleLimit = 5;
    $articleTextAdLinkLimit = \App\Support\Site\ArticleTextAdPicker::MAX_LINKS_PER_MODULE;
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
                <div class="flex flex-col gap-2 sm:flex-row">
                    @if ($channel->isGeoFlowAgent())
                        <form method="POST" action="{{ route('admin.distribution.frontend-capabilities.refresh', ['channelId' => (int) $channel->id]) }}" class="flex-none">
                            @csrf
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-blue-300 bg-white px-4 py-2 text-sm font-medium text-blue-800 shadow-sm hover:bg-blue-50 md:w-auto">
                                <i data-lucide="radar" class="mr-2 h-4 w-4"></i>
                                刷新远端能力
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('admin.distribution.sync-settings.preview', ['channelId' => (int) $channel->id]) }}" class="inline-flex w-full items-center justify-center rounded-md border border-blue-300 bg-white px-4 py-2 text-sm font-medium text-blue-800 shadow-sm hover:bg-blue-50 md:w-auto">
                        <i data-lucide="scan-search" class="mr-2 h-4 w-4"></i>
                        查看同步预览
                    </a>
                </div>
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
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.theme.section_title') }}</h3>
                                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.remote_site.theme_help') }}</p>
                                    </div>
                                    @if ($collapsedThemeCount > 0)
                                        <button
                                            type="button"
                                            class="inline-flex shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                                            data-distribution-theme-toggle
                                            data-expand-label="{{ __('admin.distribution.remote_site.template_expand_more', ['count' => $collapsedThemeCount]) }}"
                                            data-collapse-label="{{ __('admin.distribution.remote_site.template_collapse') }}"
                                            aria-expanded="false"
                                        >
                                            {{ __('admin.distribution.remote_site.template_expand_more', ['count' => $collapsedThemeCount]) }}
                                        </button>
                                    @endif
                                </div>
                                <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                                    @foreach ($themeOptions as $themeIndex => $themeOption)
                                        @php
                                            $isCollapsedTheme = $themeIndex >= $visibleThemeLimit && $selectedTheme !== $themeOption['id'];
                                            $themeCardClasses = 'flex cursor-pointer gap-3 rounded-lg border border-gray-200 bg-white p-4 hover:border-blue-200'.($isCollapsedTheme ? ' hidden' : '');
                                        @endphp
                                        <label
                                            class="{{ $themeCardClasses }}"
                                            data-distribution-theme-card
                                            data-distribution-theme-collapsed="{{ $themeIndex >= $visibleThemeLimit ? 'true' : 'false' }}"
                                        >
                                            <input type="radio" name="template_key" value="{{ $themeOption['id'] }}" class="mt-1 text-blue-600 focus:ring-blue-500" @checked($selectedTheme === $themeOption['id'])>
                                            <span class="min-w-0">
                                                <span class="flex flex-wrap items-center gap-2">
                                                    <span class="text-sm font-semibold text-gray-900">{{ $themeOption['name'] }}</span>
                                                    @if (($themeOption['version'] ?? '') !== '')
                                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ __('admin.site_settings.theme.version_badge', ['version' => $themeOption['version']]) }}</span>
                                                    @endif
                                                </span>
                                                <span class="mt-1 block text-sm leading-6 text-gray-600">{{ ($themeOption['description'] ?? '') !== '' ? $themeOption['description'] : __('admin.site_settings.theme.no_description') }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mt-6 border-t border-gray-200 pt-5">
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-900">前台体验</h3>
                                        <p class="mt-1 text-sm leading-6 text-gray-600">管理这个 GeoFlow Agent 目标站点的首页模块、样式、轮播与默认站同步关系。</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2 text-xs">
                                        <span class="rounded-full bg-blue-50 px-2.5 py-1 font-medium text-blue-700">能力版本 {{ $targetPackageCapabilities['capability_version'] ?? '1.1' }}</span>
                                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-700">{{ count($supportedFrontendModules) }} 类模块</span>
                                    </div>
                                </div>

                                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                                        <div class="text-xs font-medium text-gray-500">当前模式</div>
                                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $frontendExperienceMode }}</div>
                                    </div>
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                                        <div class="text-xs font-medium text-gray-500">首页模块</div>
                                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ (int) ($frontendSyncSummary['homepage_modules_count'] ?? 0) }} 个</div>
                                    </div>
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                                        <div class="text-xs font-medium text-gray-500">轮播</div>
                                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ (int) ($frontendSyncSummary['home_carousel_slides_count'] ?? 0) }} 张</div>
                                    </div>
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                                        <div class="text-xs font-medium text-gray-500">样式 token</div>
                                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ count($frontendSyncSummary['homepage_style_keys'] ?? []) }} 个</div>
                                    </div>
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                                        <div class="text-xs font-medium text-gray-500">文字广告</div>
                                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ (int) ($frontendSyncSummary['article_text_ads_count'] ?? 0) }} 个</div>
                                    </div>
                                </div>

                                <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                                    <div class="rounded-lg border {{ $remoteStatusCopy['class'] }} px-4 py-3">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <div class="text-sm font-semibold">远端能力状态：{{ $remoteStatusCopy['label'] }}</div>
                                                <p class="mt-1 text-sm leading-6">
                                                    @if ($remoteStatus === 'ok')
                                                        目标包 {{ $remoteTargetCapabilities['package_version'] ?? 'unknown' }}，能力版本 {{ $remoteTargetCapabilities['capability_version'] ?? 'unknown' }}。
                                                        @if (($remoteTargetCapabilities['checked_at'] ?? '') !== '')
                                                            最近检查 {{ $remoteTargetCapabilities['checked_at'] }}。
                                                        @endif
                                                    @else
                                                        {{ $remoteTargetCapabilities['message'] ?? '远端能力尚未读取。' }}
                                                    @endif
                                                </p>
                                            </div>
                                            @if ($remoteStatus === 'ok')
                                                <div class="flex flex-wrap gap-2 text-xs">
                                                    <span class="rounded-full bg-white/70 px-2.5 py-1 font-medium">{{ count($remoteSupportedModules) }} 类模块</span>
                                                    <span class="rounded-full bg-white/70 px-2.5 py-1 font-medium">{{ count($remoteSupportedRoutes) }} 条路由</span>
                                                </div>
                                            @endif
                                        </div>
                                        @if ($remoteStatus === 'ok')
                                            <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                                                <div>
                                                    <dt class="text-xs font-medium opacity-70">远端主题</dt>
                                                    <dd class="mt-0.5 font-semibold">{{ $remoteTargetCapabilities['active_theme'] ?: '默认主题' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-medium opacity-70">远端 front_mode</dt>
                                                    <dd class="mt-0.5 font-semibold">{{ $remoteTargetCapabilities['front_mode'] ?: '未声明' }}</dd>
                                                </div>
                                            </dl>
                                        @endif
                                    </div>

                                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                                        <div class="text-sm font-semibold text-gray-900">同步前差异摘要</div>
                                        <dl class="mt-3 grid grid-cols-1 gap-2 text-sm text-gray-700 sm:grid-cols-2">
                                            <div>
                                                <dt class="text-xs font-medium text-gray-500">主题</dt>
                                                <dd class="mt-0.5 font-semibold text-gray-900">{{ ($frontendSyncSummary['active_theme'] ?? '') !== '' ? $frontendSyncSummary['active_theme'] : '默认主题' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-xs font-medium text-gray-500">front_mode</dt>
                                                <dd class="mt-0.5 font-semibold text-gray-900">{{ $frontendSyncSummary['front_mode'] ?? $frontMode }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-xs font-medium text-gray-500">首页模块/轮播</dt>
                                                <dd class="mt-0.5 font-semibold text-gray-900">{{ (int) ($frontendSyncSummary['homepage_modules_count'] ?? 0) }} / {{ (int) ($frontendSyncSummary['home_carousel_slides_count'] ?? 0) }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-xs font-medium text-gray-500">文字广告</dt>
                                                <dd class="mt-0.5 font-semibold text-gray-900">{{ (int) ($frontendSyncSummary['article_text_ads_count'] ?? 0) }}</dd>
                                            </div>
                                        </dl>
                                        @if (! empty($frontendDifferences))
                                            <ul class="mt-3 space-y-2 text-sm leading-6 text-gray-700">
                                                @foreach ($frontendDifferences as $difference)
                                                    <li class="flex gap-2">
                                                        <span class="mt-2 h-1.5 w-1.5 flex-none rounded-full {{ ($difference['severity'] ?? '') === 'warning' ? 'bg-amber-500' : ((($difference['severity'] ?? '') === 'ok') ? 'bg-emerald-500' : 'bg-blue-500') }}"></span>
                                                        <span>{{ $difference['message'] ?? '' }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                </div>

                                @if ($errors->has('homepage_style_json') || $errors->has('homepage_modules_json') || $errors->has('home_carousel_slides_json'))
                                    <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                                        {{ $errors->first('homepage_style_json') ?: ($errors->first('homepage_modules_json') ?: $errors->first('home_carousel_slides_json')) }}
                                    </div>
                                @endif

                                <fieldset class="mt-4">
                                    <legend class="sr-only">前台体验模式</legend>
                                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                                        @foreach ($frontendExperienceModes as $mode)
                                            @php
                                                $modeCopy = [
                                                    'inherit_default' => ['label' => '跟随默认站', 'desc' => '同步时实时使用默认站首页模块和样式。'],
                                                    'snapshot_default' => ['label' => '复制默认站快照', 'desc' => '保存时复制默认站当前体验，之后独立维护。'],
                                                    'custom' => ['label' => '渠道自定义', 'desc' => '完全使用下方 JSON 配置管理该渠道前台。'],
                                                ][$mode] ?? ['label' => $mode, 'desc' => ''];
                                            @endphp
                                            <label class="flex cursor-pointer gap-3 rounded-lg border border-gray-200 bg-white p-4 hover:border-blue-200">
                                                <input type="radio" name="frontend_experience_mode" value="{{ $mode }}" class="mt-1 text-blue-600 focus:ring-blue-500" @checked($frontendExperienceMode === $mode)>
                                                <span>
                                                    <span class="block text-sm font-semibold text-gray-900">{{ $modeCopy['label'] }}</span>
                                                    <span class="mt-1 block text-sm leading-6 text-gray-600">{{ $modeCopy['desc'] }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>

                                <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-900">
                                    支持模块：{{ implode('、', $supportedFrontendModules) }}。WordPress REST 和 Generic API 只透传字段，不保证渲染 GEOFlow 模块。
                                </div>

                                <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
                                    <div>
                                        <label for="homepage_style_json" class="block text-sm font-medium text-gray-700">首页样式 JSON</label>
                                        <textarea id="homepage_style_json" name="homepage_style_json" rows="10" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ $homepageStyleJson }}</textarea>
                                    </div>
                                    <div>
                                        <label for="home_carousel_slides_json" class="block text-sm font-medium text-gray-700">首页轮播 JSON</label>
                                        <textarea id="home_carousel_slides_json" name="home_carousel_slides_json" rows="10" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ $homeCarouselSlidesJson }}</textarea>
                                    </div>
                                    <div class="xl:col-span-2">
                                        <label for="homepage_modules_json" class="block text-sm font-medium text-gray-700">首页模块 JSON</label>
                                        <textarea id="homepage_modules_json" name="homepage_modules_json" rows="16" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ $homepageModulesJson }}</textarea>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    @if (! $channel->isGeoFlowAgent())
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900">
                            WordPress REST 和 Generic API 只作为外部分发渠道处理，可接收字段透传，不保证渲染 GEOFlow 首页模块、轮播或主题映射。
                        </div>
                    @endif

                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <div class="mb-5">
                            <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.article_text_ads.section_title') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.distribution.article_text_ads.section_desc') }}</p>
                        </div>

                        <div class="space-y-5">
                            @foreach ($articleTextAdPlacements as $placement => $placementLabel)
                                @php
                                    $placementPolicy = $articleTextAdPolicy[$placement] ?? ['mode' => 'inherit', 'module_ids' => [], 'ad_ids' => [], 'custom_modules' => []];
                                    $placementMode = (string) ($placementPolicy['mode'] ?? 'inherit');
                                    $selectedModuleIds = $placementPolicy['module_ids'] ?? [];
                                    $legacySelectedAdIds = $placementPolicy['ad_ids'] ?? [];
                                    $customModules = is_array($placementPolicy['custom_modules'] ?? null) ? $placementPolicy['custom_modules'] : [];
                                    $placementAds = $articleTextAdsByPlacement->get($placement, collect())->values();
                                @endphp
                                <fieldset class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <legend class="text-sm font-semibold text-gray-900">{{ $placementLabel }}</legend>
                                    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                        @foreach (['inherit', 'selected', 'custom', 'disabled'] as $mode)
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
                                                        $links = is_array($textAd['links'] ?? null) ? $textAd['links'] : [];
                                                        $enabledLinks = collect($links)->filter(fn ($link) => is_array($link) && ! empty($link['enabled']))->values();
                                                        $firstLinkText = (string) (($enabledLinks->first()['text'] ?? '') ?: ($links[0]['text'] ?? ''));
                                                        $enabled = ! empty($textAd['enabled']);
                                                        $checked = in_array($textAdId, $selectedModuleIds, true) || ($selectedModuleIds === [] && \App\Support\Site\ArticleTextAdPicker::moduleOrLinkMatchesIds($textAd, $legacySelectedAdIds));
                                                    @endphp
                                                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 bg-white px-3 py-3 hover:border-blue-200">
                                                        <input
                                                            type="checkbox"
                                                            name="article_text_ad_policy[{{ $placement }}][module_ids][]"
                                                            value="{{ $textAdId }}"
                                                            class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                            @checked($checked)
                                                            @disabled(! $enabled)
                                                        >
                                                        <span class="min-w-0">
                                                            <span class="flex flex-wrap items-center gap-2">
                                                                <span class="text-sm font-semibold text-gray-900">{{ $textAd['name'] !== '' ? $textAd['name'] : $firstLinkText }}</span>
                                                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">{{ __('admin.site_settings.ads.text_link_count', ['count' => count($links), 'max' => $articleTextAdLinkLimit]) }}</span>
                                                                @unless ($enabled)
                                                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">{{ __('admin.distribution.article_text_ads.disabled_badge') }}</span>
                                                                @endunless
                                                            </span>
                                                            <span class="mt-1 block truncate text-sm text-gray-600">{{ $firstLinkText }}</span>
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <div class="mt-4 {{ $placementMode === 'custom' ? '' : 'hidden' }}" data-article-text-ad-custom="{{ $placement }}">
                                        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <p class="text-sm text-gray-600">{{ __('admin.distribution.article_text_ads.custom_desc', ['max' => $articleTextAdCustomModuleLimit]) }}</p>
                                            <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-md border border-blue-200 bg-white px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50" data-add-channel-text-ad-module="{{ $placement }}">
                                                <i data-lucide="plus" class="mr-1.5 h-4 w-4"></i>
                                                {{ __('admin.distribution.article_text_ads.custom_add_module') }}
                                            </button>
                                        </div>
                                        <div class="space-y-3" data-channel-text-ad-modules="{{ $placement }}" data-next-module-index="{{ count($customModules) }}">
                                            @foreach ($customModules as $moduleIndex => $module)
                                                @php
                                                    $moduleLinks = is_array($module['links'] ?? null) ? $module['links'] : [];
                                                @endphp
                                                <div class="rounded-lg border border-gray-200 bg-white p-4" data-channel-text-ad-module>
                                                    <input type="hidden" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][id]" value="{{ (string) ($module['id'] ?? '') }}">
                                                    <input type="hidden" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][placement]" value="{{ $placement }}">
                                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                                        <div class="grid min-w-0 flex-1 grid-cols-1 gap-3 md:grid-cols-3">
                                                            <div>
                                                                <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_name') }}</label>
                                                                <input type="text" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][name]" value="{{ (string) ($module['name'] ?? '') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                            </div>
                                                            <div>
                                                                <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_sort') }}</label>
                                                                <input type="number" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][sort_order]" value="{{ (int) ($module['sort_order'] ?? (($loop->index + 1) * 10)) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                            </div>
                                                            <label class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                                                                <input type="checkbox" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][enabled]" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" @checked(! empty($module['enabled']))>
                                                                {{ __('admin.site_settings.ads.text_enabled') }}
                                                            </label>
                                                        </div>
                                                        <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50" data-remove-channel-text-ad-module>
                                                            <i data-lucide="trash-2" class="mr-1.5 h-4 w-4"></i>
                                                            {{ __('admin.button.delete') }}
                                                        </button>
                                                    </div>

                                                    <div class="mt-4 border-t border-gray-100 pt-4">
                                                        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                            <div>
                                                                <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.ads.text_link_section') }}</div>
                                                                <div class="text-xs text-gray-500">{{ __('admin.site_settings.ads.text_link_section_desc') }}</div>
                                                            </div>
                                                            <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50" data-add-channel-text-ad-link>
                                                                <i data-lucide="plus" class="mr-1.5 h-4 w-4"></i>
                                                                {{ __('admin.site_settings.ads.text_add_link') }}
                                                            </button>
                                                        </div>
                                                        <div class="space-y-3" data-channel-text-ad-links data-next-link-index="{{ count($moduleLinks) }}">
                                                            @foreach ($moduleLinks as $linkIndex => $link)
                                                                <div class="rounded-md border border-gray-200 bg-gray-50 p-3" data-channel-text-ad-link>
                                                                    <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                                        <span class="text-sm font-semibold text-gray-800" data-channel-text-ad-link-title>{{ __('admin.site_settings.ads.text_link_item', ['index' => $loop->iteration]) }}</span>
                                                                        <button type="button" class="text-sm font-medium text-red-600 hover:text-red-700" data-remove-channel-text-ad-link>{{ __('admin.button.delete') }}</button>
                                                                    </div>
                                                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                                        <div>
                                                                            <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_text') }}</label>
                                                                            <input type="text" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][links][{{ $linkIndex }}][text]" value="{{ (string) ($link['text'] ?? '') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                                        </div>
                                                                        <div>
                                                                            <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_url') }}</label>
                                                                            <input type="text" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][links][{{ $linkIndex }}][url]" value="{{ (string) ($link['url'] ?? '') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                                        </div>
                                                                        <div>
                                                                            <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_color') }}</label>
                                                                            <input type="text" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][links][{{ $linkIndex }}][text_color]" value="{{ (string) ($link['text_color'] ?? '#2563eb') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                                        </div>
                                                                        <div>
                                                                            <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_sort') }}</label>
                                                                            <input type="number" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][links][{{ $linkIndex }}][sort_order]" value="{{ (int) ($link['sort_order'] ?? (($loop->index + 1) * 10)) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                                        </div>
                                                                    </div>
                                                                    <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-4">
                                                                        <div class="lg:col-span-2">
                                                                            <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_tracking') }}</label>
                                                                            <input type="text" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][links][{{ $linkIndex }}][tracking_param]" value="{{ (string) ($link['tracking_param'] ?? '') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                                        </div>
                                                                        <label class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                                                                            <input type="checkbox" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][links][{{ $linkIndex }}][open_new_tab]" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" @checked(! empty($link['open_new_tab']))>
                                                                            {{ __('admin.site_settings.ads.text_open_new_tab') }}
                                                                        </label>
                                                                        <label class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                                                                            <input type="checkbox" name="article_text_ad_policy[{{ $placement }}][custom_modules][{{ $moduleIndex }}][links][{{ $linkIndex }}][enabled]" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" @checked(! empty($link['enabled']))>
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
    <template id="channel-text-ad-link-template">
        <div class="rounded-md border border-gray-200 bg-gray-50 p-3" data-channel-text-ad-link>
            <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <span class="text-sm font-semibold text-gray-800" data-channel-text-ad-link-title>{{ __('admin.site_settings.ads.text_link_item', ['index' => '__LINK_NUMBER__']) }}</span>
                <button type="button" class="text-sm font-medium text-red-600 hover:text-red-700" data-remove-channel-text-ad-link>{{ __('admin.button.delete') }}</button>
            </div>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_text') }}</label>
                    <input type="text" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][links][__LINK_INDEX__][text]" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_url') }}</label>
                    <input type="text" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][links][__LINK_INDEX__][url]" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_color') }}</label>
                    <input type="text" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][links][__LINK_INDEX__][text_color]" value="#2563eb" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_sort') }}</label>
                    <input type="number" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][links][__LINK_INDEX__][sort_order]" value="__LINK_SORT__" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
            </div>
            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_tracking') }}</label>
                    <input type="text" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][links][__LINK_INDEX__][tracking_param]" value="utm_source=geoflow&utm_medium=article_text_ad" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <label class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                    <input type="checkbox" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][links][__LINK_INDEX__][open_new_tab]" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" checked>
                    {{ __('admin.site_settings.ads.text_open_new_tab') }}
                </label>
                <label class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                    <input type="checkbox" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][links][__LINK_INDEX__][enabled]" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" checked>
                    {{ __('admin.site_settings.ads.text_enabled') }}
                </label>
            </div>
        </div>
    </template>
    <template id="channel-text-ad-module-template">
        <div class="rounded-lg border border-gray-200 bg-white p-4" data-channel-text-ad-module>
            <input type="hidden" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][id]" value="">
            <input type="hidden" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][placement]" value="__PLACEMENT__">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="grid min-w-0 flex-1 grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_name') }}</label>
                        <input type="text" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][name]" value="{{ __('admin.distribution.article_text_ads.custom_default_name') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('admin.site_settings.ads.text_field_sort') }}</label>
                        <input type="number" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][sort_order]" value="__MODULE_SORT__" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <label class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                        <input type="checkbox" name="article_text_ad_policy[__PLACEMENT__][custom_modules][__MODULE_INDEX__][enabled]" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" checked>
                        {{ __('admin.site_settings.ads.text_enabled') }}
                    </label>
                </div>
                <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50" data-remove-channel-text-ad-module>
                    <i data-lucide="trash-2" class="mr-1.5 h-4 w-4"></i>
                    {{ __('admin.button.delete') }}
                </button>
            </div>
            <div class="mt-4 border-t border-gray-100 pt-4">
                <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.ads.text_link_section') }}</div>
                        <div class="text-xs text-gray-500">{{ __('admin.site_settings.ads.text_link_section_desc') }}</div>
                    </div>
                    <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50" data-add-channel-text-ad-link>
                        <i data-lucide="plus" class="mr-1.5 h-4 w-4"></i>
                        {{ __('admin.site_settings.ads.text_add_link') }}
                    </button>
                </div>
                <div class="space-y-3" data-channel-text-ad-links data-next-link-index="0"></div>
            </div>
        </div>
    </template>
    <script>
        var themeToggle = document.querySelector('[data-distribution-theme-toggle]');
        var themeExpanded = false;

        function refreshDistributionThemeCards() {
            document.querySelectorAll('[data-distribution-theme-card]').forEach(function (card) {
                var isCollapsed = card.dataset.distributionThemeCollapsed === 'true';
                var checkedInput = card.querySelector('input[type="radio"]:checked');
                card.classList.toggle('hidden', isCollapsed && !themeExpanded && !checkedInput);
            });
            if (themeToggle) {
                themeToggle.textContent = themeExpanded ? themeToggle.dataset.collapseLabel : themeToggle.dataset.expandLabel;
                themeToggle.setAttribute('aria-expanded', themeExpanded ? 'true' : 'false');
            }
        }

        if (themeToggle) {
            themeToggle.addEventListener('click', function () {
                themeExpanded = !themeExpanded;
                refreshDistributionThemeCards();
            });
        }

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
        document.addEventListener('click', function (event) {
            var addModuleButton = event.target.closest('[data-add-channel-text-ad-module]');
            if (addModuleButton) {
                addChannelTextAdModule(addModuleButton.getAttribute('data-add-channel-text-ad-module'));
                return;
            }

            var removeModuleButton = event.target.closest('[data-remove-channel-text-ad-module]');
            if (removeModuleButton) {
                var module = removeModuleButton.closest('[data-channel-text-ad-module]');
                if (module) {
                    var moduleList = module.parentElement;
                    module.remove();
                    syncChannelTextAdModuleList(moduleList);
                }
                return;
            }

            var addLinkButton = event.target.closest('[data-add-channel-text-ad-link]');
            if (addLinkButton) {
                addChannelTextAdLink(addLinkButton.closest('[data-channel-text-ad-module]'));
                return;
            }

            var removeLinkButton = event.target.closest('[data-remove-channel-text-ad-link]');
            if (removeLinkButton) {
                var link = removeLinkButton.closest('[data-channel-text-ad-link]');
                if (link) {
                    var links = link.parentElement;
                    link.remove();
                    syncChannelTextAdLinks(links);
                }
            }
        });
        toggleGenericAuthFields();
        refreshDistributionThemeCards();

        function toggleArticleTextAdPolicyFields() {
            document.querySelectorAll('[data-article-text-ad-selected]').forEach(function (panel) {
                var placement = panel.getAttribute('data-article-text-ad-selected');
                var selectedMode = document.querySelector('[data-article-text-ad-mode="' + placement + '"]:checked');
                panel.classList.toggle('hidden', !selectedMode || selectedMode.value !== 'selected');
            });
            document.querySelectorAll('[data-article-text-ad-custom]').forEach(function (panel) {
                var placement = panel.getAttribute('data-article-text-ad-custom');
                var selectedMode = document.querySelector('[data-article-text-ad-mode="' + placement + '"]:checked');
                panel.classList.toggle('hidden', !selectedMode || selectedMode.value !== 'custom');
            });
        }

        toggleArticleTextAdPolicyFields();

        function addChannelTextAdModule(placement) {
            var list = document.querySelector('[data-channel-text-ad-modules="' + placement + '"]');
            var template = document.getElementById('channel-text-ad-module-template');
            if (!list || !template || list.querySelectorAll('[data-channel-text-ad-module]').length >= {{ $articleTextAdCustomModuleLimit }}) {
                return;
            }

            var index = Number(list.dataset.nextModuleIndex || 0);
            list.dataset.nextModuleIndex = String(index + 1);
            var html = template.innerHTML
                .replaceAll('__PLACEMENT__', placement)
                .replaceAll('__MODULE_INDEX__', String(index))
                .replaceAll('__MODULE_SORT__', String((index + 1) * 10));
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            var module = wrapper.firstElementChild;
            list.appendChild(module);
            addChannelTextAdLink(module);
            syncChannelTextAdModuleList(list);
            if (window.lucide) {
                window.lucide.createIcons();
            }
        }

        function addChannelTextAdLink(module) {
            if (!module) {
                return;
            }

            var list = module.querySelector('[data-channel-text-ad-links]');
            var template = document.getElementById('channel-text-ad-link-template');
            if (!list || !template || list.querySelectorAll('[data-channel-text-ad-link]').length >= {{ $articleTextAdLinkLimit }}) {
                return;
            }

            var moduleInputs = module.querySelectorAll('input[name*="[custom_modules]"]');
            var moduleName = moduleInputs.length > 0 ? moduleInputs[0].getAttribute('name') : '';
            var match = moduleName.match(/article_text_ad_policy\[([^\]]+)\]\[custom_modules\]\[([^\]]+)\]/);
            if (!match) {
                return;
            }

            var placement = match[1];
            var moduleIndex = match[2];
            var index = Number(list.dataset.nextLinkIndex || 0);
            list.dataset.nextLinkIndex = String(index + 1);
            var html = template.innerHTML
                .replaceAll('__PLACEMENT__', placement)
                .replaceAll('__MODULE_INDEX__', moduleIndex)
                .replaceAll('__LINK_INDEX__', String(index))
                .replaceAll('__LINK_NUMBER__', String(list.querySelectorAll('[data-channel-text-ad-link]').length + 1))
                .replaceAll('__LINK_SORT__', String((index + 1) * 10));
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            list.appendChild(wrapper.firstElementChild);
            syncChannelTextAdLinks(list);
        }

        function syncChannelTextAdModuleList(list) {
            if (!list) {
                return;
            }
            var modules = list.querySelectorAll('[data-channel-text-ad-module]');
            var placement = list.getAttribute('data-channel-text-ad-modules');
            var addButton = document.querySelector('[data-add-channel-text-ad-module="' + placement + '"]');
            if (addButton) {
                addButton.disabled = modules.length >= {{ $articleTextAdCustomModuleLimit }};
                addButton.classList.toggle('opacity-60', addButton.disabled);
                addButton.classList.toggle('cursor-not-allowed', addButton.disabled);
            }
            modules.forEach(function (module) {
                syncChannelTextAdLinks(module.querySelector('[data-channel-text-ad-links]'));
            });
        }

        function syncChannelTextAdLinks(list) {
            if (!list) {
                return;
            }
            var links = list.querySelectorAll('[data-channel-text-ad-link]');
            links.forEach(function (link, index) {
                var title = link.querySelector('[data-channel-text-ad-link-title]');
                if (title) {
                    title.textContent = @json(__('admin.site_settings.ads.text_link_item', ['index' => '__INDEX__'])).replace('__INDEX__', String(index + 1));
                }
            });
            var addButton = list.closest('[data-channel-text-ad-module]')?.querySelector('[data-add-channel-text-ad-link]');
            if (addButton) {
                addButton.disabled = links.length >= {{ $articleTextAdLinkLimit }};
                addButton.classList.toggle('opacity-60', addButton.disabled);
                addButton.classList.toggle('cursor-not-allowed', addButton.disabled);
            }
        }

        document.querySelectorAll('[data-channel-text-ad-modules]').forEach(syncChannelTextAdModuleList);
    </script>
@endsection
