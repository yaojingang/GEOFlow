@extends('admin.layouts.app')

@php
    $inquiryStatusOptions = [
        'new' => '新询盘',
        'analyzing' => '分析中',
        'qualified' => '已确认',
        'converted' => '已转商机',
        'invalid' => '无效',
        'closed' => '已关闭',
        'quoted' => '已报价（历史）',
        'won' => '赢单（历史）',
        'lost' => '丢单（历史）',
    ];
    $inquiryStatusTone = [
        'new' => 'bg-blue-50 text-blue-700',
        'analyzing' => 'bg-amber-50 text-amber-700',
        'qualified' => 'bg-emerald-50 text-emerald-700',
        'converted' => 'bg-purple-50 text-purple-700',
        'invalid' => 'bg-gray-100 text-gray-600',
        'closed' => 'bg-slate-100 text-slate-700',
        'quoted' => 'bg-orange-50 text-orange-700',
        'won' => 'bg-emerald-100 text-emerald-700',
        'lost' => 'bg-red-50 text-red-700',
    ];
    $priorityLabels = ['low' => '低', 'normal' => '普通', 'high' => '高', 'urgent' => '紧急'];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">询盘管理</h1>
                <p class="mt-1 text-sm text-gray-600">将客户需求与 Entity、知识库、Case 关联，为回复和报价提供上下文。</p>
            </div>
            <a href="{{ route('admin.crm.inquiries.create') }}" class="inline-flex w-fit items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                新增询盘
            </a>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'inquiries'])

        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200"><div class="text-sm text-gray-500">询盘总数</div><div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['total'] ?? 0)) }}</div></div>
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200"><div class="text-sm text-gray-500">新询盘</div><div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['new'] ?? 0)) }}</div></div>
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200"><div class="text-sm text-gray-500">高优先级</div><div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['high'] ?? 0)) }}</div></div>
        </div>

        <form method="GET" action="{{ route('admin.crm.inquiries.index') }}" class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 xl:grid-cols-[30%_170px_170px_auto] xl:items-end">
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">搜索</label>
                    <input type="text" name="search" value="{{ $search }}" placeholder="标题、客户、需求摘要" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                @include('admin.partials.collection-select', [
                    'selectedId' => (string) ($collectionId ?? ''),
                    'collectionOptions' => $collectionOptions ?? [],
                    'label' => '业务容器',
                    'help' => '',
                    'emptyLabel' => '全部业务容器',
                    'class' => 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500',
                ])
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">状态</label>
                    <select name="status" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">全部</option>
                        @foreach ($inquiryStatusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">优先级</label>
                    <select name="priority" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">全部</option>
                        @foreach (['low' => '低', 'normal' => '普通', 'high' => '高', 'urgent' => '紧急'] as $value => $label)
                            <option value="{{ $value }}" @selected($priority === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"><i data-lucide="search" class="mr-2 h-4 w-4"></i>筛选</button>
                    <a href="{{ route('admin.crm.inquiries.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">清除</a>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-200 px-6 py-4"><h3 class="text-base font-semibold text-gray-900">询盘列表 <span class="text-sm text-gray-500">({{ (int) $inquiries->total() }})</span></h3></div>
            @if ($inquiries->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">暂无询盘</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">询盘</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">客户</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">引用资料</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($inquiries as $inquiry)
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-900">{{ $inquiry->subject }}</div>
                                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">{{ $inquiry->collection?->name ?? '未指定' }}</span>
                                            <span class="rounded-full px-2.5 py-1 font-medium {{ $inquiryStatusTone[$inquiry->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $inquiryStatusOptions[$inquiry->status] ?? $inquiry->status }}</span>
                                            <span class="rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-700">优先级：{{ $priorityLabels[$inquiry->priority] ?? $inquiry->priority }}</span>
                                        </div>
                                        @if ((string) ($inquiry->customer_need_summary ?? '') !== '')
                                            <div class="mt-2 max-w-2xl text-sm leading-6 text-gray-600">{{ \Illuminate\Support\Str::limit($inquiry->customer_need_summary, 140) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="font-medium text-gray-900">{{ $inquiry->customer?->company_name ?? '未关联客户' }}</div>
                                        <div class="mt-1 text-gray-500">负责人：{{ $inquiry->assigned_to ?: '未指定' }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-wrap gap-2 text-xs font-medium">
                                            <span class="rounded bg-blue-50 px-2 py-1 text-blue-700">{{ (int) ($inquiry->entities_count ?? 0) }} Entity</span>
                                            <span class="rounded bg-orange-50 px-2 py-1 text-orange-700">{{ (int) ($inquiry->knowledge_bases_count ?? 0) }} 知识库</span>
                                            <span class="rounded bg-emerald-50 px-2 py-1 text-emerald-700">{{ (int) ($inquiry->cases_count ?? 0) }} Case</span>
                                            <span class="rounded bg-indigo-50 px-2 py-1 text-indigo-700">{{ (int) ($inquiry->opportunities_count ?? 0) }} 商机</span>
                                            <span class="rounded bg-purple-50 px-2 py-1 text-purple-700">{{ (int) ($inquiry->quotes_count ?? 0) }} 报价</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <a href="{{ route('admin.crm.inquiries.show', ['inquiryId' => (int) $inquiry->id]) }}" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"><i data-lucide="eye" class="mr-1 h-4 w-4"></i>查看</a>
                                            <a href="{{ route('admin.crm.inquiries.edit', ['inquiryId' => (int) $inquiry->id]) }}" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"><i data-lucide="pencil" class="mr-1 h-4 w-4"></i>编辑</a>
                                            @if ((int) ($inquiry->opportunities_count ?? 0) > 0)
                                                <a href="{{ route('admin.crm.opportunities.index', ['collection_id' => (int) ($inquiry->collection_id ?? 0)]) }}" class="inline-flex items-center rounded border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100"><i data-lucide="briefcase-business" class="mr-1 h-4 w-4"></i>商机</a>
                                            @endif
                                            <a href="{{ route('admin.crm.quotes.create', ['inquiry_id' => (int) $inquiry->id]) }}" class="inline-flex items-center rounded border border-purple-200 bg-purple-50 px-3 py-1.5 text-xs font-medium text-purple-700 hover:bg-purple-100"><i data-lucide="file-plus-2" class="mr-1 h-4 w-4"></i>报价</a>
                                            <form method="POST" action="{{ route('admin.crm.inquiries.delete', ['inquiryId' => (int) $inquiry->id]) }}" onsubmit="return confirm('确认归档此询盘？关联商业记录将保留。')">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center rounded border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"><i data-lucide="trash-2" class="mr-1 h-3.5 w-3.5"></i>删除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-col gap-3 border-t border-gray-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="text-sm text-gray-600">显示第 {{ $inquiries->firstItem() ?? 0 }} - {{ $inquiries->lastItem() ?? 0 }} 条，共 {{ $inquiries->total() }} 条</div>
                    @if ($inquiries->lastPage() > 1)
                        <div>{{ $inquiries->links() }}</div>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
