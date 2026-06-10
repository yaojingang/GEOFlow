@extends('admin.layouts.app')

@php
    $statusClass = [
        'queued' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'fetching' => 'bg-cyan-50 text-cyan-700 ring-cyan-200',
        'extracting' => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
        'analyzing' => 'bg-purple-50 text-purple-700 ring-purple-200',
        'generating' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'scanning' => 'bg-orange-50 text-orange-700 ring-orange-200',
        'iterating' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'ready' => 'bg-green-50 text-green-700 ring-green-200',
        'published' => 'bg-green-100 text-green-800 ring-green-200',
        'archived' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'failed' => 'bg-red-50 text-red-700 ring-red-200',
    ][$replication->status] ?? 'bg-gray-50 text-gray-700 ring-gray-200';
    $canPreview = $replication->isPreviewReady();
    $fileDiff = $fileDiff ?? ['rows' => [], 'counts' => ['added' => 0, 'modified' => 0, 'removed' => 0, 'unchanged' => 0]];
    $diffBadgeClass = [
        'added' => 'bg-green-50 text-green-700 ring-green-200',
        'modified' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'removed' => 'bg-red-50 text-red-700 ring-red-200',
        'unchanged' => 'bg-gray-50 text-gray-600 ring-gray-200',
    ];
    $copyThemeId = $replication->theme_id.'-copy';
    $previewPages = [
        'home' => __('admin.theme_replication.preview.home'),
        'category' => __('admin.theme_replication.preview.category'),
        'article' => __('admin.theme_replication.preview.article'),
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.site-settings.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-900">{{ $replication->name }}</h1>
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $statusClass }}">
                            {{ __('admin.theme_replication.status.'.$replication->status) }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.detail_subtitle', ['theme' => $replication->theme_id]) }}</p>
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('admin.site-settings.theme-replications.create') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.theme_replication.button.create_another') }}
                </a>
                @if ($replication->status === \App\Models\SiteThemeReplication::STATUS_FAILED)
                    <form method="POST" action="{{ route('admin.site-settings.theme-replications.retry', ['replicationId' => (int) $replication->id]) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.theme_replication.button.retry') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="space-y-6">
                <section class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.overview') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.section.overview_desc') }}</p>
                    </div>
                    <div class="grid grid-cols-1 gap-0 divide-y divide-gray-100 px-6 py-2 md:grid-cols-2 md:divide-x md:divide-y-0">
                        <dl class="space-y-4 py-5 md:pr-6">
                            <div>
                                <dt class="text-sm text-gray-500">{{ __('admin.theme_replication.field.theme_id') }}</dt>
                                <dd class="mt-1 font-mono text-sm font-semibold text-gray-900">{{ $replication->theme_id }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">{{ __('admin.theme_replication.field.ai_model') }}</dt>
                                <dd class="mt-1 text-sm font-semibold text-gray-900">{{ $replication->aiModel?->name ?? __('admin.common.none') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">{{ __('admin.theme_replication.field.style_preference') }}</dt>
                                <dd class="mt-1 text-sm font-semibold text-gray-900">{{ __('admin.theme_replication.style.'.$replication->style_preference) }}</dd>
                            </div>
                        </dl>
                        <dl class="space-y-4 py-5 md:pl-6">
                            <div>
                                <dt class="text-sm text-gray-500">{{ __('admin.theme_replication.label.created_by') }}</dt>
                                <dd class="mt-1 text-sm font-semibold text-gray-900">{{ $replication->creator?->name ?? __('admin.common.none') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">{{ __('admin.theme_replication.label.created_at') }}</dt>
                                <dd class="mt-1 text-sm font-semibold text-gray-900">{{ optional($replication->created_at)->format('Y-m-d H:i') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">{{ __('admin.theme_replication.label.current_version') }}</dt>
                                <dd class="mt-1 text-sm font-semibold text-gray-900">v{{ (int) $replication->current_version }}</dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.references') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.section.references_desc') }}</p>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @foreach (['home_url', 'category_url', 'article_url'] as $urlField)
                            <div class="flex flex-col gap-2 px-6 py-4 md:flex-row md:items-center md:justify-between">
                                <div class="text-sm font-medium text-gray-500">{{ __('admin.theme_replication.field.'.$urlField) }}</div>
                                <a href="{{ $replication->{$urlField} }}" target="_blank" rel="noopener noreferrer" class="break-all text-sm font-semibold text-blue-600 hover:text-blue-700">{{ $replication->{$urlField} }}</a>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.draft_files') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.section.draft_files_desc') }}</p>
                    </div>
                    @if($latestVersion)
                        <div class="px-6 py-5">
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                <div class="rounded-lg bg-gray-50 p-3">
                                    <div class="text-xs text-gray-500">{{ __('admin.theme_replication.label.current_version') }}</div>
                                    <div class="mt-1 text-sm font-semibold text-gray-900">v{{ (int) $latestVersion->version }}</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3">
                                    <div class="text-xs text-gray-500">{{ __('admin.theme_replication.label.draft_views_path') }}</div>
                                    <div class="mt-1 truncate font-mono text-xs text-gray-900">{{ $latestVersion->draft_views_path }}</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3">
                                    <div class="text-xs text-gray-500">{{ __('admin.theme_replication.label.draft_assets_path') }}</div>
                                    <div class="mt-1 truncate font-mono text-xs text-gray-900">{{ $latestVersion->draft_assets_path }}</div>
                                </div>
                            </div>
                            <div class="mt-4 overflow-hidden rounded-lg border border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="px-4 py-3">{{ __('admin.theme_replication.label.file_path') }}</th>
                                            <th class="px-4 py-3">{{ __('admin.theme_replication.label.file_size') }}</th>
                                            <th class="px-4 py-3">{{ __('admin.theme_replication.label.checksum') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        @foreach((array) (($latestVersion->files_json ?? [])['files'] ?? []) as $file)
                                            <tr>
                                                <td class="px-4 py-3 font-mono text-xs text-gray-900">{{ $file['path'] ?? '' }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ number_format((int) ($file['bytes'] ?? 0)) }} B</td>
                                                <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ substr((string) ($file['checksum'] ?? ''), 0, 16) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @else
                        <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.theme_replication.empty.draft_files') }}</div>
                    @endif
                </section>

                <section class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.file_diff') }}</h2>
                                <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.section.file_diff_desc') }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs font-semibold">
                                @foreach((array) ($fileDiff['counts'] ?? []) as $status => $count)
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 ring-1 ring-inset {{ $diffBadgeClass[$status] ?? $diffBadgeClass['unchanged'] }}">
                                        {{ __('admin.theme_replication.diff.'.$status) }} {{ (int) $count }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    @if(! empty($fileDiff['rows']))
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-6 py-3">{{ __('admin.theme_replication.label.file_path') }}</th>
                                        <th class="px-4 py-3">{{ __('admin.theme_replication.label.diff_status') }}</th>
                                        <th class="px-4 py-3">{{ __('admin.theme_replication.label.old_size') }}</th>
                                        <th class="px-4 py-3">{{ __('admin.theme_replication.label.new_size') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @foreach($fileDiff['rows'] as $row)
                                        @php($rowStatus = (string) ($row['status'] ?? 'unchanged'))
                                        <tr>
                                            <td class="max-w-[560px] break-all px-6 py-3 font-mono text-xs text-gray-900">{{ $row['path'] ?? '' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $diffBadgeClass[$rowStatus] ?? $diffBadgeClass['unchanged'] }}">
                                                    {{ __('admin.theme_replication.diff.'.$rowStatus) }}
                                                </span>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ number_format((int) ($row['old_size'] ?? 0)) }} B</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ number_format((int) ($row['new_size'] ?? 0)) }} B</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.theme_replication.empty.file_diff') }}</div>
                    @endif
                </section>

                <section class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.preview') }}</h2>
                                <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.section.preview_desc') }}</p>
                            </div>
                            @if($canPreview)
                                <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1">
                                    <button type="button" data-preview-mode="desktop" class="rounded-md bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm">
                                        {{ __('admin.theme_replication.button.desktop_preview') }}
                                    </button>
                                    <button type="button" data-preview-mode="mobile" class="rounded-md px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900">
                                        {{ __('admin.theme_replication.button.mobile_preview') }}
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                    @if($canPreview)
                        <div class="grid grid-cols-1 gap-5 p-6 xl:grid-cols-3">
                            @foreach($previewPages as $page => $label)
                                <div data-preview-frame class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50 transition-all duration-200">
                                    <div class="flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
                                        <div class="text-sm font-semibold text-gray-900">{{ $label }}</div>
                                        <a href="{{ route('admin.site-settings.theme-replications.preview', ['replicationId' => (int) $replication->id, 'page' => $page]) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center text-xs font-semibold text-blue-600 hover:text-blue-700">
                                            {{ __('admin.theme_replication.button.open_preview') }}
                                            <i data-lucide="external-link" class="ml-1 h-3.5 w-3.5"></i>
                                        </a>
                                    </div>
                                    <iframe
                                        src="{{ route('admin.site-settings.theme-replications.preview', ['replicationId' => (int) $replication->id, 'page' => $page]) }}"
                                        title="{{ $label }}"
                                        class="h-[360px] w-full bg-white"
                                        loading="lazy"
                                        sandbox="allow-same-origin"
                                        data-preview-iframe
                                    ></iframe>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.theme_replication.empty.preview') }}</div>
                    @endif
                </section>

                <section class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.iteration') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.section.iteration_desc') }}</p>
                    </div>
                    @if($replication->status === \App\Models\SiteThemeReplication::STATUS_READY)
                        <form method="POST" action="{{ route('admin.site-settings.theme-replications.iterate', ['replicationId' => (int) $replication->id]) }}" class="space-y-4 px-6 py-5">
                            @csrf
                            <label for="feedback" class="block text-sm font-medium text-gray-700">{{ __('admin.theme_replication.field.feedback') }}</label>
                            <textarea id="feedback" name="feedback" rows="4" required class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.theme_replication.placeholder.feedback') }}"></textarea>
                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                    <i data-lucide="wand-sparkles" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.theme_replication.button.iterate') }}
                                </button>
                            </div>
                        </form>
                    @elseif($replication->status === \App\Models\SiteThemeReplication::STATUS_ITERATING)
                        <div class="px-6 py-8 text-sm text-violet-700">{{ __('admin.theme_replication.message.iteration_running') }}</div>
                    @else
                        <div class="px-6 py-8 text-sm text-gray-500">{{ __('admin.theme_replication.message.iteration_unavailable') }}</div>
                    @endif
                </section>

                <section class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.logs') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.theme_replication.section.logs_desc') }}</p>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse ($logs as $log)
                            <div class="px-6 py-4">
                                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">{{ $log->message }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ strtoupper($log->level) }} · {{ $log->step }}</div>
                                    </div>
                                    <div class="shrink-0 text-xs text-gray-500">{{ optional($log->created_at)->format('Y-m-d H:i:s') }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.theme_replication.empty.logs') }}</div>
                        @endforelse
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.next_step') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.theme_replication.next_step_desc.'.$replication->status) }}</p>
                    @if ($replication->error_message)
                        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $replication->error_message }}</div>
                    @endif
                    @if ($failureAdvice)
                        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 rounded-full bg-amber-100 p-2 text-amber-700">
                                    <i data-lucide="circle-help" class="h-4 w-4"></i>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-amber-900">{{ $failureAdvice['title'] }}</h3>
                                    <p class="mt-1 text-sm leading-6 text-amber-800">{{ $failureAdvice['description'] }}</p>
                                    <ul class="mt-3 space-y-1 text-sm text-amber-800">
                                        @foreach((array) ($failureAdvice['actions'] ?? []) as $action)
                                            <li class="flex gap-2">
                                                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                                                <span>{{ $action }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    @endif
                </section>

                <section class="rounded-lg bg-white p-6 shadow">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.deployment.title') }}</h2>
                    <div class="mt-4 space-y-3">
                        <div class="rounded-lg bg-gray-50 p-3">
                            <div class="text-xs text-gray-500">{{ __('admin.theme_replication.deployment.views_path') }}</div>
                            <div class="mt-1 truncate font-mono text-xs text-gray-900">{{ $deploymentDiagnostics['views_path'] }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3">
                            <div class="text-xs text-gray-500">{{ __('admin.theme_replication.deployment.assets_path') }}</div>
                            <div class="mt-1 truncate font-mono text-xs text-gray-900">{{ $deploymentDiagnostics['assets_path'] }}</div>
                        </div>
                    </div>
                    <div class="mt-4 rounded-lg {{ $deploymentDiagnostics['can_publish_directly'] ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' }} p-3 text-sm">
                        {{ $deploymentDiagnostics['can_publish_directly'] ? __('admin.theme_replication.deployment.writable_ready') : __('admin.theme_replication.deployment.readonly_hint') }}
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.publish') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.theme_replication.section.publish_desc') }}</p>
                    <div class="mt-5 space-y-3">
                        @if($replication->canPublish())
                            <form method="POST" action="{{ route('admin.site-settings.theme-replications.publish', ['replicationId' => (int) $replication->id]) }}">
                                @csrf
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                                    <i data-lucide="check-circle-2" class="mr-2 h-4 w-4"></i>
                                    {{ $deploymentDiagnostics['can_publish_directly'] ? __('admin.theme_replication.button.publish') : __('admin.theme_replication.button.make_package') }}
                                </button>
                            </form>
                        @endif
                        @if($canPreview)
                            <a href="{{ route('admin.site-settings.theme-replications.package', ['replicationId' => (int) $replication->id]) }}" class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <i data-lucide="package-down" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.theme_replication.button.download_package') }}
                            </a>
                        @endif
                        @if($replication->status === \App\Models\SiteThemeReplication::STATUS_PUBLISHED)
                            <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ __('admin.theme_replication.message.published') }}</div>
                        @endif
                    </div>
                </section>

                @if($canPreview)
                    <section class="rounded-lg bg-white p-6 shadow">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.copy') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.theme_replication.section.copy_desc') }}</p>
                        <form method="POST" action="{{ route('admin.site-settings.theme-replications.copy', ['replicationId' => (int) $replication->id]) }}" class="mt-5 space-y-4">
                            @csrf
                            <div>
                                <label for="copy_name" class="block text-sm font-medium text-gray-700">{{ __('admin.theme_replication.field.copy_name') }}</label>
                                <input id="copy_name" name="name" type="text" required maxlength="120" value="{{ __('admin.theme_replication.copy.default_name', ['name' => $replication->name]) }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.theme_replication.placeholder.copy_name') }}">
                            </div>
                            <div>
                                <label for="copy_theme_id" class="block text-sm font-medium text-gray-700">{{ __('admin.theme_replication.field.copy_theme_id') }}</label>
                                <input id="copy_theme_id" name="theme_id" type="text" required maxlength="80" value="{{ $copyThemeId }}" class="mt-1 block w-full rounded-md border-gray-300 font-mono shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.theme_replication.placeholder.copy_theme_id') }}">
                            </div>
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="copy-plus" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.theme_replication.button.copy_theme') }}
                            </button>
                        </form>
                    </section>
                @endif

                <section class="rounded-lg bg-white p-6 shadow">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.theme_replication.section.lifecycle') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.theme_replication.section.lifecycle_desc') }}</p>
                    <div class="mt-5 space-y-3">
                        @if($replication->canBeArchived())
                            <form method="POST" action="{{ route('admin.site-settings.theme-replications.archive', ['replicationId' => (int) $replication->id]) }}" onsubmit="return confirm(@js(__('admin.theme_replication.confirm.archive')))">
                                @csrf
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    <i data-lucide="archive" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.theme_replication.button.archive') }}
                                </button>
                            </form>
                        @endif
                        @if($replication->canDeleteDrafts())
                            <form method="POST" action="{{ route('admin.site-settings.theme-replications.delete-drafts', ['replicationId' => (int) $replication->id]) }}" onsubmit="return confirm(@js(__('admin.theme_replication.confirm.delete_drafts')))">
                                @csrf
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                                    <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.theme_replication.button.delete_drafts') }}
                                </button>
                            </form>
                        @endif
                        @if(! $replication->canBeArchived() && ! $replication->canDeleteDrafts())
                            <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-500">{{ __('admin.theme_replication.message.lifecycle_unavailable') }}</div>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const buttons = Array.from(document.querySelectorAll('[data-preview-mode]'));
            const frames = Array.from(document.querySelectorAll('[data-preview-frame]'));
            const iframes = Array.from(document.querySelectorAll('[data-preview-iframe]'));

            const applyMode = (mode) => {
                buttons.forEach((button) => {
                    const active = button.dataset.previewMode === mode;
                    button.classList.toggle('bg-white', active);
                    button.classList.toggle('text-blue-700', active);
                    button.classList.toggle('shadow-sm', active);
                    button.classList.toggle('text-gray-600', ! active);
                });

                frames.forEach((frame) => {
                    if (mode === 'mobile') {
                        frame.style.maxWidth = '390px';
                        frame.style.marginLeft = 'auto';
                        frame.style.marginRight = 'auto';
                    } else {
                        frame.style.maxWidth = '';
                        frame.style.marginLeft = '';
                        frame.style.marginRight = '';
                    }
                });

                iframes.forEach((iframe) => {
                    iframe.style.height = mode === 'mobile' ? '640px' : '360px';
                });
            };

            buttons.forEach((button) => {
                button.addEventListener('click', () => applyMode(button.dataset.previewMode || 'desktop'));
            });
        });
    </script>
@endpush
