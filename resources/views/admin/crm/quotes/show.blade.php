@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.crm.quotes.index') }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $quote->quote_no }}</h1>
                </div>
                <p class="mt-2 text-sm text-gray-600">{{ $quote->title }} · {{ $quote->status }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.crm.orders.from-quote', ['quoteId' => (int) $quote->id]) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100">
                        <i data-lucide="file-check-2" class="mr-2 h-4 w-4"></i>
                        生成订单
                    </button>
                </form>
                <div class="relative inline-flex items-center">
                    <select onchange="if(this.value) window.open(this.value, '_blank')" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 cursor-pointer appearance-none pr-8">
                        <option value="">打印单据...</option>
                        <option value="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => 'quotation']) }}">报价单</option>
                        <option value="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => 'proforma_invoice']) }}">形式发票</option>
                        <option value="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => 'invoice']) }}">正式发票</option>
                        <option value="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => 'packing_list']) }}">装箱单</option>
                        <option value="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => 'contract']) }}">合同</option>
                    </select>
                    <i data-lucide="chevron-down" class="pointer-events-none absolute right-2 h-4 w-4 text-gray-400"></i>
                </div>
                <form method="POST" action="{{ route('admin.crm.quotes.convert',['quoteId'=>$quote->id]) }}" class="flex items-center">@csrf<select name="document_type" class="rounded-l-md border border-r-0 border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"><option value="proforma_invoice">转形式发票</option><option value="invoice">转正式发票</option><option value="packing_list">转装箱单</option><option value="contract">转合同</option><option value="quotation">转报价单</option></select><button class="rounded-r-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">创建副本</button></form>
                <a href="{{ route('admin.crm.quotes.edit', ['quoteId' => (int) $quote->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="pencil" class="mr-2 h-4 w-4"></i>
                    编辑
                </a>
                <form method="POST" action="{{ route('admin.crm.quotes.delete', ['quoteId' => (int) $quote->id]) }}" onsubmit="return confirm('确认归档此单据？关联订单不会被删除。')">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                        <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                        归档
                    </button>
                </form>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'quotes'])

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                <h2 class="text-base font-semibold text-gray-900">报价明细</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">项目</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Entity</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">SKU / 型号</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500">数量</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500">单价</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500">金额</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($quote->items as $item)
                                @php
                                    $itemImageUrl = $item->image
                                        ? \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl((string) $item->image->file_path)
                                        : \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl((string) ($item->image_path ?? ''));
                                @endphp
                                <tr>
                                    <td class="px-4 py-3">
                                        <div class="flex gap-3">
                                            @if ($itemImageUrl !== '')
                                                <img src="{{ $itemImageUrl }}" alt="" class="h-12 w-12 rounded-md border border-gray-200 object-cover">
                                            @endif
                                            <div>
                                                <div class="font-medium text-gray-900">{{ $item->item_name }}</div>
                                                <div class="mt-1 text-xs text-gray-500">{{ ['product' => '产品', 'accessory' => '配件', 'service' => '服务', 'spare_part' => '备件', 'training' => '培训', 'other' => '其他'][$item->line_type] ?? '产品' }}</div>
                                                @if ((string) ($item->description ?? '') !== '')
                                                    <div class="mt-1 whitespace-pre-wrap text-xs leading-5 text-gray-500">{{ $item->description }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">{{ $item->entity?->name ?? '未关联' }}</td>
                                    <td class="px-4 py-3 text-gray-600">
                                        <div>{{ trim((string) ($item->sku ?? '').' / '.(string) ($item->model ?? ''), ' /') ?: '-' }}</div>
                                        @if ((string) ($item->hs_code ?? '') !== '')
                                            <div class="mt-1 text-xs text-gray-500">HS: {{ $item->hs_code }}</div>
                                        @endif
                                        @if ((int) ($item->package_count ?? 0) > 0 || (float) ($item->gross_weight ?? 0) > 0)
                                            <div class="mt-1 text-xs text-gray-500">包装 {{ (int) $item->package_count }} 件 · 毛重 {{ number_format((float) $item->gross_weight, 3) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-600">{{ number_format((float) $item->quantity, 2) }} {{ $item->unit }}</td>
                                    <td class="px-4 py-3 text-right text-gray-600">{{ number_format((float) $item->unit_price, 2) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900">{{ number_format((float) $item->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">暂无明细</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="5" class="px-4 py-3 text-right font-semibold text-gray-900">合计</td>
                                <td class="px-4 py-3 text-right text-base font-semibold text-gray-900">{{ $quote->currency }} {{ number_format((float) $quote->total_amount, 2) }}</td>
                            </tr>
                            <tr>
                                <td colspan="5" class="px-4 py-3 text-right font-semibold text-gray-900">运费 / 税费 / 折扣</td>
                                <td class="px-4 py-3 text-right text-gray-700">
                                    +{{ number_format((float) $quote->shipping_fee, 2) }}
                                    +{{ number_format((float) $quote->tax_amount, 2) }}
                                    -{{ number_format((float) $quote->discount_amount, 2) }}
                                </td>
                            </tr>
                            <tr>
                                <td colspan="5" class="px-4 py-3 text-right font-semibold text-gray-900">最终合计</td>
                                <td class="px-4 py-3 text-right text-base font-semibold text-blue-700">{{ $quote->currency }} {{ number_format((float) ($quote->grand_total ?: $quote->total_amount), 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            @if ($quote->inquiry?->customer?->followUps?->isNotEmpty())
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="message-square-text" class="mr-2 inline-block h-4 w-4 text-gray-500"></i>跟进记录</h2>
                    <div class="mt-4 space-y-3">
                        @foreach ($quote->inquiry->customer->followUps as $followUp)
                            @include('admin.crm.partials._follow-up-item', ['followUp' => $followUp, 'showInquiryLink' => true])
                        @endforeach
                    </div>
                </section>
                @endif

            <aside class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">基础信息</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div><dt class="text-gray-500">客户</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->customer?->contact_person ?: $quote->customer?->company_name ?? '未关联' }}</dd></div>
                        <div><dt class="text-gray-500">买方</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->buyer_company ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">负责人</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->owner ?: '未指定' }}</dd></div>
                        <div><dt class="text-gray-500">业务容器</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->collection?->name ?? '未指定' }}</dd></div>
                        <div>
                            <dt class="text-gray-500">关联商机</dt>
                            <dd class="mt-1 font-medium text-gray-900">
                                @if ($quote->opportunity)
                                    <a href="{{ route('admin.crm.opportunities.edit', ['opportunityId' => (int) $quote->opportunity->id]) }}" class="text-blue-600 hover:text-blue-700">{{ $quote->opportunity->name }}</a>
                                @else
                                    未关联
                                @endif
                            </dd>
                        </div>
                        <div><dt class="text-gray-500">文档类型</dt><dd class="mt-1 font-medium text-gray-900">{{ ['quotation' => '报价单', 'proforma_invoice' => '形式发票', 'invoice' => '正式发票', 'packing_list' => '装箱单', 'contract' => '合同'][$quote->document_type] ?? '报价单' }}</dd></div>
                        <div><dt class="text-gray-500">贸易条款</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->trade_term ?: '未设置' }}</dd></div>
                        <div><dt class="text-gray-500">有效期</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->valid_until?->format('Y-m-d') ?? '未设置' }}</dd></div>
                        <div><dt class="text-gray-500">交期</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->lead_time ?: '未设置' }}</dd></div>
                    </dl>
                    @if ($quote->inquiry)
                        <a href="{{ route('admin.crm.inquiries.show', ['inquiryId' => (int) $quote->inquiry->id]) }}" class="mt-4 inline-flex text-sm font-medium text-blue-600 hover:text-blue-700">查看关联询盘</a>
                    @endif
                </section>
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">条款与备注</h2>
                    <div class="mt-4 space-y-4 text-sm leading-6 text-gray-700">
                        <div><div class="font-medium text-gray-900">付款条款</div><div class="mt-1 whitespace-pre-wrap">{{ $quote->payment_terms ?: '未填写' }}</div></div>
                        <div><div class="font-medium text-gray-900">交付条款</div><div class="mt-1 whitespace-pre-wrap">{{ $quote->delivery_terms ?: '未填写' }}</div></div>
                        <div><div class="font-medium text-gray-900">质保条款</div><div class="mt-1 whitespace-pre-wrap">{{ $quote->warranty_terms ?: '未填写' }}</div></div>
                        <div><div class="font-medium text-gray-900">安装条款</div><div class="mt-1 whitespace-pre-wrap">{{ $quote->installation_terms ?: '未填写' }}</div></div>
                        <div><div class="font-medium text-gray-900">合同自定义条款</div><div class="mt-1 whitespace-pre-wrap">{{ $quote->contract_terms ?: '未填写' }}</div></div>
                        <div><div class="font-medium text-gray-900">客户备注</div><div class="mt-1 whitespace-pre-wrap">{{ $quote->notes ?: '未填写' }}</div></div>
                    </div>
                </section>
            </aside>
        </div>
    </div>
@endsection
