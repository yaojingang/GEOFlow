@extends('admin.layouts.app')

@php
    $scope = (string) ($scope ?? 'single');
    $previewReport = is_array($previewReport ?? null) ? $previewReport : [];
    $previews = is_array($previewReport['channels'] ?? null) ? $previewReport['channels'] : [];
    $requiresConfirmation = (bool) ($previewReport['requires_confirmation'] ?? false);
    $totals = is_array($previewReport['totals'] ?? null) ? $previewReport['totals'] : [];
    $firstPreview = $previews[0] ?? [];
    $firstChannel = is_array($firstPreview['channel'] ?? null) ? $firstPreview['channel'] : [];
    $statusCopy = [
        'ok' => ['label' => '已检查', 'class' => 'border-emerald-200 bg-emerald-50 text-emerald-800'],
        'not_checked' => ['label' => '未检查', 'class' => 'border-gray-200 bg-gray-50 text-gray-700'],
        'missing_secret' => ['label' => '缺少密钥', 'class' => 'border-amber-200 bg-amber-50 text-amber-800'],
        'unsupported_or_not_found' => ['label' => '旧包或未暴露', 'class' => 'border-amber-200 bg-amber-50 text-amber-800'],
        'unavailable' => ['label' => '不可达', 'class' => 'border-red-200 bg-red-50 text-red-800'],
        'not_applicable' => ['label' => '不适用', 'class' => 'border-gray-200 bg-gray-50 text-gray-700'],
    ];
    $confirmAction = $scope === 'all'
        ? route('admin.distribution.sync-settings-all')
        : ($scope === 'selected'
            ? route('admin.distribution.sync-settings-selected')
            : route('admin.distribution.sync-settings', ['channelId' => (int) ($firstChannel['id'] ?? 0)]));
@endphp

