@extends('admin.layouts.app')

@section('content')
@include('admin.link-pool.partials.header')
<div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-0">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">效果看板</h2>
            <p class="mt-1 text-sm text-gray-600">按来源站点统计 · 更新至 {{ now()->format('Y-m-d H:i') }}。服务端输出、读者行为和爬虫访问分别计数。</p>
        </div>
        <a href="{{ route('admin.link-pool.dashboard.csv',request()->only(['site','target_site','campaign_id','days'])) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-700">导出每日 CSV</a>
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 text-sm">
        @if($siteKey)<input type="hidden" name="site" value="{{ $siteKey }}">@endif
        <label class="min-w-0 max-w-full">目标站点
            <select name="target_site" class="mt-1 block min-h-10 w-full min-w-40 max-w-full rounded-lg border-gray-300">
                <option value="">全部目标站点</option>
                <option value="primary" @selected($targetSiteKey==='primary')>主站</option>
                <option value="external" @selected($targetSiteKey==='external')>外部链接</option>
                @foreach($profiles as $profile)
                    <option value="hosted:{{ $profile->id }}" @selected($targetSiteKey==='hosted:'.$profile->id)>{{ $profile->channel?->name ?? $profile->hostname }}</option>
                @endforeach
            </select>
        </label>
        <label class="min-w-0 max-w-full">投放活动<select name="campaign_id" class="mt-1 block min-h-10 w-full max-w-full rounded-lg border-gray-300"><option value="">全部活动</option>@foreach($campaignChoices as $choice)<option value="{{ $choice->id }}" @selected($campaignId === (int)$choice->id)>{{ $choice->name }}{{ !$siteKey ? ' · '.($siteLabels[$choice->source_site_key] ?? $choice->source_site_key) : '' }}</option>@endforeach</select></label>
        <label class="min-w-0 max-w-full">时间范围<select name="days" class="mt-1 block min-h-10 w-full max-w-full rounded-lg border-gray-300">
            @foreach([7,30,90] as $option)
                <option value="{{ $option }}" @selected($days===$option)>近 {{ $option }} 天</option>
            @endforeach
        </select></label>
        <button class="min-h-10 rounded-lg bg-blue-600 px-4 font-semibold text-white">筛选</button>
    </form>

    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="结构与行为概览">
        @foreach([
            ['有效目标',$targetRows->count(),'当前链接库中的启用目标'],
            ['当前批次边',$edgeCount,'当前推荐批次中仍启用的链接'],
            ['零入链目标',$zeroInbound,'未在当前批次获得推荐入口'],
            ['已覆盖来源页',$coveredSources,$eligibleSources === null ? '选择来源站点查看覆盖率' : '合格来源页 '.$eligibleSources.' 篇'],
            ['200 HTML 输出次数',$totals->html_served ?? 0,'服务端真实输出推荐条目'],
            ['读者可见次数',$totals->viewable ?? 0,'浏览器事件，脚本关闭时不采集'],
            ['读者点击次数',$totals->human_clicks ?? 0,'同源事件，短期去重'],
            ['点击 / 可见',($totals->viewable ?? 0) > 0 ? number_format(($totals->human_clicks ?? 0)/$totals->viewable*100,1).'%' : '—','低样本请谨慎解读'],
        ] as [$label,$value,$hint])
            <div class="rounded-lg border border-gray-200 bg-white p-5">
                <span class="text-sm text-gray-600">{{ $label }}</span>
                <strong class="mt-2 block text-2xl tabular-nums">{{ is_numeric($value) ? number_format($value) : $value }}</strong>
                <small class="mt-2 block text-gray-500">{{ $hint }}</small>
            </div>
        @endforeach
    </section>

    <section class="grid gap-5 lg:grid-cols-2">
        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <h3 class="font-semibold">入链分布</h3>
            <p class="mt-1 text-xs text-gray-500">只统计本模块当前推荐批次。</p>
            <div class="mt-5 space-y-3">
                @foreach($histogram as $bucket => $count)
                    <div class="flex items-center gap-3 text-sm"><span class="w-10 shrink-0">{{ $bucket }}</span><span class="h-3 flex-1 overflow-hidden rounded-full bg-gray-100"><span class="block h-full rounded-full bg-blue-600" style="width:{{ $targetRows->count() ? round($count/$targetRows->count()*100) : 0 }}%"></span></span><span class="w-12 text-right tabular-nums">{{ $count }}</span></div>
                @endforeach
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <h3 class="font-semibold">爬虫文章访问</h3>
            <p class="mt-1 text-xs text-gray-500">来源文章访问日志，按 User-Agent 分类。与推荐点击不做归因。</p>
            <dl class="mt-5 grid grid-cols-2 gap-4"><div><dt class="text-sm text-gray-600">搜索机器人</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($bots['search_bot']) }}</dd></div><div><dt class="text-sm text-gray-600">AI 机器人</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($bots['ai_bot']) }}</dd></div></dl>
            <p class="mt-3 text-xs text-gray-500">来源页状态码：
                @forelse($botStatus as $type => $codes)
                    {{ $type === 'search_bot' ? '搜索' : 'AI' }}
                    @foreach($codes as $code => $count){{ $code }}: {{ $count }} @endforeach
                @empty
                    暂无记录
                @endforelse
            </p>
            <p class="mt-3 border-t border-gray-200 pt-3 text-xs text-gray-600">目标页爬虫访问：
                @if($targetSiteKey === 'external')
                    GEOFlow 无法采集外部网站的访问日志
                @elseif($targetBots === null)
                    请选择目标站点查看
                @else
                    搜索 {{ number_format($targetBots['search_bot']) }} · AI {{ number_format($targetBots['ai_bot']) }}
                @endif
            </p>
            @if($siteKey && !app(\App\Services\Site\LinkPoolSiteService::class)->canPublish($siteKey))
                <p class="mt-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">站点当前不对外投放；请检查维护、归档、删除中或 noindex 状态。</p>
            @endif
        </div>
    </section>

    <figure class="rounded-lg border border-gray-200 bg-white p-5">
        <figcaption class="font-semibold">按日趋势图</figcaption>
        <p class="mt-1 text-xs text-gray-500">HTML 输出是推荐条目次数；可见与点击来自读者浏览器事件。</p>
        <div class="mt-4 overflow-x-auto">
            <svg class="min-w-[38rem] w-full" viewBox="0 0 720 200" role="img" aria-labelledby="link-pool-chart-title link-pool-chart-desc">
                <title id="link-pool-chart-title">近 {{ $days }} 天推荐输出、可见与点击趋势</title>
                <desc id="link-pool-chart-desc">最大单日计数 {{ $chartMax }}；具体数值见下方每日明细表。</desc>
                <line x1="48" y1="40" x2="688" y2="40" stroke="#e5e7eb" />
                <line x1="48" y1="100" x2="688" y2="100" stroke="#e5e7eb" />
                <line x1="48" y1="160" x2="688" y2="160" stroke="#d1d5db" />
                <text x="4" y="44" fill="#6b7280" font-size="11">{{ $chartMax }}</text>
                <text x="22" y="164" fill="#6b7280" font-size="11">0</text>
                <polyline points="{{ $chartSeries['html_served'] }}" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                <polyline points="{{ $chartSeries['viewable'] }}" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                <polyline points="{{ $chartSeries['human_clicks'] }}" fill="none" stroke="#d97706" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                <text x="48" y="187" fill="#6b7280" font-size="11">{{ $chartDays->first()['date'] }}</text>
                <text x="688" y="187" fill="#6b7280" font-size="11" text-anchor="end">{{ $chartDays->last()['date'] }}</text>
            </svg>
        </div>
        <div class="mt-3 flex flex-wrap gap-4 text-xs text-gray-700" aria-hidden="true">
            <span><span class="mr-1 inline-block h-2 w-4 rounded bg-blue-600"></span>HTML 输出</span>
            <span><span class="mr-1 inline-block h-2 w-4 rounded bg-green-600"></span>读者可见</span>
            <span><span class="mr-1 inline-block h-2 w-4 rounded bg-amber-600"></span>读者点击</span>
        </div>
    </figure>

    <section class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <div class="border-b border-gray-200 p-4"><h3 class="font-semibold">每日明细</h3><p class="text-xs text-gray-500">与上方趋势图使用相同站点、活动和时间筛选。</p></div>
        <table class="min-w-full divide-y divide-gray-100 text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-600"><tr><th class="px-4 py-3">日期</th><th class="px-4 py-3 text-right">HTML 输出</th><th class="px-4 py-3 text-right">可见</th><th class="px-4 py-3 text-right">点击</th></tr></thead><tbody class="divide-y divide-gray-100">
            @forelse($trend as $row)
                <tr><td class="px-4 py-3">{{ $row->date }}</td><td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->html_served) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->viewable) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->human_clicks) }}</td></tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">暂无统计数据。服务端输出后会在这里出现。</td></tr>
            @endforelse
        </tbody></table>
    </section>
    <section class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <div class="border-b border-gray-200 p-4"><h3 class="font-semibold">活动表现</h3></div>
        <table class="min-w-full divide-y divide-gray-100 text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-600"><tr><th class="px-4 py-3">活动</th><th class="px-4 py-3 text-right">HTML 输出</th><th class="px-4 py-3 text-right">可见</th><th class="px-4 py-3 text-right">点击</th></tr></thead><tbody class="divide-y divide-gray-100">
            @forelse($perCampaign as $row)
                <tr><td class="px-4 py-3"><a href="{{ route('admin.link-pool.campaigns.edit',$row->campaign_id) }}" class="text-blue-700">{{ $campaignChoices->firstWhere('id', $row->campaign_id)?->name ?? ('已移除活动 #'.$row->campaign_id) }}</a></td><td class="px-4 py-3 text-right">{{ number_format($row->html_served) }}</td><td class="px-4 py-3 text-right">{{ number_format($row->viewable) }}</td><td class="px-4 py-3 text-right">{{ number_format($row->human_clicks) }}</td></tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">暂无活动数据。</td></tr>
            @endforelse
        </tbody></table>
    </section>
</div>
@endsection
