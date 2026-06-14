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
    $opportunityStageLabels = \App\Http\Controllers\Admin\CrmOpportunityController::STAGES;
    $priorityLabels = ['low' => '低', 'normal' => '普通', 'high' => '高', 'urgent' => '紧急'];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.crm.inquiries.index') }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $inquiry->subject }}</h1>
                </div>
                <p class="mt-2 text-sm text-gray-600">{{ $inquiry->collection?->name ?? '未指定业务容器' }} · {{ $inquiryStatusOptions[$inquiry->status] ?? $inquiry->status }} · {{ $priorityLabels[$inquiry->priority] ?? $inquiry->priority }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.crm.proposals.from-inquiry', ['inquiryId' => (int) $inquiry->id]) }}">
                    @csrf
                    <input type="hidden" name="proposal_type" value="title_suggestion">
                    <button type="submit" class="inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">
                        <i data-lucide="type" class="mr-2 h-4 w-4"></i>
                        生成标题候选
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.crm.proposals.from-inquiry', ['inquiryId' => (int) $inquiry->id]) }}">
                    @csrf
                    <input type="hidden" name="proposal_type" value="faq_draft">
                    <button type="submit" class="inline-flex items-center rounded-md border border-orange-200 bg-orange-50 px-4 py-2 text-sm font-medium text-orange-700 hover:bg-orange-100">
                        <i data-lucide="file-question" class="mr-2 h-4 w-4"></i>
                        生成 FAQ 候选
                    </button>
                </form>
                <a href="{{ route('admin.crm.quotes.create', ['inquiry_id' => (int) $inquiry->id]) }}" class="inline-flex items-center rounded-md border border-purple-200 bg-purple-50 px-4 py-2 text-sm font-medium text-purple-700 hover:bg-purple-100">
                    <i data-lucide="file-plus-2" class="mr-2 h-4 w-4"></i>
                    生成报价
                </a>
                @if($inquiry->opportunities->isEmpty())
                    <form method="POST" action="{{ route('admin.crm.opportunities.from-inquiry', ['inquiryId' => (int) $inquiry->id]) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100">
                            <i data-lucide="briefcase-business" class="mr-2 h-4 w-4"></i>
                            转为商机
                        </button>
                    </form>
                @else
                    @foreach ($inquiry->opportunities->take(1) as $opportunity)
                        <a href="{{ route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunity->id]) }}" class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100">
                            <i data-lucide="briefcase-business" class="mr-2 h-4 w-4"></i>
                            查看商机
                        </a>
                    @endforeach
                @endif
                <a href="{{ route('admin.crm.inquiries.edit', ['inquiryId' => (int) $inquiry->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="pencil" class="mr-2 h-4 w-4"></i>
                    编辑
                </a>
                <form method="POST" action="{{ route('admin.crm.inquiries.delete', ['inquiryId' => (int) $inquiry->id]) }}" onsubmit="return confirm('归档后询盘不再出现在默认列表，关联单据仍会保留。确认归档？')">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                        <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                        归档
                    </button>
                </form>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'inquiries'])

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <div class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">需求识别结果</h2>
                    <dl class="mt-4 grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                        <div><dt class="text-gray-500">客户</dt><dd class="mt-1 font-medium text-gray-900">{{ $inquiry->customer?->contact_person ?: $inquiry->customer?->company_name ?? '未关联' }}</dd></div>
                        <div><dt class="text-gray-500">负责人</dt><dd class="mt-1 font-medium text-gray-900">{{ $inquiry->assigned_to ?: '未指定' }}</dd></div>
                        <div><dt class="text-gray-500">语言</dt><dd class="mt-1 font-medium text-gray-900">{{ $inquiry->detected_language ?: '未识别' }}</dd></div>
                    </dl>
                    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">需求摘要</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $inquiry->customer_need_summary ?: '暂无摘要' }}</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">产品兴趣</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $inquiry->product_interest ?: '暂无' }}</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">建议回复要点</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $inquiry->suggested_reply_points ?: '暂无' }}</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">需补充问题</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $inquiry->missing_information_questions ?: '暂无' }}</div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">原始询盘内容</h2>
                    <div class="mt-4 whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-700">{{ $inquiry->raw_message ?: '暂无原文' }}</div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">关联商机</h2>
                            <p class="mt-1 text-sm text-gray-500">商机用于推进成交阶段、金额、下一步和单据制作。</p>
                        </div>
                        @if ($inquiry->opportunities->isEmpty())
                            <form method="POST" action="{{ route('admin.crm.opportunities.from-inquiry', ['inquiryId' => (int) $inquiry->id]) }}">
                                @csrf
                                <button type="submit" class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                                    创建商机
                                </button>
                            </form>
                        @endif
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse ($inquiry->opportunities as $opportunity)
                            <a href="{{ route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunity->id]) }}" class="flex flex-col gap-2 rounded-md border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm hover:bg-emerald-100 sm:flex-row sm:items-center sm:justify-between">
                                <span>
                                    <span class="font-semibold text-emerald-950">{{ $opportunity->name }}</span>
                                    <span class="ml-2 rounded-full bg-white px-2 py-0.5 text-xs font-medium text-emerald-700">{{ $opportunityStageLabels[$opportunity->stage] ?? $opportunity->stage }}</span>
                                </span>
                                <span class="text-emerald-800">{{ $opportunity->currency ?: 'USD' }} {{ number_format((float) $opportunity->amount, 2) }} · {{ (int) $opportunity->probability }}%</span>
                            </a>
                        @empty
                            <div class="rounded-md border border-dashed border-gray-300 px-4 py-5 text-sm text-gray-500">暂无关联商机。确认有真实采购可能后，再将询盘转为商机。</div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">报价记录</h2>
                    <div class="mt-4 space-y-2">
                        @forelse ($inquiry->quotes as $quote)
                            <a href="{{ route('admin.crm.quotes.show', ['quoteId' => (int) $quote->id]) }}" class="flex items-center justify-between rounded-md border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50">
                                <span class="font-medium text-gray-900">{{ $quote->quote_no }} · {{ $quote->title }}</span>
                                <span class="text-gray-500">{{ $quote->currency }} {{ number_format((float) $quote->total_amount, 2) }}</span>
                            </a>
                        @empty
                            <div class="text-sm text-gray-500">暂无报价</div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">订单记录</h2>
                    <div class="mt-4 space-y-2">
                        @forelse ($inquiry->salesOrders as $order)
                            <a href="{{ route('admin.crm.orders.show', ['orderId' => (int) $order->id]) }}" class="flex items-center justify-between rounded-md border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50">
                                <span class="font-medium text-gray-900">{{ $order->order_no }} · {{ $order->title }}</span>
                                <span class="text-gray-500">{{ $order->currency }} {{ number_format((float) $order->total_amount, 2) }}</span>
                            </a>
                        @empty
                            <div class="text-sm text-gray-500">暂无订单</div>
                        @endforelse
                    </div>
                </section>
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="message-square-text" class="mr-2 inline-block h-4 w-4 text-gray-500"></i>活动记录</h2>
                    <p class="mt-1 text-sm text-gray-500">记录已经发生的电话、邮件、会议或沟通结果。</p>
                    <form method="POST" action="{{ route('admin.crm.inquiries.follow-ups.store', ['inquiryId' => (int) $inquiry->id]) }}" class="mt-4 space-y-3">
                        @csrf
                        @include('admin.crm.partials._markdown-editor', ['fieldName' => 'content', 'rows' => 4, 'placeholder' => '跟进内容（支持 Markdown）'])
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <input type="text" name="followup_type" placeholder="活动类型：电话 / 邮件 / 会议" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        </div>
                        <input type="hidden" name="owner" value="{{ $inquiry->assigned_to ?? '' }}">
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">记录活动</button>
                    </form>
                    <div class="mt-5 space-y-3">
                        @forelse ($inquiry->followUps as $followUp)
                            @include('admin.crm.partials._follow-up-item', ['followUp' => $followUp, 'showInquiryLink' => false])
                        @empty
                            <div class="text-sm text-gray-500">暂无跟进记录</div>
                        @endforelse
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200"><h2 class="text-base font-semibold text-gray-900">下一步待办</h2><p class="mt-1 text-sm text-gray-500">需要在未来完成的动作单独设定截止时间。</p><div class="mt-4">@include('admin.crm.partials.task-form',['customer_id'=>$inquiry->customer_id,'inquiry_id'=>$inquiry->id])</div><div class="mt-5 divide-y divide-gray-100 border-t border-gray-100">@forelse($inquiry->crmTasks as $task)@include('admin.crm.partials.task-row',['task'=>$task])@empty<div class="py-5 text-sm text-gray-500">暂无待办</div>@endforelse</div></section>
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">推荐引用资料</h2>
                    <div class="mt-4 space-y-5">
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">Entity</h3>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @forelse ($inquiry->entities as $entity)
                                    <a href="{{ route('admin.entities.edit', ['entityId' => (int) $entity->id]) }}" class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">{{ $entity->name }}</a>
                                @empty
                                    <span class="text-sm text-gray-500">暂无关联</span>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">知识库</h3>
                            <div class="mt-2 space-y-2">
                                @forelse ($inquiry->knowledgeBases as $knowledgeBase)
                                    <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}" class="block rounded-md border border-orange-100 bg-orange-50 px-3 py-2 text-sm text-orange-800 hover:bg-orange-100">{{ $knowledgeBase->name }}</a>
                                @empty
                                    <span class="text-sm text-gray-500">暂无关联</span>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">Case</h3>
                            <div class="mt-2 space-y-2">
                                @forelse ($inquiry->cases as $caseRecord)
                                    <a href="{{ route('admin.cases.edit', ['caseId' => (int) $caseRecord->id]) }}" class="block rounded-md border border-emerald-100 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 hover:bg-emerald-100">{{ $caseRecord->title }}</a>
                                @empty
                                    <span class="text-sm text-gray-500">暂无关联</span>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">标签与备注</h2>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($inquiry->tags as $tag)
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">{{ $tag->displayName() }}</span>
                        @empty
                            <span class="text-sm text-gray-500">暂无标签</span>
                        @endforelse
                    </div>
                    @if ((string) ($inquiry->notes ?? '') !== '')
                        <div class="mt-4 whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-700">{{ $inquiry->notes }}</div>
                    @endif
                </section>


            </aside>
        </div>
    </div>
@endsection
