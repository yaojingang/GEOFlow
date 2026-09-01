@extends('admin.layouts.app')

@section('content')
    <div class="space-y-7 px-4 sm:px-0">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('admin.distribution.hosted-sites.index') }}" class="inline-flex min-h-10 items-center text-sm font-medium text-gray-500 hover:text-gray-800 focus-visible:ring-2 focus-visible:ring-blue-500">返回托管站点</a>
                <h1 class="text-2xl font-bold text-gray-900">{{ $channel->name }}</h1>
                <a href="https://{{ $profile->hostname }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex text-sm text-blue-700 hover:text-blue-900">{{ $profile->hostname }}</a>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.distribution.hosted-sites.edit', $channel) }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-transform active:scale-[.96] hover:bg-gray-50">编辑设置</a>
                <form method="POST" action="{{ route('admin.distribution.hosted-sites.preflight', $channel) }}">@csrf<button class="min-h-10 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-transform active:scale-[.96] hover:bg-blue-700">执行预检</button></form>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-px overflow-hidden bg-gray-200 shadow-sm sm:grid-cols-4">
            @foreach ([['渠道', $channel->status], ['服务', $profile->serving_status], ['质量', $profile->quality_status], ['索引', $profile->indexing_status]] as [$label, $value])
                <div class="bg-white px-5 py-5"><div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</div><div class="mt-2 font-semibold text-gray-900">{{ $value }}</div></div>
            @endforeach
        </div>

        @php($preflight = is_array($channel->channel_config['hosted_site_preflight'] ?? null) ? $channel->channel_config['hosted_site_preflight'] : [])
        @if($preflight !== [])
            <section class="border-y border-gray-200 bg-white px-5 py-5 sm:px-7">
                <h2 class="text-sm font-semibold text-gray-900">最近预检</h2>
                <p class="mt-1 text-sm text-gray-600">{{ $preflight['checked_at'] ?? '' }} · {{ !empty($preflight['passed']) ? '全部通过' : '存在未通过项' }}</p>
                @if(!empty($preflight['failed_checks']))
                    <p class="mt-2 text-sm text-red-700">未通过：{{ implode('、', (array) $preflight['failed_checks']) }}</p>
                @endif
            </section>
        @endif

        <section class="grid gap-px overflow-hidden bg-gray-200 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['今日容量', (int) $todayUsedCount.' / '.(int) $profile->daily_publish_limit],
                ['最近健康检查', $channel->last_health_checked_at?->format('Y-m-d H:i') ?: '尚未检查'],
                ['最近发布', $profile->last_published_at?->format('Y-m-d H:i') ?: '尚未发布'],
                ['连续发布失败', (int) $profile->consecutive_publish_failures],
                ['访问归属', (int) $viewCount],
                ['线索归属', (int) $leadCount],
                ['冷却至', $profile->cooldown_until?->format('Y-m-d H:i') ?: '未冷却'],
                ['最近错误', $channel->last_error_message ?: '无'],
            ] as [$label, $value])
                <div class="bg-white px-5 py-4"><div class="text-xs font-medium text-gray-500">{{ $label }}</div><div class="mt-1 break-words text-sm font-semibold text-gray-900">{{ $value }}</div></div>
            @endforeach
        </section>

        <section class="border-y border-gray-200 bg-white px-5 py-5 sm:px-7">
            <h2 class="text-sm font-semibold text-gray-900">绑定任务</h2>
            @forelse ($boundTasks as $task)
                <p class="mt-2 text-sm text-gray-700">#{{ $task->id }} {{ $task->name }} · {{ $task->status }} · {{ $task->publish_scope }}</p>
            @empty
                <p class="mt-2 text-sm text-gray-500">暂无绑定任务</p>
            @endforelse
        </section>

        <section class="border-y border-gray-200 bg-white px-5 py-6 sm:px-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div><h2 class="text-base font-semibold text-gray-900">生命周期动作</h2><p class="mt-1 text-sm text-gray-600">激活需要预检通过，维护和归档会强制禁止索引。</p></div>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.distribution.hosted-sites.activate', $channel) }}" data-admin-confirm-form data-admin-confirm-tone="success" data-admin-confirm-title="{{ __('admin.action_dialog.hosted_site.activate_title', ['hostname' => $profile->hostname]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.hosted_site.activate_message') }}" data-admin-confirm-guidance="{{ __('admin.action_dialog.hosted_site.index_guidance', ['status' => $profile->indexing_status]) }}" data-admin-confirm-label="{{ __('admin.action_dialog.hosted_site.activate_label') }}">@csrf<button class="min-h-10 rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition-transform active:scale-[.96] hover:bg-emerald-700" data-admin-confirm-submit disabled aria-disabled="true">激活</button></form>
                    <form method="POST" action="{{ route('admin.distribution.hosted-sites.pause', $channel) }}" data-admin-confirm-form data-admin-confirm-tone="warning" data-admin-confirm-title="{{ __('admin.action_dialog.hosted_site.pause_title', ['hostname' => $profile->hostname]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.hosted_site.pause_message') }}" data-admin-confirm-guidance="{{ __('admin.action_dialog.hosted_site.pause_guidance') }}" data-admin-confirm-label="{{ __('admin.action_dialog.hosted_site.pause_label') }}">@csrf<button class="min-h-10 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-transform active:scale-[.96] hover:bg-gray-50" data-admin-confirm-submit disabled aria-disabled="true">暂停接收</button></form>
                    <form method="POST" action="{{ route('admin.distribution.hosted-sites.maintenance', $channel) }}" data-admin-confirm-form data-admin-confirm-tone="warning" data-admin-confirm-title="{{ __('admin.action_dialog.hosted_site.maintenance_title', ['hostname' => $profile->hostname]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.hosted_site.maintenance_message') }}" data-admin-confirm-guidance="{{ __('admin.action_dialog.hosted_site.maintenance_guidance') }}" data-admin-confirm-label="{{ __('admin.action_dialog.hosted_site.maintenance_label') }}">@csrf<button class="min-h-10 rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white transition-transform active:scale-[.96] hover:bg-amber-700" data-admin-confirm-submit disabled aria-disabled="true">进入维护</button></form>
                </div>
            </div>
            <div class="mt-5 flex flex-col gap-4 border-t border-gray-100 pt-5 sm:flex-row sm:items-end sm:justify-between">
                <form method="POST" action="{{ route('admin.distribution.hosted-sites.indexing', $channel) }}" class="flex flex-wrap items-end gap-3">@csrf<div><label for="indexing_status" class="block text-xs font-medium text-gray-600">索引状态</label><select id="indexing_status" name="indexing_status" class="mt-1 rounded-md border-gray-300 text-sm"><option value="noindex">noindex</option><option value="index">index</option></select></div><label class="flex max-w-xs items-start gap-2 text-xs text-gray-600"><input type="checkbox" name="quality_confirmed" value="1" class="mt-0.5 rounded border-gray-300"><span>开放索引前，我已确认内容、版权、合规和技术预检结果。</span></label><button class="min-h-10 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-transform active:scale-[.96] hover:bg-gray-50">更新索引</button></form>
                <form method="POST" action="{{ route('admin.distribution.hosted-sites.archive', $channel) }}" class="flex flex-wrap items-end gap-3">@csrf<div><label for="archive_hostname" class="block text-xs font-medium text-gray-600">输入完整域名确认归档</label><input id="archive_hostname" name="hostname" required class="mt-1 rounded-md border-gray-300 text-sm" placeholder="{{ $profile->hostname }}"></div><button class="min-h-10 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white transition-transform active:scale-[.96] hover:bg-red-700">归档站点</button></form>
            </div>
        </section>

        <section>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><h2 class="text-base font-semibold text-gray-900">文章归属</h2><p class="mt-1 text-sm text-gray-600">展示最近的预留、发布、失败和下架记录。</p></div><form method="POST" action="{{ route('admin.distribution.hosted-sites.articles.assign', $channel) }}" class="flex flex-wrap items-end gap-2">@csrf<div><label for="article_id" class="block text-xs font-medium text-gray-600">手动分配文章 ID</label><input id="article_id" name="article_id" type="number" min="1" required class="mt-1 w-36 rounded-md border-gray-300 text-sm"></div><button class="min-h-10 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-transform active:scale-[.96] hover:bg-blue-700">进入发布队列</button><span class="self-center text-sm tabular-nums text-gray-500">日限 {{ (int) $profile->daily_publish_limit }}</span></form></div>
            <div class="mt-4 overflow-x-auto border-y border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-5 py-3">文章</th><th class="px-5 py-3">状态</th><th class="px-5 py-3">容量日期</th><th class="px-5 py-3">更新时间</th></tr></thead><tbody class="divide-y divide-gray-100">@forelse ($assignments as $assignment)<tr><td class="px-5 py-4 font-medium text-gray-900">{{ $assignment->article?->title }}</td><td class="px-5 py-4 text-gray-700">{{ $assignment->status }}</td><td class="px-5 py-4 tabular-nums text-gray-600">{{ $assignment->capacity_date?->format('Y-m-d') }}</td><td class="px-5 py-4 tabular-nums text-gray-500">{{ $assignment->updated_at?->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="4" class="px-5 py-10 text-center text-gray-500">暂无文章归属记录</td></tr>@endforelse</tbody></table>
            </div>
        </section>

        <section>
            <div><h2 class="text-base font-semibold text-gray-900">最近分配请求</h2><p class="mt-1 text-sm text-gray-600">用于定位等待、重试和取消原因。</p></div>
            <div class="mt-4 overflow-x-auto border-y border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-5 py-3">文章</th><th class="px-5 py-3">状态</th><th class="px-5 py-3">尝试</th><th class="px-5 py-3">原因</th><th class="px-5 py-3">下次尝试</th></tr></thead><tbody class="divide-y divide-gray-100">@forelse ($allocationRequests as $allocationRequest)<tr><td class="px-5 py-4 font-medium text-gray-900">{{ $allocationRequest->article?->title }}</td><td class="px-5 py-4 text-gray-700">{{ $allocationRequest->status }}</td><td class="px-5 py-4 tabular-nums text-gray-600">{{ (int) $allocationRequest->attempt_count }}</td><td class="px-5 py-4 text-gray-600">{{ $allocationRequest->last_error_code ?: '无' }}@if($allocationRequest->last_error_message)<div class="mt-1 max-w-xl text-xs text-gray-500">{{ $allocationRequest->last_error_message }}</div>@endif</td><td class="px-5 py-4 tabular-nums text-gray-500">{{ $allocationRequest->next_attempt_at?->format('Y-m-d H:i') ?: '无' }}</td></tr>@empty<tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">暂无分配请求</td></tr>@endforelse</tbody></table>
            </div>
        </section>
    </div>
@endsection
