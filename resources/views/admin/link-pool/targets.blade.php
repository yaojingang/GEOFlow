@extends('admin.layouts.app')
@section('content')
@include('admin.link-pool.partials.header')
<div class="mx-auto max-w-7xl px-4 sm:px-0">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="text-lg font-semibold text-gray-900">链接库</h2><p class="text-sm text-gray-600">管理文章与外部链接。文章 URL 随站点更新；外部链接保留管理员输入的地址。访问检查结果供参考，启用状态由管理员决定。</p></div>
        @if($canWrite)<a href="{{ route('admin.link-pool.import.form', ['site' => $siteKey]) }}" class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white">导入链接</a>@endif
    </div>
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 text-sm">
        @if($siteKey)<input type="hidden" name="site" value="{{ $siteKey }}">@endif
        <label class="min-w-0 max-w-full">目标站点
            <select name="target_site" class="mt-1 block min-h-10 w-full min-w-40 max-w-full rounded-lg border-gray-300">
                <option value="">全部目标站点</option>
                <option value="primary" @selected($targetSiteKey === 'primary')>主站</option>
                <option value="external" @selected($targetSiteKey === 'external')>外部链接</option>
                @foreach($profiles as $profile)
                    <option value="hosted:{{ $profile->id }}" @selected($targetSiteKey === 'hosted:'.$profile->id)>{{ $profile->channel?->name ?? $profile->hostname }}</option>
                @endforeach
            </select>
        </label>
        <label class="min-w-0 max-w-full">状态
            <select name="status" class="mt-1 block min-h-10 w-full max-w-full rounded-lg border-gray-300">
                <option value="">全部状态</option>
                <option value="active" @selected($status === 'active')>启用</option>
                <option value="paused" @selected($status === 'paused')>暂停</option>
            </select>
        </label>
        <label class="min-w-48 flex-1">标题或导入 URL
            <input name="q" type="search" value="{{ $search }}" maxlength="120" class="mt-1 block min-h-10 w-full rounded-lg border-gray-300">
        </label>
        <button class="min-h-10 rounded-lg bg-blue-600 px-4 font-semibold text-white">筛选</button>
    </form>
    @if($siteKey)<p class="mb-3 text-xs text-gray-600">当前来源站点：{{ $siteLabels[$siteKey] ?? $siteKey }}。运行活动与近 7 天点击按此来源统计；目标站点可单独筛选。</p>@endif
    <div data-link-list="targets" class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-600"><tr><th class="px-4 py-3">标题 / 当前 URL</th><th class="px-4 py-3">目标站点</th><th class="px-4 py-3">状态</th><th class="px-4 py-3 text-right">运行活动</th><th class="px-4 py-3 text-right">当前入链</th><th class="px-4 py-3 text-right">近 7 天点击</th><th class="px-4 py-3">最后修改</th><th class="px-4 py-3">操作</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($targets as $target)
                    @php
                        $siteService = app(\App\Services\Site\LinkPoolSiteService::class);
                        $external = \App\Services\LinkPool\LinkPoolTargetService::isExternal($target);
                        $liveArticle = $external ? null : $siteService->article($target->target_site_key, (int) $target->article_id);
                        $currentUrl = $external ? app(\App\Services\LinkPool\LinkPoolTargetService::class)->normalizeUrl($target->input_url) : ($liveArticle ? $siteService->url($target->target_site_key, $liveArticle) : null);
                        $siteCanPublish = $external || $siteService->canPublish($target->target_site_key);
                        $targetExpired = $target->expires_at && \Illuminate\Support\Carbon::parse($target->expires_at)->isPast();
                        $targetState = !$external && !$liveArticle ? '文章不可访问' : (!$siteCanPublish ? '站点停用' : ($targetExpired ? '已过期' : $target->status));
                    @endphp
                    <tr class="align-top">
                        <td class="min-w-64 px-4 py-4">
                            @if($external)<strong class="block text-gray-900">{{ $target->display_title }}</strong>
                            @else<a href="{{ route('admin.articles.edit', $target->article_id) }}" class="block font-semibold text-blue-700">{{ $target->display_title }}</a><span class="block text-xs text-gray-500">文章现标题：{{ $liveArticle?->title ?? '不可访问' }}</span>@endif
                            @if($currentUrl)<a href="{{ $currentUrl }}" target="_blank" rel="noopener" class="mt-1 block max-w-sm break-all text-xs text-blue-700 underline">{{ $currentUrl }}</a>@endif
                            @if($external)
                                <p class="mt-2 text-xs {{ $target->health_status === 'reachable' ? 'text-green-700' : 'text-amber-700' }}">{{ $target->health_status === 'pending' ? '等待访问检查，稍后刷新查看' : ($target->health_message ?: '尚未检查') }}</p>
                                @if($target->health_checked_at && $target->health_status !== 'pending')<p class="mt-1 text-xs text-gray-500">检查于 {{ $target->health_checked_at }}</p>@endif
                            @endif
                        </td>
                        <td class="px-4 py-4 text-gray-600">{{ $external ? '外部链接' : $target->target_site_key }}</td>
                        <td class="px-4 py-4"><span class="{{ $targetState === 'active' ? 'text-green-700' : 'text-amber-700' }}">{{ ['active' => '启用', 'paused' => '暂停'][$targetState] ?? $targetState }}</span></td>
                        <td class="px-4 py-4 text-right tabular-nums">{{ $campaignCounts[$target->id]->campaign_count ?? 0 }}</td>
                        <td class="px-4 py-4 text-right tabular-nums">{{ $inbound[$target->id]->edge_count ?? 0 }}</td>
                        <td class="px-4 py-4 text-right tabular-nums">{{ number_format($clicks[$target->id]->clicks ?? 0) }}</td>
                        <td class="px-4 py-4 whitespace-nowrap text-gray-500">{{ $target->updated_at }}</td>
                        <td class="px-4 py-4">@if($canWrite)<details><summary class="cursor-pointer text-blue-700">编辑</summary><form method="POST" action="{{ route('admin.link-pool.targets.update', $target->id) }}" class="mt-3 grid min-w-56 gap-2">@csrf @method('PATCH')<label class="text-xs">展示标题<input name="display_title" maxlength="120" value="{{ $target->display_title }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label><label class="text-xs">主题<input name="topic" value="{{ $target->topic }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label><label class="text-xs">优先级<input type="number" name="priority" min="1" max="10" value="{{ $target->priority }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label><label class="text-xs">状态<select name="status" class="mt-1 w-full rounded-lg border-gray-300 text-sm"><option value="active" @selected($target->status==='active')>启用</option><option value="paused" @selected($target->status==='paused')>暂停</option></select></label><button class="min-h-10 rounded-lg bg-blue-600 px-3 text-white">保存</button></form></details>@if($external)<form method="POST" action="{{ route('admin.link-pool.targets.check', $target->id) }}" class="mt-3">@csrf<button class="min-h-10 rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700">重新检查访问</button></form>@endif @endif</td>
                    </tr>
                @empty<tr><td colspan="8" class="px-4 py-10 text-center text-gray-500">当前筛选下没有目标。调整筛选条件或导入文章、外部网页链接。</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $targets->links() }}</div>
</div>
@endsection
