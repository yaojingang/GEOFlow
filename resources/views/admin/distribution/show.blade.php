@extends('admin.layouts.app')

@php
    $channelStatusKey = 'admin.distribution.status.'.(string) $channel->status;
    $channelStatusLabel = trans()->has($channelStatusKey) ? __($channelStatusKey) : (string) $channel->status;
    $healthStatus = (string) ($channel->last_health_status ?? '');
    $healthStatusKey = 'admin.distribution.health_status.'.$healthStatus;
    $healthStatusLabel = $healthStatus !== '' && trans()->has($healthStatusKey) ? __($healthStatusKey) : ($healthStatus !== '' ? $healthStatus : __('admin.common.none'));
    $canRevealSecret = auth('admin')->user() instanceof \App\Models\Admin && auth('admin')->user()->isSuperAdmin();
    $channelType = $channel->channelType();
    $channelTypeLabel = __('admin.distribution.channel_type.'.$channelType);
    $channelConfig = $channel->resolvedChannelConfig();
    $genericConfig = $channel->resolvedGenericHttpConfig();
    $articleTextAdPolicy = \App\Models\DistributionChannel::normalizeArticleTextAdPolicy($articleTextAdPolicy ?? $channel->resolvedArticleTextAdPolicy());
    $effectiveArticleTextAds = is_array($effectiveArticleTextAds ?? null) ? $effectiveArticleTextAds : $channel->effectiveArticleTextAds();
    $frontendExperienceReport = is_array($frontendExperienceReport ?? null) ? $frontendExperienceReport : [];
    $frontendPreview = is_array($frontendExperienceReport['sync_preview'] ?? null) ? $frontendExperienceReport['sync_preview'] : [];
    $frontendSummary = is_array($frontendPreview['summary'] ?? null) ? $frontendPreview['summary'] : [];
    $remoteTarget = is_array($frontendExperienceReport['remote_target'] ?? null) ? $frontendExperienceReport['remote_target'] : [];
    $remoteStatus = (string) ($remoteTarget['status'] ?? 'not_checked');
    $remoteStatusCopy = [
        'ok' => ['label' => '已检查', 'class' => 'border-emerald-200 bg-emerald-50 text-emerald-800'],
        'not_checked' => ['label' => '未检查', 'class' => 'border-gray-200 bg-gray-50 text-gray-700'],
        'missing_secret' => ['label' => '缺少密钥', 'class' => 'border-amber-200 bg-amber-50 text-amber-800'],
        'unsupported_or_not_found' => ['label' => '旧包或未暴露', 'class' => 'border-amber-200 bg-amber-50 text-amber-800'],
        'unavailable' => ['label' => '不可达', 'class' => 'border-red-200 bg-red-50 text-red-800'],
        'not_applicable' => ['label' => '不适用', 'class' => 'border-gray-200 bg-gray-50 text-gray-700'],
    ][$remoteStatus] ?? ['label' => $remoteStatus, 'class' => 'border-gray-200 bg-gray-50 text-gray-700'];
    $articleTextAdCounts = ['content_top' => 0, 'content_bottom' => 0];
    foreach ($effectiveArticleTextAds as $textAd) {
        $placement = (string) ($textAd['placement'] ?? '');
        if (array_key_exists($placement, $articleTextAdCounts)) {
            $articleTextAdCounts[$placement]++;
        }
    }
    $genericSamplePayload = [
        'version' => '1.0',
        'source' => 'geoflow',
        'event' => 'article.publish',
        'article' => [
            'id' => 123,
            'title' => 'Article title',
            'slug' => 'article-slug',
            'content_format' => 'markdown',
            'content' => 'Markdown content',
            'content_html' => '<p>HTML content</p>',
            'category' => ['name' => 'Category', 'slug' => 'category'],
            'author' => ['name' => 'Author'],
        ],
        'assets' => ['images' => []],
    ];
    $healthCheckUrl = rtrim((string) $channel->endpoint_url, '/').'/geoflow-agent/v1/health';
    if ($channel->isWordPressRest()) {
        $healthCheckUrl = $channel->wordpressRestBaseUrl().'/wp/v2/users/me?context=edit';
    } elseif ($channel->isGenericHttpApi()) {
        $genericHealthPath = strtr((string) $genericConfig['generic_health_path'], ['{channel_id}' => (string) $channel->id]);
        $healthCheckUrl = rtrim((string) $channel->endpoint_url, '/').(str_starts_with($genericHealthPath, '/') ? $genericHealthPath : '/'.$genericHealthPath);
    }
    $indexAgentBaseUrl = str_ends_with(rtrim((string) $channel->endpoint_url, '/'), '/index.php') ? rtrim((string) $channel->endpoint_url, '/') : rtrim((string) $channel->endpoint_url, '/').'/index.php';
    $indexHealthCheckUrl = $indexAgentBaseUrl.'/geoflow-agent/v1/health';