@section('content')
    <div class="space-y-6 px-4 sm:px-0">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <a href="{{ route('admin.distribution.index') }}" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700">
                    <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                    返回渠道列表
                </a>
                <h1 class="mt-3 text-2xl font-bold text-gray-900">前台体验同步预览</h1>
                <p class="mt-1 text-sm leading-6 text-gray-600">确认远端能力缓存、同步差异和 settings JSON 后再执行同步。</p>
            </div>
            <div class="rounded-lg border {{ $requiresConfirmation ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-900' }} px-4 py-3 text-sm">
                <div class="font-semibold">{{ $requiresConfirmation ? '需要确认后同步' : '未发现阻断风险' }}</div>
                <div class="mt-1">渠道 {{ (int) ($totals['channels'] ?? count($previews)) }} 个 · 风险提示 {{ (int) ($totals['warnings'] ?? 0) }} 条</div>
            </div>
        </div>

        @if ($previews === [])
            <div class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-12 text-center text-sm text-gray-500 shadow">
                当前没有可同步的 GeoFlow Agent 渠道。
            </div>
        @else
            <div class="space-y-5">
                @foreach ($previews as $preview)
                    @php
                        $channel = is_array($preview['channel'] ?? null) ? $preview['channel'] : [];
                        $summary = is_array($preview['summary'] ?? null) ? $preview['summary'] : [];
                        $remote = is_array($preview['remote_target'] ?? null) ? $preview['remote_target'] : [];
                        $warnings = is_array($preview['warnings'] ?? null) ? $preview['warnings'] : [];
                        $remoteStatus = (string) ($remote['status'] ?? 'not_checked');
                        $remoteStatusInfo = $statusCopy[$remoteStatus] ?? ['label' => $remoteStatus, 'class' => 'border-gray-200 bg-gray-50 text-gray-700'];
                        $supportedModules = is_array($remote['supported_modules'] ?? null) ? $remote['supported_modules'] : [];
                        $supportedRoutes = is_array($remote['supported_routes'] ?? null) ? $remote['supported_routes'] : [];
                        $styleKeys = is_array($summary['homepage_style_keys'] ?? null) ? $summary['homepage_style_keys'] : [];
                        $moduleTypes = is_array($summary['homepage_module_types'] ?? null) ? $summary['homepage_module_types'] : [];
                        $slideTitles = is_array($summary['home_carousel_slide_titles'] ?? null) ? $summary['home_carousel_slide_titles'] : [];
                    @endphp

                    <section class="rounded-lg bg-white shadow">
                        <div class="border-b border-gray-200 px-6 py-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="text-lg font-semibold text-gray-900">{{ $channel['name'] ?? '未命名渠道' }}</h2>
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium {{ $remoteStatusInfo['class'] }}">{{ $remoteStatusInfo['label'] }}</span>
                                        @if (! empty($remote['is_stale']))
                                            <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800">缓存可能过期</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 break-all text-sm text-gray-500">{{ $channel['domain'] ?? '' }} · {{ $channel['endpoint_url'] ?? '' }}</p>
                                </div>
                                @if (! empty($channel['id']))
                                    <form method="POST" action="{{ route('admin.distribution.frontend-capabilities.refresh', ['channelId' => (int) $channel['id']]) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                            <i data-lucide="radar" class="mr-2 h-4 w-4"></i>
                                            刷新远端能力
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-0 lg:grid-cols-3">
                            <div class="border-b border-gray-200 px-6 py-5 lg:border-b-0 lg:border-r">
                                <h3 class="text-sm font-semibold text-gray-900">同步摘要</h3>
                                <dl class="mt-4 space-y-3 text-sm">
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-500">体验模式</dt>
                                        <dd class="font-medium text-gray-900">{{ $summary['frontend_experience_mode'] ?? '' }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-500">主题 / front_mode</dt>
                                        <dd class="font-medium text-gray-900">{{ ($summary['active_theme'] ?? '') !== '' ? $summary['active_theme'] : '默认主题' }} / {{ $summary['front_mode'] ?? '' }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-500">模块 / 轮播 / 文字广告</dt>
                                        <dd class="font-medium text-gray-900">{{ (int) ($summary['homepage_modules_count'] ?? 0) }} / {{ (int) ($summary['home_carousel_slides_count'] ?? 0) }} / {{ (int) ($summary['article_text_ads_count'] ?? 0) }}</dd>
                                    </div>
                                </dl>
                                <div class="mt-4 space-y-2 text-xs leading-5 text-gray-500">
                                    <div>模块类型：{{ $moduleTypes !== [] ? implode('、', $moduleTypes) : '无' }}</div>
                                    <div>轮播标题：{{ $slideTitles !== [] ? implode('、', $slideTitles) : '无' }}</div>
                                    <div>样式 token：{{ $styleKeys !== [] ? implode('、', $styleKeys) : '无' }}</div>
                                </div>
                            </div>

                            <div class="border-b border-gray-200 px-6 py-5 lg:border-b-0 lg:border-r">
                                <h3 class="text-sm font-semibold text-gray-900">远端能力缓存</h3>
                                <dl class="mt-4 space-y-3 text-sm">
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-500">最后检查</dt>
                                        <dd class="font-medium text-gray-900">{{ ($remote['checked_at'] ?? '') !== '' ? $remote['checked_at'] : '未检查' }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-500">能力 / 包版本</dt>
                                        <dd class="font-medium text-gray-900">{{ ($remote['capability_version'] ?? '') !== '' ? $remote['capability_version'] : '-' }} / {{ ($remote['package_version'] ?? '') !== '' ? $remote['package_version'] : '-' }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-500">远端主题 / front_mode</dt>
                                        <dd class="font-medium text-gray-900">{{ ($remote['active_theme'] ?? '') !== '' ? $remote['active_theme'] : '-' }} / {{ ($remote['front_mode'] ?? '') !== '' ? $remote['front_mode'] : '-' }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-500">模块 / 路由</dt>
                                        <dd class="font-medium text-gray-900">{{ count($supportedModules) }} / {{ count($supportedRoutes) }}</dd>
                                    </div>
                                </dl>
                                @if ($remoteStatus === 'unsupported_or_not_found')
                                    <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm leading-6 text-amber-900">
                                        旧目标包需要重新下载并覆盖目标站点包，才能完整展示前台体验能力。
                                    </div>
                                @elseif (($remote['message'] ?? '') !== '')
                                    <div class="mt-4 text-sm leading-6 text-gray-600">{{ $remote['message'] }}</div>
                                @endif
                            </div>

                            <div class="px-6 py-5">
                                <h3 class="text-sm font-semibold text-gray-900">风险提示</h3>
                                @if ($warnings === [])
                                    <div class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-3 text-sm text-emerald-800">
                                        未发现需要确认的同步风险。
                                    </div>
                                @else
                                    <ul class="mt-4 space-y-3 text-sm leading-6">
                                        @foreach ($warnings as $warning)
                                            <li class="rounded-md border {{ ($warning['severity'] ?? '') === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-blue-100 bg-blue-50 text-blue-900' }} px-3 py-2">
                                                <div class="font-medium">{{ $warning['code'] ?? '' }}{{ ! empty($warning['requires_confirmation']) ? ' · 需要确认' : '' }}</div>
                                                <div class="mt-1">{{ $warning['message'] ?? '' }}</div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>

                        <div class="border-t border-gray-200 px-6 py-5">
                            <label class="block text-sm font-semibold text-gray-900">即将发送的 settings JSON</label>
                            <textarea readonly rows="12" class="mt-3 block w-full rounded-md border-gray-300 bg-gray-50 font-mono text-xs text-gray-800 shadow-sm">{{ $preview['settings_payload_json'] ?? '' }}</textarea>
                        </div>
                    </section>
                @endforeach
            </div>

            <div class="sticky bottom-0 rounded-lg border border-gray-200 bg-white px-6 py-4 shadow">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div class="text-sm leading-6 text-gray-600">
                        确认后会沿用原同步流程，并继续触发现有内容刷新队列。
                    </div>
                    <form method="POST" action="{{ $confirmAction }}" class="flex items-center justify-end gap-3">
                        @csrf
                        <input type="hidden" name="frontend_sync_confirmed" value="1">
                        @if ($scope === 'selected')
                            @foreach ($previews as $preview)
                                @php($channel = is_array($preview['channel'] ?? null) ? $preview['channel'] : [])
                                @if (! empty($channel['id']))
                                    <input type="hidden" name="channel_ids[]" value="{{ (int) $channel['id'] }}">
                                @endif
                            @endforeach
                        @endif
                        <a href="{{ route('admin.distribution.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">取消</a>
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="check-circle" class="mr-2 h-4 w-4"></i>
                            确认并同步
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
