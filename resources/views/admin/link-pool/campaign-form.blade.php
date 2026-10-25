@extends('admin.layouts.app')
@section('content')
@include('admin.link-pool.partials.header')
@php($draftData = is_array($payload) ? $payload : [])
@php($draftCurrent = $draft && (int)$draft->base_revision === (int)$campaign->revision)
<div class="mx-auto max-w-7xl px-4 sm:px-0">
    <a href="{{ route('admin.link-pool.campaigns.index',['site'=>$siteKey]) }}" class="text-sm text-blue-700">← 返回活动列表</a>
    <div class="mt-2 mb-5 flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-semibold">{{ $campaign ? '编辑活动 · '.$campaign->name : '新建活动' }}</h2><p class="text-sm text-gray-600">来源站点 {{ $siteLabels[$siteKey] ?? $siteKey }} · {{ $campaign?->status === 'active' ? '当前公开版本继续运行，保存为待发布草稿' : '保存草稿后可以预览和发布' }}</p></div><span class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700">当前版本 v{{ $campaign?->revision ?? 0 }}</span></div>
    @if($campaign?->status === 'paused')<p class="mb-4 rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">{{ $draftCurrent ? '活动已暂停。已保存的草稿和目标选择均已保留；确认后点击发布活动，即可恢复投放。' : '活动已暂停。下方沿用上次的投放配置；确认后保存草稿并发布，即可恢复投放。' }}</p>@endif
    <form method="GET" action="{{ $campaign ? route('admin.link-pool.campaigns.edit', $campaign->id) : route('admin.link-pool.campaigns.create') }}" class="mb-5 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4" data-link-pool-target-search>
        @unless($campaign)<input type="hidden" name="site" value="{{ $siteKey }}">@endunless
        <label class="min-w-48 flex-1 text-sm">检索目标链接（标题、URL 或目标 ID）<input name="target_q" type="search" maxlength="120" value="{{ $targetSearch }}" class="mt-1 block min-h-10 w-full rounded-lg border-gray-300"></label>
        <button class="min-h-10 rounded-lg border border-gray-300 px-4 text-sm font-medium">查找目标</button>
        <p class="w-full text-xs text-gray-500" data-link-pool-target-search-status role="status">检索时保留当前选择和未保存的活动设置。</p><noscript><p class="w-full text-xs text-gray-500">浏览器未启用脚本，检索前请先保存草稿。</p></noscript>
    </form>
    <form id="source-search-form" method="GET" action="{{ $campaign ? route('admin.link-pool.campaigns.edit', $campaign->id) : route('admin.link-pool.campaigns.create') }}" data-link-pool-source-search>@unless($campaign)<input type="hidden" name="site" value="{{ $siteKey }}">@endunless</form>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
    <form id="campaign-draft-form" method="POST" action="{{ route('admin.link-pool.campaigns.draft') }}" class="space-y-5">@csrf
        <input type="hidden" name="source_site_key" value="{{ $siteKey }}"><input type="hidden" name="base_revision" value="{{ $draftData['base_revision'] ?? $campaign?->revision ?? 0 }}">@if($campaign)<input type="hidden" name="campaign_id" value="{{ $campaign->id }}">@endif
        <section class="rounded-lg border border-gray-200 bg-white p-5"><h3 class="font-semibold text-gray-900">1. 基础</h3><div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm">活动名称<input name="name" maxlength="120" required value="{{ old('name',$draftData['name'] ?? '') }}" class="mt-1 block w-full rounded-lg border-gray-300"></label><label class="text-sm">模式<select name="mode" class="mt-1 block w-full rounded-lg border-gray-300">@foreach(['manual'=>'人工指定','related'=>'相关规则','random'=>'稳定随机','mixed'=>'混合'] as $value=>$label)<option value="{{ $value }}" @selected(old('mode',$draftData['mode'] ?? 'manual')===$value)>{{ $label }}</option>@endforeach</select></label><label class="text-sm">活动优先级<input type="number" name="priority" min="0" max="100" value="{{ old('priority',$draftData['priority'] ?? 0) }}" class="mt-1 block w-full rounded-lg border-gray-300"></label><label class="text-sm">每篇最多展示<input type="number" name="limit" min="1" max="6" value="{{ old('limit',$draftData['limit'] ?? 4) }}" class="mt-1 block w-full rounded-lg border-gray-300"></label><label class="text-sm">开始时间<input type="datetime-local" name="starts_at" value="{{ old('starts_at',isset($draftData['starts_at']) ? str_replace(' ','T',substr((string)$draftData['starts_at'],0,16)) : '') }}" class="mt-1 block w-full rounded-lg border-gray-300"></label><label class="text-sm">结束时间<input type="datetime-local" name="ends_at" value="{{ old('ends_at',isset($draftData['ends_at']) ? str_replace(' ','T',substr((string)$draftData['ends_at'],0,16)) : '') }}" class="mt-1 block w-full rounded-lg border-gray-300"></label></div></section>
        <section class="rounded-lg border border-gray-200 bg-white p-5">
            <h3 class="font-semibold text-gray-900">2. 来源范围</h3>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="text-sm">文章范围<select name="scope" class="mt-1 block w-full rounded-lg border-gray-300">@foreach(['all'=>'全部合格文章','category'=>'指定分类','articles'=>'指定文章','keyword'=>'关键词命中'] as $value=>$label)<option value="{{ $value }}" @selected(($draftData['scope'] ?? 'all')===$value)>{{ $label }}</option>@endforeach</select></label>
                <label class="text-sm" data-source-scope="category" @if(($draftData['scope'] ?? 'all')!=='category') hidden @endif>选择分类<select name="category_id" class="mt-1 block w-full rounded-lg border-gray-300"><option value="">请选择分类</option>@if(!empty($draftData['category_id']) && !$categories->contains('id', (int)$draftData['category_id']))<option value="{{ $draftData['category_id'] }}" selected>原分类已无可投放文章，请重新选择</option>@endif
