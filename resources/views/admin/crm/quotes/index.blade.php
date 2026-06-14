@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">单据制作</h1>
                <p class="mt-1 text-sm text-gray-600">管理报价单、形式发票、正式发票、装箱单和合同等各类业务单据。</p>
            </div>
            <a href="{{ route('admin.crm.quotes.create') }}" class="inline-flex w-fit items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                新增单据
            </a>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'quotes'])

        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200"><div class="text-sm text-gray-500">单据总数</div><div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['total'] ?? 0)) }}</div></div>
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200"><div class="text-sm text-gray-500">草稿</div><div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['draft'] ?? 0)) }}</div></div>
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200"><div class="text-sm text-gray-500">已发送</div><div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['sent'] ?? 0)) }}</div></div>
        </div>

        <form method="GET" action="{{ route('admin.crm.quotes.index') }}" class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[30%_180px_auto] lg:items-end">
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">搜索</label>
                    <input type="text" name="search" value="{{ $search }}" placeholder="报价号、标题、客户" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
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
                        @foreach (['draft' => '草稿', 'sent' => '已发送', 'accepted' => '已接受', 'rejected' => '已拒绝', 'expired' => '已过期'] as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"><i data-lucide="search" class="mr-2 h-4 w-4"></i>筛选</button>
                    <a href="{{ route('admin.crm.quotes.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">清除</a>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-200 px-6 py-4"><h3 class="text-base font-semibold text-gray-900">单据列表 <span class="text-sm text-gray-500">({{ (int) $quotes->total() }})</span></h3></div>
            @if ($quotes->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">暂无单据</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">单据</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">客户</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">金额</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($quotes as $quote)
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-900">{{ $quote->quote_no }}</div>
                                        <div class="mt-1 text-sm text-gray-600">{{ $quote->title }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $quote->collection?->name ?? '未指定' }} · {{ $quote->status }} · {{ (int) ($quote->items_count ?? 0) }} 项</div>
                                        @if ($quote->opportunity)
                                            <div class="mt-2 text-xs">
                                                <a href="{{ route('admin.crm.opportunities.edit', ['opportunityId' => (int) $quote->opportunity->id]) }}" class="inline-flex rounded-full bg-indigo-50 px-2.5 py-1 font-medium text-indigo-700 hover:bg-indigo-100">
                                                    商机：{{ $quote->opportunity->name }}
                                                </a>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="font-medium text-gray-900">{{ $quote->customer?->company_name ?? '未关联客户' }}</div>
                                        <div class="mt-1 text-gray-500">负责人：{{ $quote->owner ?: '未指定' }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $quote->currency }} {{ number_format((float) $quote->total_amount, 2) }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <a href="{{ route('admin.crm.quotes.show', ['quoteId' => (int) $quote->id]) }}" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"><i data-lucide="eye" class="mr-1 h-4 w-4"></i>查看</a>
                                            <form method="POST" action="{{ route('admin.crm.orders.from-quote', ['quoteId' => (int) $quote->id]) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center rounded border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100"><i data-lucide="file-check-2" class="mr-1 h-4 w-4"></i>创建订单</button>
                                            </form>
                                            <a href="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id]) }}" target="_blank" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"><i data-lucide="printer" class="mr-1 h-4 w-4"></i>打印</a>
                                            <a href="{{ route('admin.crm.quotes.edit', ['quoteId' => (int) $quote->id]) }}" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"><i data-lucide="pencil" class="mr-1 h-4 w-4"></i>编辑</a>
                                            <form method="POST" action="{{ route('admin.crm.quotes.delete', ['quoteId' => (int) $quote->id]) }}" onsubmit="return confirm('确认归档此单据？')">
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
                    <div class="text-sm text-gray-600">显示第 {{ $quotes->firstItem() ?? 0 }} - {{ $quotes->lastItem() ?? 0 }} 条，共 {{ $quotes->total() }} 条</div>
                    @if ($quotes->lastPage() > 1)
                        <div>{{ $quotes->links() }}</div>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
