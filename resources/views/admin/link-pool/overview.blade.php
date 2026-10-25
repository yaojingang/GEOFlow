@extends('admin.layouts.app')
@section('content')
@include('admin.link-pool.partials.header')
<div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-0">
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-label="当前状态">
        @foreach([['运行活动',$summary['active_campaigns'],'admin.link-pool.campaigns.index'],['待发布草稿',$summary['draft_campaigns'],'admin.link-pool.campaigns.index'],['有效目标',$summary['target_count'],'admin.link-pool.targets'],['当前批次边',$summary['edge_count'],'admin.link-pool.dashboard'],['推荐覆盖来源页',$summary['source_count'],'admin.link-pool.preview'],['零入链目标',$summary['zero_inbound'],'admin.link-pool.targets']] as [$label,$value,$routeName])
            <a href="{{ route($routeName, $siteKey ? ['site' => $siteKey] : []) }}" class="rounded-lg border border-gray-200 bg-white p-5 hover:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <span class="text-sm text-gray-600">{{ $label }}</span><strong class="mt-2 block text-2xl tabular-nums text-gray-900">{{ number_format($value) }}</strong>
            </a>
        @endforeach
    </section>
    <section class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-lg font-semibold text-gray-900">开始投放</h2>
        <p class="mt-2 text-sm leading-6 text-gray-600">先导入文章或外部链接、保存活动草稿并检查预览；开启来源站点的推荐区后发布活动。投放和点击采用独立口径统计。</p>
        <div class="mt-5 flex flex-wrap gap-3">
            <a href="{{ route('admin.link-pool.import.form', ['site' => $siteKey]) }}" class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">导入链接</a>
            <a href="{{ route('admin.link-pool.campaigns.index', $siteKey ? ['site' => $siteKey] : []) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 px-4 text-sm font-medium text-gray-800">查看活动</a>
            <a href="{{ route('admin.link-pool.settings', $siteKey ? ['site' => $siteKey] : []) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 px-4 text-sm font-medium text-gray-800">站点设置</a>
        </div>
    </section>
</div>
@endsection