@foreach($categories as $category)<option value="{{ $category->id }}" @selected((int)($draftData['category_id'] ?? 0)===(int)$category->id)>{{ $category->name }}</option>@endforeach</select></label>
                <label class="text-sm" data-source-scope="keyword" @if(($draftData['scope'] ?? 'all')!=='keyword') hidden @endif>关键词<input name="keyword" value="{{ $draftData['keyword'] ?? '' }}" class="mt-1 block w-full rounded-lg border-gray-300"><span class="mt-1 block text-xs text-gray-500">填写文章的关键词标签，精确匹配其中一项。</span></label>
            </div>
            <details data-source-chooser class="mt-4 rounded-lg border border-gray-200 p-3" @if(($draftData['scope'] ?? 'all')==='articles' || !empty($draftData['exclusion_ids'])) open @endif>
                <summary class="cursor-pointer text-sm font-medium text-gray-800">选择投放文章或排除文章</summary>
                <p class="mt-2 text-xs leading-5 text-gray-500">指定文章时勾选投放；其他范围自动包含符合条件的文章。排除对所有范围生效。</p>
                <div class="mt-3 flex flex-wrap items-end gap-2">
                    <label class="min-w-40 flex-1 text-sm">查找来源文章<input form="source-search-form" name="source_q" value="{{ $sourceSearch }}" type="search" maxlength="120" class="mt-1 block min-h-10 w-full rounded-lg border-gray-300"></label>
                    <button form="source-search-form" class="min-h-10 rounded-lg border border-gray-300 px-3 text-sm">查找来源文章</button>
                </div>
                <p data-link-pool-source-search-status role="status" class="mt-2 text-xs text-gray-500">显示最近 200 篇和已选择的文章；检索时保留选择。</p>
                <div data-link-pool-source-list class="mt-3 max-h-72 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200">
                    @forelse($sourceChoices as $choice)
                        <div class="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
                            <span class="min-w-0 flex-1 break-words font-medium text-gray-800">{{ $choice->title }}</span>
                            <div class="flex shrink-0 gap-3">
                                <label class="flex items-center gap-1.5"><input type="checkbox" name="source_article_ids[]" value="{{ $choice->id }}" @checked(in_array($choice->id, $draftData['source_article_ids'] ?? [])) class="rounded border-gray-300">投放<span class="sr-only"> · {{ $choice->title }}</span></label>
                                <label class="flex items-center gap-1.5"><input type="checkbox" name="exclusion_ids[]" value="{{ $choice->id }}" @checked(in_array($choice->id, $draftData['exclusion_ids'] ?? [])) class="rounded border-gray-300">排除<span class="sr-only"> · {{ $choice->title }}</span></label>
                            </div>
                        </div>
                    @empty<p class="p-4 text-sm text-gray-500">没有找到可投放的文章，请调整搜索词或检查文章发布状态。</p>@endforelse
                </div>
            </details>
            <noscript><style>[data-source-scope][hidden] { display:block; }</style><p class="mt-2 text-xs text-gray-500">检索前请先保存草稿；分类与关键词只在对应范围生效。</p></noscript>
        </section>
        <section class="rounded-lg border border-gray-200 bg-white p-5"><h3 class="font-semibold text-gray-900">3. 目标与审核</h3><p class="mt-1 text-sm text-gray-600">人工模式使用勾选目标；规则、随机和混合模式默认取同站文章与外部链接，勾选后只从选中目标筛选。外部链接直接使用，相关规则按主题匹配。GEOFlow 自有跨站文章沿用人工审核，每页最多 1 条。</p><div data-link-pool-target-list class="mt-4 max-h-[28rem] divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200">@forelse($targets as $target)@php($external = \App\Services\LinkPool\LinkPoolTargetService::isExternal($target))@php($cross = !$external && $target->target_site_key !== $siteKey)<div class="grid gap-2 p-3 text-sm sm:grid-cols-[1fr_auto]"><label class="flex items-start gap-2"><input type="checkbox" name="target_ids[]" value="{{ $target->id }}" @checked(in_array($target->id,$draftData['target_ids'] ?? [])) class="mt-1 rounded border-gray-300"><span><strong>{{ $target->display_title }}</strong><small class="block text-gray-500">{{ $external ? '外部链接 · '.parse_url($target->input_url, PHP_URL_HOST) : ($siteLabels[$target->target_site_key] ?? $target->target_site_key).' · 文章 '.$target->article_id }}</small></span></label><label class="flex items-center gap-1 text-xs text-gray-600"><input type="checkbox" name="pinned_ids[]" value="{{ $target->id }}" @checked(in_array($target->id,$draftData['pinned_ids'] ?? [])) class="rounded border-gray-300">置顶</label>@if($cross)<div class="sm:col-span-2 rounded-lg bg-amber-50 p-2"><label class="text-xs">跨站编辑理由<input name="review_reasons[{{ $target->id }}]" value="{{ $draftData['review_reasons'][$target->id] ?? '' }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></label><label class="mt-2 flex items-center gap-2 text-xs"><input type="checkbox" name="approved_cross_site_ids[]" value="{{ $target->id }}" @checked(in_array($target->id,$draftData['approved_cross_site_ids'] ?? [])) class="rounded border-gray-300">我已核验该跨站目标与来源内容的关联性</label></div>@endif</div>@empty<p class="p-4 text-sm text-gray-500">链接库暂无匹配的目标。<a href="{{ route('admin.link-pool.import.form', ['site' => $siteKey]) }}" class="font-medium text-blue-700 hover:underline">导入链接</a></p>@endforelse</div></section>
        <div class="flex flex-wrap gap-3">@if($canWrite)<button class="min-h-10 rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white">保存草稿</button>@endif