@endphp

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.distribution.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $channel->name }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ $channel->domain }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3">
                <a href="{{ route('admin.distribution.edit', ['channelId' => (int) $channel->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="pencil" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.button.edit') }}
                </a>
                <form method="POST" action="{{ $channel->status === 'active' ? route('admin.distribution.pause', ['channelId' => (int) $channel->id]) : route('admin.distribution.activate', ['channelId' => (int) $channel->id]) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="{{ $channel->status === 'active' ? 'pause-circle' : 'play-circle' }}" class="mr-2 h-4 w-4"></i>
                        {{ $channel->status === 'active' ? __('admin.distribution.button.pause') : __('admin.distribution.button.activate') }}
                    </button>
                </form>
                @if ($channel->isGeoFlowAgent())
                    <form method="POST" action="{{ route('admin.distribution.rotate-secret', ['channelId' => (int) $channel->id]) }}" onsubmit="return confirm('{{ __('admin.distribution.confirm.rotate_secret') }}')">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 bg-white px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-50">
                            <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.distribution.button.rotate_secret') }}
                        </button>
                    </form>
                @endif
                <form method="POST" action="{{ route('admin.distribution.health', ['channelId' => (int) $channel->id]) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="activity" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.distribution.button.health') }}
                    </button>
                </form>
                @if ($channel->isGeoFlowAgent())
                    <form method="POST" action="{{ route('admin.distribution.frontend-capabilities.refresh', ['channelId' => (int) $channel->id]) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i data-lucide="radar" class="mr-2 h-4 w-4"></i>
                            刷新远端能力
                        </button>
                    </form>
                @endif
                <a href="{{ route('admin.distribution.sync-settings.preview', ['channelId' => (int) $channel->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="scan-search" class="mr-2 h-4 w-4"></i>
                    同步预览
                </a>
            </div>
        </div>

        @if (session('distribution_secret'))
            @php($secret = session('distribution_secret'))
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-4">
                <div class="text-sm font-semibold text-amber-900">{{ __('admin.distribution.secret_notice_title') }}</div>
                <p class="mt-1 text-sm text-amber-800">{{ __('admin.distribution.secret_notice_desc') }}</p>
                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <div class="text-xs font-medium uppercase text-amber-700">{{ __('admin.distribution.field.key_id') }}</div>
                        <code class="mt-1 block break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm text-amber-900">{{ $secret['key_id'] ?? '' }}</code>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase text-amber-700">{{ __('admin.distribution.field.secret') }}</div>
                        <code class="mt-1 block break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm text-amber-900">{{ $secret['secret'] ?? '' }}</code>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase text-amber-700">{{ __('admin.distribution.field.endpoint_url') }}</div>
                        <code class="mt-1 block break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm text-amber-900">{{ $secret['endpoint_url'] ?? '' }}</code>
                    </div>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-lg bg-white p-6 shadow lg:col-span-2">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.detail.basic') }}</h2>
                <dl class="mt-5 grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">{{ __('admin.distribution.field.endpoint_url') }}</dt>
                        <dd class="mt-1 break-all font-medium text-gray-900">{{ $channel->endpoint_url }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.distribution.field.channel_type') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $channelTypeLabel }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.distribution.field.status') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $channelStatusLabel }}</dd>
                    </div>
                    @if ($channel->isGeoFlowAgent())
                        <div>
                            <dt class="text-gray-500">{{ __('admin.distribution.field.template_key') }}</dt>
                            <dd class="mt-1 font-medium text-gray-900">{{ $channel->template_key ?: __('admin.common.none') }}</dd>
                        </div>
                    @elseif ($channel->isWordPressRest())
                        <div>
                            <dt class="text-gray-500">{{ __('admin.distribution.wordpress.username') }}</dt>
                            <dd class="mt-1 font-medium text-gray-900">{{ $channelConfig['wordpress_username'] ?: __('admin.common.none') }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">{{ __('admin.distribution.wordpress.post_status') }}</dt>
                            <dd class="mt-1 font-medium text-gray-900">{{ __('admin.distribution.wordpress.post_status_'.$channelConfig['wordpress_post_status']) }}</dd>
                        </div>
                    @elseif ($channel->isGenericHttpApi())
                        <div>
                            <dt class="text-gray-500">{{ __('admin.distribution.generic.auth_type') }}</dt>
                            <dd class="mt-1 font-medium text-gray-900">{{ __('admin.distribution.generic.auth_'.$genericConfig['generic_auth_type']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">{{ __('admin.distribution.generic.publish_endpoint') }}</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-gray-900">{{ $genericConfig['generic_publish_method'] }} {{ $genericConfig['generic_publish_path'] }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-gray-500">{{ __('admin.distribution.field.health_status') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $healthStatusLabel }}</dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="text-gray-500">{{ __('admin.distribution.field.health_check_url') }}</dt>
                        <dd class="mt-1 break-all font-mono text-sm text-gray-900">{{ $healthCheckUrl }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg bg-white p-6 shadow">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.detail.secret') }}</h2>
                <dl class="mt-5 space-y-4 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ __('admin.distribution.field.key_id') }}</dt>
                        <dd class="mt-1 break-all font-medium text-gray-900">{{ $channel->activeSecret?->key_id ?: __('admin.common.none') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.distribution.field.last_used_at') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $channel->activeSecret?->last_used_at?->format('Y-m-d H:i') ?: __('admin.common.none') }}</dd>
                    </div>
                </dl>
                @if ($channel->activeSecret)
                    @if ($channel->isWordPressRest())
                        <div class="mt-5 rounded-md border border-blue-100 bg-blue-50 px-3 py-3 text-sm leading-6 text-blue-900">
                            {{ __('admin.distribution.wordpress.secret_hint') }}
                        </div>
                    @elseif ($canRevealSecret)
                        <form method="POST" action="{{ route('admin.distribution.reveal-secret', ['channelId' => (int) $channel->id]) }}" class="mt-5 border-t border-gray-200 pt-5">
                            @csrf
                            <label for="distribution-secret-password" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.admin_password') }}</label>
                            <input id="distribution-secret-password" name="password" type="password" autocomplete="current-password" required class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('password')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            <p class="mt-2 text-xs leading-5 text-gray-500">{{ __('admin.distribution.help.reveal_secret') }}</p>
                            <button type="submit" class="mt-4 inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="eye" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.distribution.button.reveal_secret') }}
                            </button>
                        </form>
                    @else
                        <div class="mt-5 rounded-md border border-gray-200 bg-gray-50 px-3 py-3 text-sm text-gray-600">
                            {{ __('admin.distribution.message.secret_reveal_forbidden') }}
                        </div>
                    @endif
                @endif
            </div>
        </div>

        <div class="rounded-lg bg-white p-6 shadow">
            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div>
                    <h2 class="text-lg font-medium text-gray-900">前台体验状态</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-600">展示当前将同步到目标站的前台配置，以及最近一次缓存的远端能力。</p>
                </div>
                <a href="{{ route('admin.distribution.sync-settings.preview', ['channelId' => (int) $channel->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="scan-search" class="mr-2 h-4 w-4"></i>
                    查看同步预览
                </a>
            </div>
            <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                    <div class="text-xs font-medium text-gray-500">体验模式</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $frontendSummary['frontend_experience_mode'] ?? $channel->frontendExperienceMode() }}</div>
                    <div class="mt-2 text-xs leading-5 text-gray-500">{{ ($frontendSummary['active_theme'] ?? '') !== '' ? $frontendSummary['active_theme'] : '默认主题' }} · {{ $frontendSummary['front_mode'] ?? $channel->frontMode() }}</div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                    <div class="text-xs font-medium text-gray-500">模块 / 轮播 / 文字广告</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ (int) ($frontendSummary['homepage_modules_count'] ?? 0) }} / {{ (int) ($frontendSummary['home_carousel_slides_count'] ?? 0) }} / {{ (int) ($frontendSummary['article_text_ads_count'] ?? 0) }}</div>
                    <div class="mt-2 text-xs leading-5 text-gray-500">样式 token {{ count($frontendSummary['homepage_style_keys'] ?? []) }} 个</div>
                </div>
                <div class="rounded-lg border {{ $remoteStatusCopy['class'] }} px-4 py-4">
                    <div class="text-xs font-medium opacity-75">远端能力缓存</div>
                    <div class="mt-1 text-sm font-semibold">{{ $remoteStatusCopy['label'] }}</div>
                    <div class="mt-2 text-xs leading-5">
                        {{ ($remoteTarget['checked_at'] ?? '') !== '' ? '最后检查 '.$remoteTarget['checked_at'] : '尚未检查远端能力' }}
                    </div>
                    @if ($remoteStatus === 'ok')
                        <div class="mt-2 text-xs leading-5">能力 {{ $remoteTarget['capability_version'] ?: '-' }} · 包 {{ $remoteTarget['package_version'] ?: '-' }}</div>
                    @elseif ($remoteStatus === 'unsupported_or_not_found')
                        <div class="mt-2 text-xs leading-5">建议重新下载并覆盖目标站点包。</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="rounded-lg bg-white p-6 shadow">
            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div>
                    <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.article_text_ads.detail_title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.distribution.article_text_ads.detail_desc') }}</p>
                </div>
                <a href="{{ route('admin.distribution.edit', ['channelId' => (int) $channel->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="pencil" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.button.edit') }}
                </a>
            </div>
            <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach (['content_top' => __('admin.distribution.article_text_ads.placement_top'), 'content_bottom' => __('admin.distribution.article_text_ads.placement_bottom')] as $placement => $placementLabel)
                    @php($mode = (string) ($articleTextAdPolicy[$placement]['mode'] ?? 'inherit'))
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                        <div class="text-sm font-semibold text-gray-900">{{ $placementLabel }}</div>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-600">
                            <span class="rounded-full bg-white px-2.5 py-1 font-medium text-gray-800 ring-1 ring-gray-200">{{ __('admin.distribution.article_text_ads.mode_'.$mode) }}</span>
                            <span>{{ __('admin.distribution.article_text_ads.effective_count', ['count' => $articleTextAdCounts[$placement] ?? 0]) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                {{ __('admin.distribution.article_text_ads.package_hint') }}
            </div>
        </div>

        @if ($channel->isGeoFlowAgent())
            <div class="rounded-lg bg-white p-6 shadow">
                <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                    <div class="max-w-3xl">
                        <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.detail.target_package') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.distribution.detail.target_package_desc') }}</p>
                        <div class="mt-5 grid grid-cols-1 gap-3 text-sm text-gray-700 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-3">
                                <div class="font-medium text-gray-900">{{ __('admin.distribution.detail.target_package_feature_health') }}</div>
                                <code class="mt-1 block break-all text-xs text-gray-500">/geoflow-agent/v1/health</code>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-3">
                                <div class="font-medium text-gray-900">{{ __('admin.distribution.detail.target_package_feature_article') }}</div>
                                <code class="mt-1 block break-all text-xs text-gray-500">/geoflow-agent/v1/articles</code>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-3">
                                <div class="font-medium text-gray-900">{{ __('admin.distribution.detail.target_package_feature_home') }}</div>
                                <code class="mt-1 block break-all text-xs text-gray-500">/</code>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-3">
                                <div class="font-medium text-gray-900">{{ __('admin.distribution.detail.target_package_feature_detail') }}</div>
                                <code class="mt-1 block break-all text-xs text-gray-500">/article/{slug}</code>
                            </div>
                        </div>
                        <div class="mt-5">
                            <div class="text-sm font-medium text-gray-900">{{ __('admin.distribution.detail.target_package_files') }}</div>
                            <ul class="mt-2 space-y-2 text-sm text-gray-600">
                                <li>{{ __('admin.distribution.detail.target_package_file_config') }}</li>
                                <li>{{ __('admin.distribution.detail.target_package_file_index') }}</li>
                                <li>{{ __('admin.distribution.detail.target_package_file_storage') }}</li>
                            </ul>
                        </div>
                        <div class="mt-5 grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-3">
                                <div class="font-medium text-gray-900">{{ __('admin.distribution.detail.package_configured_base') }}</div>
                                <code class="mt-1 block break-all text-xs text-gray-600">{{ rtrim((string) $channel->endpoint_url, '/') }}</code>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-3">
                                <div class="font-medium text-gray-900">{{ __('admin.distribution.detail.package_no_rewrite_entry') }}</div>
                                <code class="mt-1 block break-all text-xs text-gray-600">{{ $indexHealthCheckUrl }}</code>
                            </div>
                        </div>
                        <div class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            <div class="font-medium">{{ __('admin.distribution.detail.health_before_deploy_title') }}</div>
                            <p class="mt-1 leading-6">{{ __('admin.distribution.detail.health_before_deploy_desc') }}</p>
                        </div>
                    </div>

                    <div class="w-full rounded-lg border border-blue-100 bg-blue-50 p-4 xl:max-w-sm">
                        @if ($channel->activeSecret && $canRevealSecret)
                            <form method="POST" action="{{ route('admin.distribution.download-package', ['channelId' => (int) $channel->id]) }}">
                                @csrf
                                <label for="distribution-package-password" class="block text-sm font-medium text-gray-800">{{ __('admin.distribution.field.admin_password') }}</label>
                                <input id="distribution-package-password" name="package_password" type="password" autocomplete="current-password" required class="mt-2 block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @error('package_password')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                                <p class="mt-2 text-xs leading-5 text-gray-600">{{ __('admin.distribution.help.download_package') }}</p>
                                <button type="submit" class="mt-4 inline-flex w-full items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                    <i data-lucide="download" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.distribution.button.download_package') }}
                                </button>
                            </form>
                        @elseif (! $channel->activeSecret)
                            <div class="text-sm text-gray-600">{{ __('admin.distribution.message.active_secret_not_found') }}</div>
                        @else
                            <div class="text-sm text-gray-600">{{ __('admin.distribution.message.package_download_forbidden') }}</div>
                        @endif
                    </div>
                </div>
            </div>

            @include('admin.distribution._rewrite-rules', ['channel' => $channel])

            <div class="rounded-lg bg-white p-6 shadow">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.detail.agent_guide') }}</h2>
                        <p class="mt-2 text-sm text-gray-600">{{ __('admin.distribution.detail.agent_config_hint') }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                        <span class="font-medium">{{ __('admin.distribution.detail.agent_package') }}：</span>
                        <code class="break-all text-gray-900">{{ __('admin.distribution.detail.agent_package_name') }}</code>
                    </div>
                </div>
                <ol class="mt-6 grid grid-cols-1 gap-4 text-sm text-gray-700 md:grid-cols-2 xl:grid-cols-4">
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">1</span>
                        <span>{{ __('admin.distribution.detail.agent_step_deploy') }}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">2</span>
                        <span>{{ __('admin.distribution.detail.agent_step_configure') }}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">3</span>
                        <span>{{ __('admin.distribution.detail.agent_step_health') }}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">4</span>
                        <span>{{ __('admin.distribution.detail.agent_step_task') }}</span>
                    </li>
                </ol>
            </div>
        @elseif ($channel->isWordPressRest())
            <div class="rounded-lg bg-white p-6 shadow">
                <div class="max-w-3xl">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.wordpress.guide_title') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.distribution.wordpress.guide_desc') }}</p>
                </div>
                <ol class="mt-6 grid grid-cols-1 gap-4 text-sm text-gray-700 md:grid-cols-2 xl:grid-cols-4">
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">1</span>
                        <span>{{ __('admin.distribution.wordpress.guide_step_password') }}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">2</span>
                        <span>{{ __('admin.distribution.wordpress.guide_step_config') }}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">3</span>
                        <span>{{ __('admin.distribution.wordpress.guide_step_health') }}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">4</span>
                        <span>{{ __('admin.distribution.wordpress.guide_step_draft') }}</span>
                    </li>
                </ol>
            </div>
        @elseif ($channel->isGenericHttpApi())
            <div class="rounded-lg bg-white p-6 shadow">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="max-w-3xl">
                        <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.generic.guide_title') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.distribution.generic.guide_desc') }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                        <span class="font-medium">{{ __('admin.distribution.generic.payload_contract') }}：</span>
                        <code class="break-all text-gray-900">GEOFlow article JSON v1</code>
                    </div>
                </div>
                <div class="mt-6 grid grid-cols-1 gap-4 text-sm text-gray-700 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <div class="font-medium text-gray-900">{{ __('admin.distribution.generic.guide_endpoint_title') }}</div>
                        <code class="mt-2 block break-all text-xs text-gray-600">{{ $genericConfig['generic_publish_method'] }} {{ $genericConfig['generic_publish_path'] }}</code>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <div class="font-medium text-gray-900">{{ __('admin.distribution.generic.guide_auth_title') }}</div>
                        <p class="mt-2 text-xs leading-5 text-gray-600">{{ __('admin.distribution.generic.auth_'.$genericConfig['generic_auth_type']) }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <div class="font-medium text-gray-900">{{ __('admin.distribution.generic.guide_response_title') }}</div>
                        <p class="mt-2 text-xs leading-5 text-gray-600">{{ __('admin.distribution.generic.response_contract') }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <div class="font-medium text-gray-900">{{ __('admin.distribution.generic.guide_settings_title') }}</div>
                        <code class="mt-2 block break-all text-xs text-gray-600">{{ $genericConfig['generic_settings_method'] }} {{ $genericConfig['generic_settings_path'] ?: __('admin.common.none') }}</code>
                    </div>
                </div>
                <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-900">{{ __('admin.distribution.generic.response_mapping_title') }}</div>
                        <dl class="mt-3 space-y-2 text-xs text-gray-600">
                            <div class="flex items-center justify-between gap-3">
                                <dt>{{ __('admin.distribution.generic.remote_id_path') }}</dt>
                                <dd class="break-all font-mono text-gray-900">{{ $genericConfig['generic_remote_id_path'] ?: __('admin.common.none') }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt>{{ __('admin.distribution.generic.remote_url_path') }}</dt>
                                <dd class="break-all font-mono text-gray-900">{{ $genericConfig['generic_remote_url_path'] ?: __('admin.common.none') }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt>{{ __('admin.distribution.generic.success_statuses') }}</dt>
                                <dd class="break-all font-mono text-gray-900">{{ implode(',', $genericConfig['generic_success_statuses']) }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-900">{{ __('admin.distribution.generic.sample_payload_title') }}</div>
                        <pre class="mt-3 max-h-72 overflow-auto rounded-md bg-gray-950 p-3 text-xs leading-5 text-gray-100"><code>{{ json_encode($genericSamplePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
                    </div>
                </div>
            </div>
        @endif

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.jobs_title') }}</h2>
            </div>
            @include('admin.distribution._jobs-table', ['jobs' => $jobs])
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.recent_logs_title') }}</h2>
            </div>
            @if ($logs->isEmpty())
                <div class="px-6 py-8 text-sm text-gray-500">{{ __('admin.distribution.empty_logs') }}</div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($logs as $log)
                        @php($logLevelKey = 'admin.distribution.log_level.'.(string) $log->level)
                        @php($logLevelLabel = trans()->has($logLevelKey) ? __($logLevelKey) : (string) $log->level)
                        <div class="px-6 py-4 text-sm">
                            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="font-medium text-gray-900">{{ $log->message }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                                        <span class="whitespace-nowrap">{{ __('admin.distribution.field.time') }}：{{ $log->created_at?->format('Y-m-d H:i') }}</span>
                                        <span class="whitespace-nowrap">{{ __('admin.distribution.field.event') }}：{{ $log->event ?: __('admin.common.none') }}</span>
                                        <span class="whitespace-nowrap">{{ $logLevelLabel }}</span>
                                    </div>
                                </div>
                                <div class="min-w-0 text-gray-600 lg:max-w-xl lg:text-right">
                                    <span class="text-gray-400">{{ __('admin.distribution.field.article') }}：</span>
                                    <span class="font-medium text-gray-900">{{ $log->article?->title ?? __('admin.common.none') }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
