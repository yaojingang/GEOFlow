@extends('admin.layouts.app')

@php
    $isEdit = (bool) $opportunity;
    $formAction = $isEdit
        ? route('admin.crm.opportunities.update', ['opportunityId' => (int) $opportunity->id])
        : route('admin.crm.opportunities.store');
    $inputClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500';
    $textareaClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500';
    $sourcePrimaryContactId = $sourceInquiry?->customer?->contacts?->firstWhere('is_primary', true)?->id
        ?: $sourceInquiry?->customer?->contacts?->first()?->id;
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? '编辑商机' : '新增商机' }}</h1>
                <p class="mt-1 text-sm text-gray-500">商机只记录已经确认存在采购可能的机会，用于推进阶段、报价和成交。</p>
            </div>
            <a href="{{ route('admin.crm.opportunities.index') }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                <i data-lucide="kanban-square" class="mr-2 h-4 w-4"></i>
                返回管道
            </a>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'opportunities'])

        @if ($errors->any())
            <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
            <form method="POST" action="{{ $formAction }}" class="space-y-6">
                @csrf
                @if($isEdit)
                    @method('PUT')
                @endif

                <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="mb-5 border-b border-gray-100 pb-4">
                        <h2 class="text-base font-semibold text-gray-900">商机信息</h2>
                        <p class="mt-1 text-sm text-gray-500">金额和概率可先粗略填写，后续推进阶段再更新。</p>
                    </div>
                    <div class="grid gap-5 md:grid-cols-2">
                        <label class="md:col-span-2">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">商机名称 <span class="text-red-500">*</span></span>
                            <input name="name" required maxlength="200" value="{{ old('name', $opportunity?->name ?: $sourceInquiry?->subject) }}" class="{{ $inputClass }}">
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">客户 <span class="text-red-500">*</span></span>
                            <select name="customer_id" required class="{{ $inputClass }}">
                                @foreach($customers as $customer)
                                    <option value="{{ (int) $customer->id }}" @selected((int) old('customer_id', $selectedCustomerId) === (int) $customer->id)>
                                        {{ $customer->company_name ?: $customer->contact_person }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">业务容器</span>
                            <select name="collection_id" class="{{ $inputClass }}">
                                <option value="">未指定</option>
                                @foreach($collectionOptions as $option)
                                    <option value="{{ (int) $option['id'] }}" @selected((int) old('collection_id', $opportunity?->collection_id ?: $sourceInquiry?->collection_id) === (int) $option['id'])>{{ $option['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">主要联系人</span>
                            <select name="primary_contact_id" class="{{ $inputClass }}">
                                <option value="">未指定</option>
                                @foreach($customers as $customer)
                                    @foreach($customer->contacts as $contact)
                                        <option value="{{ (int) $contact->id }}" @selected((int) old('primary_contact_id', $opportunity?->primary_contact_id ?: $sourcePrimaryContactId) === (int) $contact->id)>
                                            {{ $customer->company_name }} · {{ $contact->name }}{{ $contact->title ? ' · '.$contact->title : '' }}
                                        </option>
                                    @endforeach
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">负责人</span>
                            <select name="owner_admin_id" class="{{ $inputClass }}">
                                <option value="">未指定</option>
                                @foreach($employeeOptions as $id => $label)
                                    <option value="{{ (int) $id }}" @selected((int) old('owner_admin_id', $opportunity?->owner_admin_id) === (int) $id)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">阶段</span>
                            <select name="stage" class="{{ $inputClass }}">
                                @foreach($stages as $key => $label)
                                    <option value="{{ $key }}" @selected(old('stage', $opportunity?->stage ?: 'qualified') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">金额</span>
                            <div class="flex">
                                <select name="currency" class="w-24 rounded-l-md border border-r-0 border-gray-300 px-2 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                    @foreach(['USD', 'EUR', 'CNY'] as $currency)
                                        <option value="{{ $currency }}" @selected(old('currency', $opportunity?->currency ?: 'USD') === $currency)>{{ $currency }}</option>
                                    @endforeach
                                </select>
                                <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount', $opportunity?->amount ?: 0) }}" class="min-w-0 flex-1 rounded-r-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                            </div>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">成交概率 %</span>
                            <input type="number" min="0" max="100" name="probability" value="{{ old('probability', $opportunity?->probability ?: 20) }}" class="{{ $inputClass }}">
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">预计成交日期</span>
                            <input type="date" name="expected_close_date" value="{{ old('expected_close_date', $opportunity?->expected_close_date?->format('Y-m-d')) }}" class="{{ $inputClass }}">
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">下一步时间</span>
                            <input type="datetime-local" name="next_step_at" value="{{ old('next_step_at', $opportunity?->next_step_at?->format('Y-m-d\TH:i')) }}" class="{{ $inputClass }}">
                        </label>
                        <label class="md:col-span-2">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">下一步动作</span>
                            <input name="next_step" maxlength="500" value="{{ old('next_step', $opportunity?->next_step) }}" class="{{ $inputClass }}" placeholder="例如：确认预算、发配置方案、安排样机测试">
                        </label>
                        <label class="md:col-span-2">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">竞争对手</span>
                            <input name="competitor" maxlength="200" value="{{ old('competitor', $opportunity?->competitor) }}" class="{{ $inputClass }}">
                        </label>
                        <label class="md:col-span-2">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">输单原因（阶段为输单时必填）</span>
                            <textarea name="lost_reason" rows="3" class="{{ $textareaClass }}">{{ old('lost_reason', $opportunity?->lost_reason) }}</textarea>
                        </label>
                        <label class="md:col-span-2">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">内部备注</span>
                            <textarea name="notes" rows="5" class="{{ $textareaClass }}">{{ old('notes', $opportunity?->notes) }}</textarea>
                        </label>
                    </div>
                    <input type="hidden" name="source_inquiry_id" value="{{ old('source_inquiry_id', $opportunity?->source_inquiry_id ?: $sourceInquiry?->id) }}">
                    <div class="mt-6 flex justify-end">
                        <button type="submit" class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                            保存商机
                        </button>
                    </div>
                </section>
            </form>

            <aside class="space-y-6">
                @if($sourceInquiry)
                    <section class="rounded-lg border border-emerald-100 bg-emerald-50 p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="font-semibold text-emerald-950">来源询盘</h2>
                                <p class="mt-1 text-sm text-emerald-800">{{ $sourceInquiry->subject }}</p>
                            </div>
                            <a href="{{ route('admin.crm.inquiries.show', ['inquiryId' => (int) $sourceInquiry->id]) }}" class="shrink-0 text-sm font-medium text-emerald-700 hover:text-emerald-900">查看</a>
                        </div>
                        <dl class="mt-4 grid grid-cols-3 gap-2 text-xs">
                            <div class="rounded-md bg-white px-3 py-2">
                                <dt class="text-emerald-700">Entity</dt>
                                <dd class="mt-1 text-lg font-semibold text-emerald-950">{{ $sourceInquiry->entities->count() }}</dd>
                            </div>
                            <div class="rounded-md bg-white px-3 py-2">
                                <dt class="text-emerald-700">知识库</dt>
                                <dd class="mt-1 text-lg font-semibold text-emerald-950">{{ $sourceInquiry->knowledgeBases->count() }}</dd>
                            </div>
                            <div class="rounded-md bg-white px-3 py-2">
                                <dt class="text-emerald-700">Case</dt>
                                <dd class="mt-1 text-lg font-semibold text-emerald-950">{{ $sourceInquiry->cases->count() }}</dd>
                            </div>
                        </dl>
                        @if((string) ($sourceInquiry->customer_need_summary ?? '') !== '')
                            <div class="mt-4 rounded-md border border-emerald-100 bg-white px-3 py-2">
                                <div class="text-xs font-medium uppercase tracking-wide text-emerald-700">需求摘要</div>
                                <div class="mt-1 whitespace-pre-wrap text-sm leading-6 text-emerald-950">{{ \Illuminate\Support\Str::limit((string) $sourceInquiry->customer_need_summary, 360) }}</div>
                            </div>
                        @endif
                        @if((string) ($sourceInquiry->missing_information_questions ?? '') !== '')
                            <div class="mt-3 rounded-md border border-amber-100 bg-amber-50 px-3 py-2">
                                <div class="text-xs font-medium uppercase tracking-wide text-amber-700">仍需确认</div>
                                <div class="mt-1 whitespace-pre-wrap text-sm leading-6 text-amber-900">{{ \Illuminate\Support\Str::limit((string) $sourceInquiry->missing_information_questions, 260) }}</div>
                            </div>
                        @endif
                    </section>
                @endif

                @if($isEdit)
                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="font-semibold text-gray-900">关联单据</h2>
                            <a href="{{ route('admin.crm.quotes.create', ['opportunity_id' => (int) $opportunity->id]) }}" class="inline-flex items-center rounded-md border border-purple-200 bg-purple-50 px-3 py-1.5 text-xs font-medium text-purple-700 hover:bg-purple-100">
                                <i data-lucide="file-plus-2" class="mr-1.5 h-3.5 w-3.5"></i>
                                新建单据
                            </a>
                        </div>
                        <div class="mt-4 space-y-2">
                            @forelse($opportunity->quotes as $quote)
                                @php($quoteTotal = (float) ($quote->grand_total ?: $quote->total_amount))
                                <a href="{{ route('admin.crm.quotes.show', ['quoteId' => (int) $quote->id]) }}" class="block rounded-md border border-gray-200 px-3 py-2 text-sm hover:bg-gray-50">
                                    <div class="font-medium text-gray-900">{{ $quote->quote_no ?: '未生成单号' }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $quote->title }} · {{ $quote->currency }} {{ number_format($quoteTotal, 2) }}</div>
                                </a>
                            @empty
                                <div class="rounded-md border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500">暂无关联单据</div>
                            @endforelse
                        </div>
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <h2 class="font-semibold text-gray-900">新建待办</h2>
                        <div class="mt-4">
                            @include('admin.crm.partials.task-form', ['customer_id' => $opportunity->customer_id, 'opportunity_id' => $opportunity->id])
                        </div>
                    </section>
                    <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-5 py-4">
                            <h2 class="font-semibold text-gray-900">商机待办</h2>
                        </div>
                        <div class="divide-y divide-gray-100">
                            @forelse($opportunity->tasks as $task)
                                @include('admin.crm.partials.task-row', ['task' => $task])
                            @empty
                                <div class="p-5 text-sm text-gray-500">暂无待办</div>
                            @endforelse
                        </div>
                    </section>
                @else
                    <section class="rounded-lg border border-blue-100 bg-blue-50 p-5 text-sm leading-6 text-blue-800">
                        建议只把已确认存在真实采购可能的询盘转成商机，普通咨询继续留在询盘中即可。
                    </section>
                @endif
            </aside>
        </div>
    </div>
@endsection