@if($campaign)<a href="{{ route('admin.link-pool.preview',['site'=>$siteKey,'campaign_id'=>$campaign->id,'version'=>$draft ? 'draft' : 'current']) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 px-4 text-sm">预览文章</a>@endif</div>
    </form>
    <aside class="space-y-4"><a href="{{ route('admin.link-pool.settings', ['site' => $siteKey, 'campaign_id' => $campaign?->id]) }}" class="inline-flex min-h-10 items-center text-sm font-medium text-blue-700 hover:underline">检查站点推荐设置</a>@if($errors->has('campaign') || $errors->has('base_revision'))<a href="{{ $campaign ? route('admin.link-pool.campaigns.edit', $campaign->id) : route('admin.link-pool.campaigns.create', ['site' => $siteKey]) }}" class="block text-sm font-medium text-blue-700 hover:underline">载入最新活动</a>@endif<div class="rounded-lg border border-blue-100 bg-blue-50 p-5"><h3 class="font-semibold text-blue-900">发布检查</h3><ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-blue-900"><li>当前来源站点 {{ $siteLabels[$siteKey] ?? $siteKey }}</li><li>每页上限 6 条，人工置顶最多 2 条</li><li>外部链接无需授权或逐条审核；自有跨站文章每页最多 1 条</li><li>发布会生成新版本，旧批次按需切换</li></ul></div>@if($campaign && $draft && $canWrite && !$sourceReady)<div class="rounded-lg border border-amber-200 bg-amber-50 p-4"><p class="text-sm text-amber-900">发布前请先开启此站推荐区。草稿可以先预览。</p><a href="{{ route('admin.link-pool.settings', ['site'=>$siteKey, 'campaign_id'=>$campaign->id]) }}" class="mt-3 inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white">开启推荐，继续发布</a></div>@endif
