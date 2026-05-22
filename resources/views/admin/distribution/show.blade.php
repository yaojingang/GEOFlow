@extends('admin.layouts.app')

@php($channelStatusKey = 'admin.distribution.status.'.(string) $channel->status)
@php($channelStatusLabel = trans()->has($channelStatusKey) ? __($channelStatusKey) : (string) $channel->status)
@php($healthStatus = (string) ($channel->last_health_status ?? ''))
@php($healthStatusKey = 'admin.distribution.health_status.'.$healthStatus)
@php($healthStatusLabel = $healthStatus !== '' && trans()->has($healthStatusKey) ? __($healthStatusKey) : ($healthStatus !== '' ? $healthStatus : __('admin.common.none')))
@php($canRevealSecret = auth('admin')->user() instanceof \App\Models\Admin && auth('admin')->user()->isSuperAdmin())
@php($healthCheckUrl = rtrim((string) $channel->endpoint_url, '/').'/geoflow-agent/v1/health')
@php($indexAgentBaseUrl = str_ends_with(rtrim((string) $channel->endpoint_url, '/'), '/index.php') ? rtrim((string) $channel->endpoint_url, '/') : rtrim((string) $channel->endpoint_url, '/').'/index.php')
@php($indexHealthCheckUrl = $indexAgentBaseUrl.'/geoflow-agent/v1/health')

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
                <form method="POST" action="{{ route('admin.distribution.rotate-secret', ['channelId' => (int) $channel->id]) }}" onsubmit="return confirm('{{ __('admin.distribution.confirm.rotate_secret') }}')">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 bg-white px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-50">
                        <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.distribution.button.rotate_secret') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.distribution.health', ['channelId' => (int) $channel->id]) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="activity" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.distribution.button.health') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="settings" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.distribution.button.sync_settings') }}
                    </button>
                </form>
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
                        <dt class="text-gray-500">{{ __('admin.distribution.field.status') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $channelStatusLabel }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.distribution.field.template_key') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $channel->template_key ?: __('admin.common.none') }}</dd>
                    </div>
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
                    @if ($canRevealSecret)
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
