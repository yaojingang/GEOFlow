@extends('admin.layouts.app')
@section('content')
@include('admin.link-pool.partials.header')
<div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-0">
    <div><h2 class="text-xl font-semibold text-gray-950">站点设置</h2><p class="mt-1 text-sm text-gray-600">管理所选站点的文章推荐区。</p></div>
    @if($campaign)<a href="{{ route('admin.link-pool.campaigns.edit', $campaign->id) }}" class="inline-flex min-h-10 items-center rounded-lg border border-blue-200 bg-white px-4 text-sm font-medium text-blue-700">返回活动，继续发布 · {{ $campaign->name }}</a>@endif
    @if(!$siteKey)
        <p class="rounded-xl border border-blue-100 bg-blue-50 p-5 text-sm text-blue-900">请先选择一个来源站点。</p>
    @else
        @php
            $settingsInput = request()->old('site_key') === $siteKey ? request()->session()->getOldInput() : [];
            $formSettings = array_replace($settings, $settingsInput);
            if ($settingsInput !== []) $formSettings['enabled'] = (bool) ($settingsInput['enabled'] ?? false);
        @endphp
        <section class="max-w-3xl overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm" aria-labelledby="site-context-title">
            <div class="border-b border-gray-100 bg-gray-50/70 px-5 py-4 sm:px-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">当前来源站点</p>
                <h3 id="site-context-title" class="mt-1 text-lg font-semibold text-gray-950">{{ $siteName }}</h3>
                <p class="mt-1 break-all text-sm text-gray-600">{{ $siteDomain }}</p>
                <p class="mt-2 break-words text-xs text-gray-500">文章主题：<span class="font-medium text-gray-700">{{ $themeName ?: '默认主题' }}</span></p>
            </div>
            @if(!$themeReady || $adaptation?->status === 'completed')
                <div class="space-y-4 px-5 py-5 sm:px-6" data-theme-adaptation data-store-url="{{ route('admin.link-pool.theme-adaptations.store') }}" data-rollback-base="{{ url(\App\Support\AdminWeb::basePath().'/link-pool/theme-adaptations') }}" data-site-key="{{ $siteKey }}">
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700" aria-hidden="true"><i data-lucide="wand-sparkles" class="size-5"></i></span>
                        <div><h4 class="font-semibold text-gray-950">{{ $adaptation?->status === 'completed' ? ($settings['enabled'] && !$emergencyDisabled ? '推荐已开启' : '主题适配已完成') : '当前主题还差一步' }}</h4><p class="mt-1 text-sm leading-6 text-gray-600">{{ $adaptation?->status === 'completed' ? ($adaptation->article_url ? '主题版本已通过文章页检查。你可以查看文章页或撤回此次适配。' : '主题样例已通过检查。发布首篇公开文章后，即可在文章页展示推荐内容。') : 'GEOFlow 将生成可撤回的主题版本，检查文章页后开启推荐。原主题包与文章内容保持可恢复。' }}</p></div>
                    </div>
                    @if($adaptation?->status === 'completed')
                        <p class="text-xs text-gray-500">完成于 <time datetime="{{ $adaptation->finished_at?->toIso8601String() }}">{{ $adaptation->finished_at?->format('Y-m-d H:i') }}</time> · 版本 {{ substr((string) $adaptation->candidate_revision_id, 0, 8) }}</p>
                        <p class="text-sm leading-6 text-gray-600">推荐内容由已发布的投放活动提供；符合条件的文章在有可用链接时自动展示。<a href="{{ route('admin.link-pool.campaigns.index', ['site' => $siteKey]) }}" class="font-medium text-blue-700 hover:underline">查看投放活动</a></p>
                    @elseif(!app(\App\Services\Site\LinkPoolSiteService::class)->canPublish($siteKey))
                        <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">站点处于维护、归档、删除中或 noindex 状态，请恢复公开资格后重试。</p>
                    @elseif($emergencyDisabled)
                        <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">全部站点推荐已紧急停用，请先解除停用。</p>
                    @elseif(!$adaptationAvailable['available'])
                        @php
                            $adaptationReason = match (true) {
                                in_array('theme_adaptation_migration', $adaptationAvailable['missing'], true) => '主题任务数据尚未初始化，请联系管理员完成更新。',
                                in_array('theme_storage_write_access', $adaptationAvailable['missing'], true) => '主题版本存储当前不可写，请联系管理员检查。',
                                in_array('http_readback_endpoint', $adaptationAvailable['missing'], true) => '正式站点回读尚未配置，请联系管理员完成部署检查。',
                                default => '当前部署尚未确认主题版本的共享存储与升级互锁，请联系管理员完成检查。',
                            };
                        @endphp
                        <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $adaptationReason }}</p>
                    @endif
                    <div class="flex flex-wrap items-center gap-3" @if($adaptation?->status === 'completed') hidden @endif>
                        @if($canWrite)<button type="button" data-adaptation-start class="min-h-10 rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300" @disabled(!$adaptationAvailable['available'] || !app(\App\Services\Site\LinkPoolSiteService::class)->canPublish($siteKey) || $emergencyDisabled)>一键适配并开启推荐</button>@endif
                        <span class="text-xs text-gray-500">自动定位推荐区，无需编辑主题文件。</span>
                    </div>
                    <div data-adaptation-status aria-live="polite" class="text-sm text-gray-700" @if(!$adaptation) hidden @endif @if($adaptation) data-task-url="{{ route('admin.link-pool.theme-adaptations.show', $adaptation->id) }}" data-task-id="{{ $adaptation->id }}" @endif>
                        @if($adaptation)<p>{{ match($adaptation->status) { 'queued' => '正在等待处理', 'running' => '正在适配主题', 'completed' => '主题适配已完成', 'rolled_back' => '本次适配已撤回', default => $adaptation->error_message ?: '主题适配未完成' } }}</p>@endif
                    </div>
                    <div data-adaptation-actions class="flex flex-wrap gap-3" @if(!$adaptation || $adaptation->status !== 'completed') hidden @endif>
                        @if($adaptation?->article_url)<a data-adaptation-article href="{{ $adaptation->article_url }}" target="_blank" rel="noopener" class="min-h-10 rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-800">查看文章页</a>@endif
                        @if($canWrite && $adaptation)<button type="button" data-adaptation-rollback class="min-h-10 rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">撤回此次适配</button>@endif
                    </div>
                </div>
            @else
                <div class="flex items-center gap-3 px-5 py-5 text-sm text-emerald-800 sm:px-6"><i data-lucide="circle-check" class="size-5" aria-hidden="true"></i><strong>推荐区已就绪</strong><span class="text-gray-600">可以在下方调整展示设置。</span></div>
            @endif
        </section>
        <section class="max-w-3xl rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="recommendation-settings-title">
            <h3 id="recommendation-settings-title" class="text-base font-semibold text-gray-950">推荐区设置</h3>
            <p class="mt-1 text-sm text-gray-600">标题、展示数量和轮换周期会沿用到适配后的主题。</p>
            <form data-recommendation-settings data-saved-values="{{ json_encode(['heading'=>$settings['heading'],'default_limit'=>$settings['default_limit'],'rotation_hours'=>$settings['rotation_hours'],'enabled'=>$settings['enabled'] ? '1' : '0']) }}" method="POST" action="{{ route('admin.link-pool.settings.update') }}" class="mt-5 space-y-5">
                @csrf