@if($campaign && $draft && !$draftCurrent)<p class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">活动状态已更新，草稿内容已保留。请先保存草稿再发布。</p>@endif
@if($campaign && $draft && $draftCurrent && $canWrite && $sourceReady)<form method="POST" action="{{ route('admin.link-pool.campaigns.publish',$campaign->id) }}" data-admin-confirm-form data-admin-confirm-tone="warning" data-admin-confirm-title="发布活动草稿" data-admin-confirm-message="相关文章下一次请求会按新规则更新推荐。" data-admin-confirm-label="确认发布" class="rounded-lg border border-gray-200 bg-white p-5">@csrf<p class="mb-3 text-sm text-gray-600">待发布草稿保存于 {{ $draft->updated_at }}。发布时会重新核验站点、文章和跨站审核。</p><button class="min-h-10 w-full rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white">发布活动</button></form>@endif
        @if($campaign && $canWrite)
            <form method="POST" action="{{ route('admin.link-pool.campaigns.copy',$campaign->id) }}" class="rounded-lg border border-gray-200 bg-white p-5">
                @csrf
                <h3 class="font-semibold">复制到站点</h3>
                <p class="mt-1 text-xs text-gray-600">复制后仅保存草稿；跨站审核和指定来源文章需要重新核验。</p>
                <label class="mt-3 block text-sm">目标来源站点
                    <select name="site_key" required class="mt-1 block w-full rounded-lg border-gray-300">
                        <option value="">请选择</option>
                        <option value="primary" @disabled($siteKey==='primary')>主站</option>
                        @foreach($profiles as $profile)
                            <option value="hosted:{{ $profile->id }}" @disabled($siteKey==='hosted:'.$profile->id)>{{ $profile->channel?->name ?? $profile->hostname }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="mt-3 min-h-10 rounded-lg border border-gray-300 px-4 text-sm font-medium">复制为草稿</button>
            </form>
        @endif
    </aside>
    </div>
</div>
@push('scripts')<script src="{{ asset('assets/js/admin-link-pool-campaign.js') }}?v=20260930-3" defer></script>@endpush
@endsection
