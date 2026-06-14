@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.crm.inquiries.update', ['inquiryId' => (int) $inquiryId])
        : route('admin.crm.inquiries.store');
    $currentSourceChannel = old('source_channel', (string) ($inquiryForm['source_channel'] ?? ''));
    $currentInquiryStatus = old('status', (string) ($inquiryForm['status'] ?? 'new'));
    $activeInquiryStatuses = [
        'new' => '新询盘',
        'analyzing' => '分析中',
        'qualified' => '已确认',
        'converted' => '已转商机',
        'invalid' => '无效',
        'closed' => '已关闭',
    ];
    $legacyInquiryStatuses = [
        'quoted' => '已报价（历史状态）',
        'won' => '赢单（历史状态）',
        'lost' => '丢单（历史状态）',
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.crm.inquiries.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? '编辑询盘' : '新增询盘' }}</h1>
                <p class="mt-1 text-sm text-gray-600">询盘用于把客户需求与现有知识体系关联，AI 只推荐已有 Entity、知识库和 Case。</p>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'inquiries'])

        <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="px-6 py-6">
                @if ($errors->any())
                    <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ $formAction }}" class="space-y-6" data-crm-inquiry-form data-analysis-url="{{ route('admin.crm.inquiries.analyze') }}">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <section class="rounded-lg border border-blue-100 bg-blue-50/60 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <h2 class="text-base font-semibold text-blue-950">AI 需求识别</h2>
                                <p class="mt-1 text-sm text-blue-800">粘贴询盘原文后，系统会提取需求摘要，并推荐当前 Collection 内可引用的 Entity、知识库和 Case。</p>
                            </div>
                            <div class="flex min-w-[280px] flex-col gap-2 sm:flex-row">
                                <select data-crm-analysis-model class="block w-full rounded-md border border-blue-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="0">自动选择模型</option>
                                    @foreach(($aiModelOptions ?? []) as $model)
                                        <option value="{{ (int) $model['id'] }}">{{ $model['name'] }}</option>
                                    @endforeach
                                </select>
                                <button type="button" data-crm-analysis-submit class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                    <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                                    分析
                                </button>
                            </div>
                        </div>
                        <textarea data-crm-analysis-content rows="5" class="mt-4 block w-full rounded-md border border-blue-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="粘贴客户询盘、邮件或聊天记录"></textarea>
                        <p data-crm-analysis-status class="mt-2 hidden text-sm text-blue-800"></p>
                    </section>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        @include('admin.partials.collection-select', [
                            'selectedId' => (string) ($inquiryForm['collection_id'] ?? ''),
                            'collectionOptions' => $collectionOptions ?? [],
                            'label' => '业务容器',
                            'help' => '选择后，Entity、知识库和 Case 选项会限制在同一业务容器中。',
                            'class' => 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500',
                        ])
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">询盘标题 <span class="text-red-500">*</span></label>
                            <input type="text" name="subject" required maxlength="200" value="{{ old('subject', (string) ($inquiryForm['subject'] ?? '')) }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="例如：SJ4060 询价">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">客户</label>
                            <select name="customer_id" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">不关联客户</option>
                                @foreach (($customerOptions ?? []) as $customer)
                                    <option value="{{ (int) $customer['id'] }}" @selected(old('customer_id', (string) ($inquiryForm['customer_id'] ?? '')) === (string) $customer['id'])>{{ $customer['label'] }} @if($customer['meta'] !== '') · {{ $customer['meta'] }} @endif</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">负责人</label>
                            <select name="assigned_to" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">未指定</option>
                                @foreach (($employeeOptions ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected(old('assigned_to', (string) ($inquiryForm['assigned_to'] ?? '')) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">来源渠道</label>
                            <select name="source_channel" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">未指定</option>
                                @if ($currentSourceChannel !== '' && !array_key_exists($currentSourceChannel, $sourceChannelOptions ?? []))
                                    <option value="{{ $currentSourceChannel }}" selected>{{ $currentSourceChannel }}（历史值）</option>
                                @endif
                                @foreach (($sourceChannelOptions ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected($currentSourceChannel === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">语言</label>
                            <input type="text" name="detected_language" maxlength="80" value="{{ old('detected_language', (string) ($inquiryForm['detected_language'] ?? '')) }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="en / zh-CN">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">状态</label>
                            <select name="status" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @if (array_key_exists($currentInquiryStatus, $legacyInquiryStatuses))
                                    <option value="{{ $currentInquiryStatus }}" selected>{{ $legacyInquiryStatuses[$currentInquiryStatus] }}</option>
                                @endif
                                @foreach ($activeInquiryStatuses as $value => $label)
                                    <option value="{{ $value }}" @selected($currentInquiryStatus === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">优先级</label>
                            <select name="priority" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @foreach (['low' => '低', 'normal' => '普通', 'high' => '高', 'urgent' => '紧急'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('priority', (string) ($inquiryForm['priority'] ?? 'normal')) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">原始询盘内容</label>
                        <textarea name="raw_message" rows="7" data-crm-field="raw_message" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('raw_message', (string) ($inquiryForm['raw_message'] ?? '')) }}</textarea>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">需求摘要</label>
                            <textarea name="customer_need_summary" rows="5" data-crm-field="customer_need_summary" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('customer_need_summary', (string) ($inquiryForm['customer_need_summary'] ?? '')) }}</textarea>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">产品兴趣</label>
                            <textarea name="product_interest" rows="5" data-crm-field="product_interest" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('product_interest', (string) ($inquiryForm['product_interest'] ?? '')) }}</textarea>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">建议回复要点</label>
                            <textarea name="suggested_reply_points" rows="5" data-crm-field="suggested_reply_points" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('suggested_reply_points', (string) ($inquiryForm['suggested_reply_points'] ?? '')) }}</textarea>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">需补充问题</label>
                            <textarea name="missing_information_questions" rows="5" data-crm-field="missing_information_questions" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('missing_information_questions', (string) ($inquiryForm['missing_information_questions'] ?? '')) }}</textarea>
                        </div>
                    </div>

                    <section class="rounded-lg border border-slate-200 bg-slate-50/60 p-4">
                        <h2 class="text-base font-semibold text-slate-900">推荐引用资料</h2>
                        <p class="mt-1 text-sm text-slate-600">这些关联不会创建新素材，只用于把询盘和现有知识上下文连接起来。</p>
                        <div class="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-3">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Entity</label>
                                @include('admin.partials.option-multi-selector', [
                                    'name' => 'entity_ids',
                                    'options' => $entityOptions ?? [],
                                    'selectedIds' => $selectedEntityIds ?? [],
                                    'tone' => 'blue',
                                    'placeholder' => '搜索 Entity',
                                    'noneSelectedText' => '暂未关联 Entity',
                                ])
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">知识库</label>
                                @include('admin.partials.option-multi-selector', [
                                    'name' => 'knowledge_base_ids',
                                    'options' => $knowledgeBaseOptions ?? [],
                                    'selectedIds' => $selectedKnowledgeBaseIds ?? [],
                                    'tone' => 'orange',
                                    'placeholder' => '搜索知识库',
                                    'noneSelectedText' => '暂未关联知识库',
                                ])
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Case</label>
                                @include('admin.partials.option-multi-selector', [
                                    'name' => 'case_record_ids',
                                    'options' => $caseOptions ?? [],
                                    'selectedIds' => $selectedCaseRecordIds ?? [],
                                    'tone' => 'green',
                                    'placeholder' => '搜索 Case',
                                    'noneSelectedText' => '暂未关联 Case',
                                ])
                            </div>
                        </div>
                    </section>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">标签</label>
                        @include('admin.partials.tag-selector', ['tagOptions' => $tagOptions ?? [], 'selectedTagIds' => $selectedTagIds ?? [], 'tone' => 'blue'])
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">内部备注</label>
                        <textarea name="notes" rows="4" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('notes', (string) ($inquiryForm['notes'] ?? '')) }}</textarea>
                    </div>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.crm.inquiries.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">取消</a>
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                            保存询盘
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('[data-crm-inquiry-form]');
            if (!form) return;

            const collectionSelect = form.querySelector('select[name="collection_id"]');

            function selectedCollectionId() {
                return String(collectionSelect?.value || '');
            }

            function removeChip(selector, id) {
                selector.querySelector('[data-option-chip][data-option-id="' + CSS.escape(id) + '"]')?.remove();
                selector.dispatchEvent(new CustomEvent('option-selector:changed', {bubbles: true}));
            }

            function filterByCollection() {
                const collectionId = selectedCollectionId();
                form.querySelectorAll('[data-option-multi-selector]').forEach((selector) => {
                    const fieldName = selector.getAttribute('data-field-name') || '';
                    if (!['entity_ids', 'knowledge_base_ids', 'case_record_ids'].includes(fieldName)) return;
                    selector.querySelectorAll('[data-option-item]').forEach((item) => {
                        const optionCollectionId = item.getAttribute('data-option-collection-id') || '';
                        const hidden = collectionId !== '' && optionCollectionId !== collectionId;
                        item.dataset.optionFilterHidden = hidden ? '1' : '0';
                        item.classList.toggle('hidden', hidden);
                        if (hidden && selector.querySelector('[data-option-chip][data-option-id="' + CSS.escape(item.getAttribute('data-option-id') || '') + '"]')) {
                            removeChip(selector, item.getAttribute('data-option-id') || '');
                        }
                    });
                });
            }

            function selectOption(fieldName, id) {
                const selector = form.querySelector('[data-option-multi-selector][data-field-name="' + fieldName + '"]');
                if (!selector || !id) return;
                if (selector.querySelector('[data-option-chip][data-option-id="' + CSS.escape(String(id)) + '"]')) return;
                const item = selector.querySelector('[data-option-item][data-option-id="' + CSS.escape(String(id)) + '"]');
                if (item && item.dataset.optionFilterHidden !== '1') {
                    item.click();
                }
            }

            collectionSelect?.addEventListener('change', filterByCollection);
            filterByCollection();

            form.querySelector('[data-crm-analysis-submit]')?.addEventListener('click', async () => {
                const status = form.querySelector('[data-crm-analysis-status]');
                const source = form.querySelector('[data-crm-analysis-content]');
                const content = (source?.value || form.querySelector('[name="raw_message"]')?.value || '').trim();
                if (!content) {
                    if (status) {
                        status.textContent = '请先粘贴或填写询盘内容。';
                        status.classList.remove('hidden');
                    }
                    return;
                }

                if (status) {
                    status.textContent = '正在分析...';
                    status.classList.remove('hidden');
                }

                try {
                    const response = await fetch(form.dataset.analysisUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                        },
                        body: JSON.stringify({
                            content,
                            collection_id: selectedCollectionId(),
                            ai_model_id: form.querySelector('[data-crm-analysis-model]')?.value || 0,
                        }),
                    });
                    const payload = await response.json();
                    const fields = payload.fields || {};
                    Object.entries({
                        detected_language: 'detected_language',
                        customer_need_summary: 'customer_need_summary',
                        product_interest: 'product_interest',
                        suggested_reply_points: 'suggested_reply_points',
                        missing_information_questions: 'missing_information_questions',
                        urgency_level: 'urgency_level',
                    }).forEach(([key, name]) => {
                        const input = form.querySelector('[name="' + name + '"]');
                        if (input && fields[key] !== undefined) input.value = fields[key] || '';
                    });
                    if (form.querySelector('[name="raw_message"]') && !form.querySelector('[name="raw_message"]').value.trim()) {
                        form.querySelector('[name="raw_message"]').value = content;
                    }
                    (fields.entity_ids || []).forEach((id) => selectOption('entity_ids', id));
                    (fields.knowledge_base_ids || []).forEach((id) => selectOption('knowledge_base_ids', id));
                    (fields.case_record_ids || []).forEach((id) => selectOption('case_record_ids', id));
                    if (status) status.textContent = '分析完成，可继续人工调整。';
                } catch (error) {
                    if (status) status.textContent = '分析失败，请检查模型配置或稍后重试。';
                }
            });
        })();
    </script>
@endpush
