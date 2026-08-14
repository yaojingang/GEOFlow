@extends('admin.layouts.app')

@section('content')
<div class="px-4 sm:px-0">
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div><h1 class="text-2xl font-bold text-gray-900">正负零官网内容</h1><p class="mt-1 text-sm text-gray-600">公众页面只读取活动发布快照；草稿保存后不会直接改变官网。</p></div>
        <div class="flex gap-2"><a href="{{ route('site.home') }}" target="_blank" rel="noopener" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">预览官网</a><a href="{{ route('admin.public-facts.index') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">事实与证据</a></div>
    </div>
    @if(session('message'))<div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('message') }}</div>@endif
    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">页面</th><th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">区域</th><th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">四门审核</th><th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">公开状态</th><th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">操作</th></tr></thead>
            <tbody class="divide-y divide-gray-200">
            @foreach($pages as $page)
                @php($approved = $page->approvals->where('content_hash', $page->content_hash)->where('decision', 'approved')->pluck('gate')->unique())
                <tr><td class="px-5 py-4"><div class="font-semibold text-gray-900">{{ $page->title }}</div><div class="mt-1 font-mono text-xs text-gray-500">{{ $page->slug }} · v{{ $page->version }}</div></td><td class="px-5 py-4 text-sm text-gray-600">{{ ['institution'=>'机构','health'=>'健康科普','governance'=>'治理'][$page->area] ?? $page->area }}</td><td class="px-5 py-4"><div class="flex flex-wrap gap-1">@foreach($gates as $gate)<span class="rounded-full px-2 py-1 text-xs {{ $approved->contains($gate) ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ $gate }}</span>@endforeach</div></td><td class="px-5 py-4"><span class="rounded-full px-2 py-1 text-xs {{ $page->activeSnapshot ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' }}">{{ $page->activeSnapshot ? '活动快照' : '未公开' }}</span>@if($page->is_placeholder)<span class="ml-1 rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-600">占位 noindex</span>@endif</td><td class="px-5 py-4 text-right"><a href="{{ route('admin.public-pages.edit', $page->id) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-800">编辑与审核</a></td></tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
</div>
@endsection