@if($campaign)<input type="hidden" name="campaign_id" value="{{ $campaign->id }}">@endif<input type="hidden" name="site_key" value="{{ $siteKey }}"><input type="hidden" name="revision" value="{{ $formSettings['revision'] }}">
                <input type="hidden" name="enabled" value="0"><label class="flex items-center gap-3 text-sm font-medium text-gray-800"><input type="checkbox" name="enabled" value="1" @checked($formSettings['enabled']) @disabled(!$canWrite || !$themeReady) class="rounded border-gray-300 text-blue-600">开启此站文章推荐区</label>
                <label class="block text-sm font-medium text-gray-800">模块标题<input name="heading" required maxlength="60" value="{{ $formSettings['heading'] }}" @disabled(!$canWrite) class="mt-2 block w-full rounded-lg border-gray-300"></label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm font-medium text-gray-800">默认展示数量<input type="number" name="default_limit" min="1" max="6" value="{{ $formSettings['default_limit'] }}" @disabled(!$canWrite) class="mt-2 block w-full rounded-lg border-gray-300"></label>
                    <label class="block text-sm font-medium text-gray-800">轮换周期（小时）<input type="number" name="rotation_hours" min="1" max="168" value="{{ $formSettings['rotation_hours'] }}" @disabled(!$canWrite) class="mt-2 block w-full rounded-lg border-gray-300"></label>
                </div>
                @if($canWrite)<button class="min-h-10 rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700">保存站点设置</button>@endif
                @if($errors->has('revision'))<a href="{{ route('admin.link-pool.settings', ['site' => $siteKey, 'campaign_id' => $campaign?->id]) }}" class="inline-flex min-h-10 items-center text-sm font-medium text-blue-700 hover:underline">载入最新设置</a>@endif
            </form>
        </section>
    @endif
</div>
@push('scripts')<script src="{{ asset('assets/js/admin-link-pool-theme-adaptation.js') }}?v=20260930-5" defer></script>@endpush
@endsection
