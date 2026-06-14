@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.crm.quotes.update', ['quoteId' => (int) $quoteId])
        : route('admin.crm.quotes.store');
    $emptyRow = [
        'entity_id' => '',
        'line_type' => 'product',
        'model' => '',
        'hs_code' => '',
        'image_id' => '',
        'image_path' => '',
        'image_original_name' => '',
        'item_name' => '',
        'description' => '',
        'quantity' => '1',
        'unit' => '',
        'unit_price' => '0',
        'package_count' => '0',
        'net_weight' => '0',
        'gross_weight' => '0',
        'volume_cbm' => '0',
    ];
    $oldItemNames = old('items.item_name');
    if (is_array($oldItemNames)) {
        $rows = [];
        foreach (array_keys($oldItemNames) as $rowIndex) {
            $row = $emptyRow;
            foreach (array_keys($emptyRow) as $field) {
                $row[$field] = (string) old("items.$field.$rowIndex", $emptyRow[$field]);
            }
            $rows[] = $row;
        }
    } else {
        $rows = collect($quoteItems ?? [])->map(static fn (array $row): array => array_replace($emptyRow, $row))->values()->all();
    }
    if ($rows === []) {
        $rows = [$emptyRow];
    }
    $inputClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500';
    $compactInputClass = 'block w-full rounded-md border border-gray-300 px-2 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500';
    $textareaClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500';
    $sectionClass = 'rounded-lg border border-gray-200 bg-white p-5 shadow-sm mt-6';
    $bankAccountJsonValue = old('bank_account_json', (string) ($quoteForm['bank_account_json'] ?? '')) ?: (string) ($defaultBankAccountJson ?? '{}');
    $sellerCompanyJsonValue = old('seller_company_json', (string) ($quoteForm['seller_company_json'] ?? '')) ?: (string) ($defaultSellerCompanyJson ?? '{}');
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.crm.quotes.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? '编辑单据' : '新增单据' }}</h1>
                <p class="mt-1 text-sm text-gray-600">用于维护报价单、形式发票、正式发票、装箱单和合同草稿。</p>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'quotes'])

        <form method="POST" action="{{ $formAction }}" enctype="multipart/form-data" class="space-y-6" data-crm-quote-form>
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
            @endif

                        <section class="{{ $sectionClass }}">
                <div class="-mx-5 -mt-5 mb-4 rounded-t-lg border-b border-blue-200 bg-blue-50 px-5 py-3">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="info" class="mr-2 h-4 w-4 inline-block text-blue-600"></i>基础信息</h2>
                    <p class="mt-0.5 text-sm text-gray-500">业务容器会限制客户、询盘、Entity 和图库候选范围。</p>
                </div>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">标题 <span class="text-red-500">*</span></label>
                        <input type="text" name="title" required maxlength="200" value="{{ old('title', (string) ($quoteForm['title'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">单据号</label>
                        <input type="text" name="quote_no" maxlength="80" value="{{ old('quote_no', (string) ($quoteForm['quote_no'] ?? '')) }}" class="{{ $inputClass }}" placeholder="留空自动生成">
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-12 mt-4">
                    <div class="lg:col-span-4">
                        @include('admin.partials.collection-select', [
                            'selectedId' => (string) old('collection_id', (string) ($quoteForm['collection_id'] ?? '')),
                            'collectionOptions' => $collectionOptions ?? [],
                            'label' => '业务容器',
                            'help' => '',
                            'emptyLabel' => '未指定 Collection 时不会限制候选素材',
                            'class' => $inputClass,
                        ])
                    </div>
                    <div class="lg:col-span-4">
                        <label class="mb-2 block text-sm font-medium text-gray-700">客户 <span class="text-red-500">*</span></label>
                        <select name="customer_id" required class="{{ $inputClass }}">
                            <option value="">请选择客户</option>
                            @foreach (($customerOptions ?? []) as $customer)
                                <option value="{{ (int) $customer['id'] }}" @selected(old('customer_id', (string) ($quoteForm['customer_id'] ?? '')) === (string) $customer['id'])>
                                    {{ $customer['label'] }} @if (($customer['meta'] ?? '') !== '') · {{ $customer['meta'] }} @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-4">
                        <label class="mb-2 block text-sm font-medium text-gray-700">负责人</label>
                        <select name="owner" class="{{ $inputClass }}">
                            <option value="">未指定</option>
                            @foreach (($employeeOptions ?? []) as $value => $label)
                                <option value="{{ $value }}" @selected(old('owner', (string) ($quoteForm['owner'] ?? '')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-12 mt-4">
                    <div class="lg:col-span-3">
                        <label class="mb-2 block text-sm font-medium text-gray-700">关联询盘</label>
                        <select name="inquiry_id" class="{{ $inputClass }}">
                            <option value="">不关联询盘</option>
                            @foreach (($inquiryOptions ?? []) as $inquiry)
                                <option value="{{ (int) $inquiry['id'] }}" @selected(old('inquiry_id', (string) ($quoteForm['inquiry_id'] ?? '')) === (string) $inquiry['id'])>
                                    {{ $inquiry['label'] }} @if (($inquiry['meta'] ?? '') !== '') · {{ $inquiry['meta'] }} @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-3">
                        <label class="mb-2 block text-sm font-medium text-gray-700">关联商机</label>
                        <select name="opportunity_id" class="{{ $inputClass }}">
                            <option value="">不关联商机</option>
                            @foreach (($opportunityOptions ?? []) as $opportunity)
                                <option value="{{ (int) $opportunity['id'] }}" @selected(old('opportunity_id', (string) ($quoteForm['opportunity_id'] ?? '')) === (string) $opportunity['id'])>
                                    {{ $opportunity['label'] }} @if (($opportunity['meta'] ?? '') !== '') · {{ $opportunity['meta'] }} @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">单据类型</label>
                        <select name="document_type" class="{{ $inputClass }}">
                            @foreach (($documentTypeOptions ?? []) as $value => $label)
                                <option value="{{ $value }}" @selected(old('document_type', (string) ($quoteForm['document_type'] ?? 'quotation')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">语言</label>
                        <select name="document_language" class="{{ $inputClass }}">
                            @foreach (($languageOptions ?? []) as $value => $label)
                                <option value="{{ $value }}" @selected(old('document_language', (string) ($quoteForm['document_language'] ?? 'en')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">状态</label>
                        <select name="status" class="{{ $inputClass }}">
                            @foreach (['draft' => '草稿', 'sent' => '已发送', 'accepted' => '已接受', 'rejected' => '已拒绝', 'expired' => '已过期'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', (string) ($quoteForm['status'] ?? 'draft')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </section>


            <section class="{{ $sectionClass }}">
                <div class="-mx-5 -mt-5 mb-4 flex flex-col gap-3 rounded-t-lg border-b border-blue-200 bg-blue-50 px-5 py-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900"><i data-lucide="users" class="mr-2 h-4 w-4 inline-block text-blue-600"></i>买方信息</h2>
                        <p class="mt-0.5 text-sm text-gray-500">买方名称、电话和国家留空时，会用客户资料作为保存兜底。</p>
                    </div>
                    <button type="button" class="inline-flex h-9 items-center rounded-md border border-blue-200 bg-blue-50 px-3 text-sm font-medium text-blue-700 hover:bg-blue-100" data-fill-buyer-from-customer>
                        <i data-lucide="user-round-check" class="mr-2 h-4 w-4"></i>
                        从客户资料带入
                    </button>
                </div>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-12">
                    <div class="lg:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">联系人</label>
                        <input type="text" name="buyer_contact" maxlength="200" value="{{ old('buyer_contact', (string) ($quoteForm['buyer_contact'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                    <div class="lg:col-span-3">
                        <label class="mb-2 block text-sm font-medium text-gray-700">公司名</label>
                        <input type="text" name="buyer_company" maxlength="200" value="{{ old('buyer_company', (string) ($quoteForm['buyer_company'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">电话</label>
                        <input type="text" name="buyer_phone" maxlength="120" value="{{ old('buyer_phone', (string) ($quoteForm['buyer_phone'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                    <div class="lg:col-span-3">
                        <label class="mb-2 block text-sm font-medium text-gray-700">邮箱</label>
                        <input type="email" name="buyer_email" maxlength="200" value="{{ old('buyer_email', (string) ($quoteForm['buyer_email'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">国家</label>
                        <input type="text" name="buyer_country" maxlength="100" value="{{ old('buyer_country', (string) ($quoteForm['buyer_country'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                </div>
                <div class="mt-5">
                    <label class="mb-2 block text-sm font-medium text-gray-700">地址</label>
                    <textarea name="buyer_address" rows="3" class="{{ $textareaClass }}">{{ old('buyer_address', (string) ($quoteForm['buyer_address'] ?? '')) }}</textarea>
                </div>
            </section>

            <section class="{{ $sectionClass }}">
                <div class="-mx-5 -mt-5 mb-4 rounded-t-lg border-b border-blue-200 bg-blue-50 px-5 py-3">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="building-2" class="mr-2 h-4 w-4 inline-block text-blue-600"></i>卖方信息</h2>
                    <p class="mt-0.5 text-sm text-gray-500">银行账户与卖方公司分开维护。可直接编辑 JSON，也可保存为常用模板后复用到其他单据。</p>
                </div>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4" data-seller-json-panel data-profile-type="bank_account">
                        <div class="mb-3 flex flex-col gap-3 xl:flex-row xl:items-end">
                            <div class="min-w-0 flex-1">
                                <label class="mb-2 block text-sm font-medium text-gray-700">常用银行账户</label>
                                <select class="{{ $inputClass }} mt-0" data-profile-select>
                                    <option value="">选择常用 Bank Account</option>
                                    @foreach (($bankAccountProfileOptions ?? []) as $profile)
                                        <option value="{{ (int) $profile['id'] }}" @selected((bool) ($profile['is_default'] ?? false))>
                                            {{ $profile['name'] }}@if((bool) ($profile['is_default'] ?? false)) · 默认@endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50" data-profile-import>
                                    导入常用
                                </button>
                                <button type="button" class="inline-flex h-9 items-center rounded-md border border-blue-200 bg-blue-50 px-3 text-sm font-medium text-blue-700 hover:bg-blue-100" data-profile-save>
                                    保存常用
                                </button>
                            </div>
                        </div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Bank Account JSON</label>
                        <textarea name="bank_account_json" rows="8" class="{{ $textareaClass }} font-mono text-xs" data-json-template-field>{{ $bankAccountJsonValue }}</textarea>
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" data-profile-default>
                                保存时设为默认
                            </label>
                            <button type="button" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50" data-json-format>
                                格式化 JSON
                            </button>
                        </div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4" data-seller-json-panel data-profile-type="seller_company">
                        <div class="mb-3 flex flex-col gap-3 xl:flex-row xl:items-end">
                            <div class="min-w-0 flex-1">
                                <label class="mb-2 block text-sm font-medium text-gray-700">常用卖方公司</label>
                                <select class="{{ $inputClass }} mt-0" data-profile-select>
                                    <option value="">选择常用 Seller Company</option>
                                    @foreach (($sellerCompanyProfileOptions ?? []) as $profile)
                                        <option value="{{ (int) $profile['id'] }}" @selected((bool) ($profile['is_default'] ?? false))>
                                            {{ $profile['name'] }}@if((bool) ($profile['is_default'] ?? false)) · 默认@endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50" data-profile-import>
                                    导入常用
                                </button>
                                <button type="button" class="inline-flex h-9 items-center rounded-md border border-blue-200 bg-blue-50 px-3 text-sm font-medium text-blue-700 hover:bg-blue-100" data-profile-save>
                                    保存常用
                                </button>
                            </div>
                        </div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Seller Company JSON</label>
                        <textarea name="seller_company_json" rows="8" class="{{ $textareaClass }} font-mono text-xs" data-json-template-field>{{ $sellerCompanyJsonValue }}</textarea>
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" data-profile-default>
                                保存时设为默认
                            </label>
                            <button type="button" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50" data-json-format>
                                格式化 JSON
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="{{ $sectionClass }}">
                <div class="-mx-5 -mt-5 mb-4 rounded-t-lg border-b border-blue-200 bg-blue-50 px-5 py-3">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="ship" class="mr-2 h-4 w-4 inline-block text-blue-600"></i>物流与贸易信息</h2>
                    <p class="mt-0.5 text-sm text-gray-500">运费、港口、唛头等物流与贸易基础参数，会进入对外单据模板。</p>
                </div>
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-5">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">币种</label>
                        <input type="text" name="currency" maxlength="10" value="{{ old('currency', (string) ($quoteForm['currency'] ?? 'USD')) }}" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Trade Term</label>
                        <input type="text" name="trade_term" maxlength="80" value="{{ old('trade_term', (string) ($quoteForm['trade_term'] ?? 'EXW')) }}" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Origin</label>
                        <input type="text" name="origin_country" maxlength="100" value="{{ old('origin_country', (string) ($quoteForm['origin_country'] ?? 'China')) }}" class="{{ $inputClass }}">
                    </div>
                                        <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">装运港 (Port of Loading)</label>
                        <input type="text" name="port_of_loading" maxlength="200" value="{{ old('port_of_loading', (string) ($quoteForm['port_of_loading'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">目的港 (Port of Destination)</label>
                        <input type="text" name="port_of_destination" maxlength="200" value="{{ old('port_of_destination', (string) ($quoteForm['port_of_destination'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">运输方式 (Transport Mode)</label>
                        <input type="text" name="transport_mode" maxlength="100" value="{{ old('transport_mode', (string) ($quoteForm['transport_mode'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">唛头 (Shipping Mark)</label>
                        <textarea name="shipping_mark" rows="2" maxlength="500" class="{{ $textareaClass }}">{{ old('shipping_mark', (string) ($quoteForm['shipping_mark'] ?? '')) }}</textarea>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">有效期</label>     <input type="date" name="valid_until" value="{{ old('valid_until', (string) ($quoteForm['valid_until'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">交期</label>
                        <input type="text" name="lead_time" maxlength="120" value="{{ old('lead_time', (string) ($quoteForm['lead_time'] ?? '14 days')) }}" class="{{ $inputClass }}">
                    </div>
                </div>
            </section>

            <section class="{{ $sectionClass }}">
                <div class="-mx-5 -mt-5 mb-4 rounded-t-lg border-b border-blue-200 bg-blue-50 px-5 py-3">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="file-text" class="mr-2 h-4 w-4 inline-block text-blue-600"></i>条款和条件</h2>
                    <p class="mt-0.5 text-sm text-gray-500">付款、质保、安装、包装及交付条款，会进入对外单据模板。</p>
                </div>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">预付款比例 (%)</label>
                        <input type="number" name="deposit_percent" min="0" max="100" value="{{ old('deposit_percent', (int) ($quoteForm['deposit_percent'] ?? 60)) }}" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">包装条款 (Packing)</label>
                        <input type="text" name="packing_terms" maxlength="500" value="{{ old('packing_terms', (string) ($quoteForm['packing_terms'] ?? '')) }}" class="{{ $inputClass }}" placeholder="Standard export wooden case">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Payment Terms</label>
                        <textarea name="payment_terms" rows="4" class="{{ $textareaClass }}">{{ old('payment_terms', (string) ($quoteForm['payment_terms'] ?? '')) }}</textarea>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Delivery Terms</label>
                        <textarea name="delivery_terms" rows="4" class="{{ $textareaClass }}">{{ old('delivery_terms', (string) ($quoteForm['delivery_terms'] ?? '')) }}</textarea>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">质保条款</label>
                        <textarea name="warranty_terms" rows="3" class="{{ $textareaClass }}">{{ old('warranty_terms', (string) ($quoteForm['warranty_terms'] ?? '1 year warranty against manufacturing defects.')) }}</textarea>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">安装条款</label>
                        <textarea name="installation_terms" rows="3" class="{{ $textareaClass }}">{{ old('installation_terms', (string) ($quoteForm['installation_terms'] ?? 'Remote guidance included; on-site available at extra cost')) }}</textarea>
                    </div>
                </div>
            </section>

            <section class="{{ $sectionClass }}">
                <div class="-mx-5 -mt-5 mb-4 rounded-t-lg border-b border-blue-200 bg-blue-50 px-5 py-3">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="list" class="mr-2 h-4 w-4 inline-block text-blue-600"></i>明细区域</h2>
                    <p class="mt-0.5 text-sm text-gray-500">支持动态增删行。图片可选择图库，也可上传 200KB 以内的本地图片。</p>
                    <p class="mt-0.5 text-xs text-blue-600" data-logistics-hint>报价单和形式发票阶段无需填写物流字段（HS Code、尺寸、重量等），选择发票/装箱单/合同后自动显示。</p>
                </div>
                <div class="space-y-4" data-crm-quote-items>
                    @foreach ($rows as $index => $row)
                        @include('admin.crm.quotes.partials.item-row', [
                            'row' => $row,
                            'index' => $index,
                            'entityOptions' => $entityOptions ?? [],
                            'imageOptions' => $imageOptions ?? [],
                            'lineTypeOptions' => $lineTypeOptions ?? [],
                            'inputClass' => $inputClass,
                            'compactInputClass' => $compactInputClass,
                            'textareaClass' => $textareaClass,
                        ])
                    @endforeach
                </div>
                <div class="flex justify-end mt-5">
                    <button type="button" class="inline-flex h-9 items-center rounded-md border border-blue-200 bg-blue-50 px-3 text-sm font-medium text-blue-700 hover:bg-blue-100" data-add-quote-item>
                        <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                        添加明细
                    </button>
                </div>
                <template data-crm-quote-row-template>
                    @include('admin.crm.quotes.partials.item-row', [
                        'row' => $emptyRow,
                        'index' => 'template',
                        'entityOptions' => $entityOptions ?? [],
                        'imageOptions' => $imageOptions ?? [],
                        'lineTypeOptions' => $lineTypeOptions ?? [],
                        'inputClass' => $inputClass,
                        'compactInputClass' => $compactInputClass,
                        'textareaClass' => $textareaClass,
                    ])
                </template>
            </section>

            <section class="{{ $sectionClass }}">
                <div class="-mx-5 -mt-5 mb-4 rounded-t-lg border-b border-blue-200 bg-blue-50 px-5 py-3">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="calculator" class="mr-2 h-4 w-4 inline-block text-blue-600"></i>汇总区域</h2>
                    <p class="mt-0.5 text-sm text-gray-500">前端仅做辅助预览，最终金额以后端保存计算为准。</p>
                </div>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-5">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Items Subtotal</label>
                        <div class="flex h-[38px] items-center rounded-md border border-gray-200 bg-gray-50 px-3 text-sm font-semibold text-gray-900" data-items-subtotal>0.00</div>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Shipping Fee</label>
                        <input type="number" step="0.01" min="0" name="shipping_fee" value="{{ old('shipping_fee', (string) ($quoteForm['shipping_fee'] ?? '0')) }}" class="{{ $inputClass }}" data-summary-input>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Discount</label>
                        <input type="number" step="0.01" min="0" name="discount_amount" value="{{ old('discount_amount', (string) ($quoteForm['discount_amount'] ?? '0')) }}" class="{{ $inputClass }}" data-summary-input>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Tax</label>
                        <input type="number" step="0.01" min="0" name="tax_amount" value="{{ old('tax_amount', (string) ($quoteForm['tax_amount'] ?? '0')) }}" class="{{ $inputClass }}" data-summary-input>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Grand Total</label>
                        <div class="flex h-[38px] items-center rounded-md border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-900" data-grand-total>0.00</div>
                    </div>
                </div>
            </section>

            <section class="{{ $sectionClass }}">
                <div class="-mx-5 -mt-5 mb-4 rounded-t-lg border-b border-blue-200 bg-blue-50 px-5 py-3">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="scroll-text" class="mr-2 h-4 w-4 inline-block text-blue-600"></i>合同自定义条款</h2>
                    <p class="mt-0.5 text-sm text-gray-500">合同模板只作为商业草稿，条款应由人工审核后再对外发送。</p>
                </div>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">Customer-specific Contract Terms</label>
                        <textarea name="contract_terms" rows="6" class="{{ $textareaClass }}">{{ old('contract_terms', (string) ($quoteForm['contract_terms'] ?? '')) }}</textarea>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Governing Law</label>
                        <input type="text" name="governing_law" maxlength="160" value="{{ old('governing_law', (string) ($quoteForm['governing_law'] ?? '')) }}" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Dispute Resolution</label>
                        <textarea name="dispute_resolution" rows="3" class="{{ $textareaClass }}">{{ old('dispute_resolution', (string) ($quoteForm['dispute_resolution'] ?? '')) }}</textarea>
                    </div>
                </div>
            </section>

            <section class="{{ $sectionClass }}">
                <div class="-mx-5 -mt-5 mb-4 rounded-t-lg border-b border-blue-200 bg-blue-50 px-5 py-3">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="sticky-note" class="mr-2 h-4 w-4 inline-block text-blue-600"></i>备注信息</h2>
                    <p class="mt-0.5 text-sm text-gray-500">内部备注和签名备注不会输出到对外打印模板。</p>
                </div>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Customer Notes</label>
                        <textarea name="notes" rows="4" class="{{ $textareaClass }}">{{ old('notes', (string) ($quoteForm['notes'] ?? '')) }}</textarea>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Internal Notes</label>
                        <textarea name="internal_notes" rows="4" class="{{ $textareaClass }}">{{ old('internal_notes', (string) ($quoteForm['internal_notes'] ?? '')) }}</textarea>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Signature Notes</label>
                        <textarea name="signature_notes" rows="3" class="{{ $textareaClass }}">{{ old('signature_notes', (string) ($quoteForm['signature_notes'] ?? '')) }}</textarea>
                    </div>
                </div>
            </section>


            <div class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm">
                <div class="flex justify-end gap-3">
                    <a href="{{ route('admin.crm.quotes.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">取消</a>
                    <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                        保存单据
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-crm-quote-form]');
            // Toggle logistics and HS Code fields based on document type
            const toggleFieldsByDocType = () => {
                const docType = form.querySelector('[name="document_type"]')?.value || 'quotation';
                
                // Logistics fields (package_count, net_weight, gross_weight, volume_cbm):
                // Hide for quotation, proforma_invoice, contract — only show for invoice, packing_list
                const hideLogisticsFor = ['quotation', 'proforma_invoice', 'contract'];
                const hideLogistics = hideLogisticsFor.includes(docType);
                form.querySelectorAll('[data-logistics-field]').forEach((el) => {
                    el.style.display = hideLogistics ? 'none' : '';
                });

                // HS Code field: only show for commercial_invoice
                const showHsFor = ['invoice'];
                const showHs = showHsFor.includes(docType);
                form.querySelectorAll('[data-hscode-field]').forEach((el) => {
                    el.style.display = showHs ? '' : 'none';
                });

                // Update hint text
                const hint = form.querySelector('[data-logistics-hint]');
                if (hint) {
                    const labels = {
                        quotation: '报价单阶段无需填写 HS Code 和物流字段（尺寸、重量、件数），选择发票/装箱单后自动显示。',
                        proforma_invoice: '形式发票阶段无需填写 HS Code 和物流字段（尺寸、重量、件数），选择发票/装箱单后自动显示。',
                        invoice: '已显示全部字段：HS Code（报关需要）+ 物流明细（件数、尺寸、重量）。',
                        packing_list: '已显示物流明细（件数、尺寸、重量），HS Code 无需填写（装箱单不含报关信息）。',
                        contract: '合同阶段无需填写 HS Code 和物流字段（尺寸、重量、件数）。',
                    };
                    hint.textContent = labels[docType] || '';
                }
            };
            form.querySelector('[name="document_type"]')?.addEventListener('change', toggleFieldsByDocType);
            toggleFieldsByDocType();

            if (!form) return;
            const customerProfiles = @json($customerProfiles ?? []);
            const sellerProfiles = {
                bank_account: @json($bankAccountProfileOptions ?? []),
                seller_company: @json($sellerCompanyProfileOptions ?? []),
            };
            const sellerProfileSaveUrl = @json(route('admin.crm.quotes.seller-profiles.store'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || form.querySelector('input[name="_token"]')?.value || '';
            const rowsContainer = form.querySelector('[data-crm-quote-items]');
            const template = form.querySelector('[data-crm-quote-row-template]');
            const subtotalEl = form.querySelector('[data-items-subtotal]');
            const grandTotalEl = form.querySelector('[data-grand-total]');
            const money = (value) => Number.parseFloat(String(value || '0')) || 0;
            const format = (value) => value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            const refreshIcons = () => {
                if (window.lucide) window.lucide.createIcons();
            };

            const profileById = (type, id) => (sellerProfiles[type] || []).find((profile) => String(profile.id) === String(id));

            const formatJsonTextarea = (textarea) => {
                const value = String(textarea?.value || '').trim();
                if (!value) return null;
                try {
                    const parsed = JSON.parse(value);
                    if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') {
                        throw new Error('JSON 必须是对象格式');
                    }
                    textarea.value = JSON.stringify(parsed, null, 2);
                    return textarea.value;
                } catch (error) {
                    alert(`JSON 格式错误：${error.message}`);
                    textarea?.focus();
                    return false;
                }
            };

            form.querySelectorAll('[data-seller-json-panel]').forEach((panel) => {
                const type = panel.dataset.profileType;
                const select = panel.querySelector('[data-profile-select]');
                const textarea = panel.querySelector('[data-json-template-field]');

                panel.querySelector('[data-profile-import]')?.addEventListener('click', () => {
                    const profile = profileById(type, select?.value || '');
                    if (!profile || !textarea) {
                        alert('请选择一个常用模板');
                        return;
                    }
                    textarea.value = profile.payload || '';
                    formatJsonTextarea(textarea);
                });

                panel.querySelector('[data-json-format]')?.addEventListener('click', () => {
                    formatJsonTextarea(textarea);
                });

                panel.querySelector('[data-profile-save]')?.addEventListener('click', async () => {
                    const payload = formatJsonTextarea(textarea);
                    if (!payload) return;

                    const parsed = JSON.parse(payload);
                    const defaultName = type === 'seller_company'
                        ? (parsed.name || parsed.company || 'Seller Company')
                        : (parsed.bank_name || parsed.beneficiary || 'Bank Account');
                    const name = window.prompt('请输入常用模板名称', String(defaultName));
                    if (!name || !name.trim()) return;

                    const button = panel.querySelector('[data-profile-save]');
                    button.disabled = true;
                    try {
                        const response = await fetch(sellerProfileSaveUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({
                                type,
                                name: name.trim(),
                                payload,
                                set_default: Boolean(panel.querySelector('[data-profile-default]')?.checked),
                            }),
                        });
                        const result = await response.json();
                        if (!response.ok) {
                            throw new Error(result.message || '保存常用模板失败');
                        }

                        const profile = result.profile;
                        sellerProfiles[type] = (sellerProfiles[type] || []).filter((item) => String(item.id) !== String(profile.id));
                        sellerProfiles[type].push(profile);

                        let option = select.querySelector(`option[value="${profile.id}"]`);
                        if (!option) {
                            option = document.createElement('option');
                            option.value = profile.id;
                            select.appendChild(option);
                        }
                        option.textContent = `${profile.name}${profile.is_default ? ' · 默认' : ''}`;
                        select.value = String(profile.id);
                        alert(result.message || '常用信息已保存');
                    } catch (error) {
                        alert(error.message || '保存常用模板失败');
                    } finally {
                        button.disabled = false;
                    }
                });
            });

            const calculate = () => {
                let subtotal = 0;
                rowsContainer.querySelectorAll('[data-crm-quote-item-row]').forEach((row) => {
                    const quantity = money(row.querySelector('[data-quote-quantity]')?.value);
                    const unitPrice = money(row.querySelector('[data-quote-unit-price]')?.value);
                    const amount = quantity * unitPrice;
                    subtotal += amount;
                    const rowAmount = row.querySelector('[data-quote-row-amount]');
                    if (rowAmount) rowAmount.textContent = format(amount);
                });
                const shipping = money(form.querySelector('[name="shipping_fee"]')?.value);
                const discount = money(form.querySelector('[name="discount_amount"]')?.value);
                const tax = money(form.querySelector('[name="tax_amount"]')?.value);
                if (subtotalEl) subtotalEl.textContent = format(subtotal);
                if (grandTotalEl) grandTotalEl.textContent = format(subtotal + shipping + tax - discount);
            };

            form.addEventListener('input', (event) => {
                if (event.target.matches('[data-quote-quantity], [data-quote-unit-price], [data-summary-input]')) {
                    calculate();
                }
            });

            form.querySelector('[data-add-quote-item]')?.addEventListener('click', () => {
                if (!template || !rowsContainer) return;
                rowsContainer.insertAdjacentHTML('beforeend', template.innerHTML.trim());
                refreshIcons();
                calculate();
            });

            form.querySelector('[data-fill-buyer-from-customer]')?.addEventListener('click', () => {
                const customerId = form.querySelector('[name="customer_id"]')?.value || '';
                const profile = customerProfiles[customerId];
                if (!profile) return;
                const fields = {
                    buyer_contact: profile.contact_person || '',
                    buyer_company: profile.name || '',
                    buyer_phone: profile.phone || '',
                    buyer_email: profile.email || '',
                    buyer_country: profile.country || '',
                    buyer_address: profile.address || '',
                };
                Object.entries(fields).forEach(([name, value]) => {
                    const input = form.querySelector(`[name="${name}"]`);
                    if (input && value !== '') input.value = value;
                });
                // Also set buyer_email from customer email (always sync, even if empty)
                const emailInput = form.querySelector('[name="buyer_email"]');
                if (emailInput && profile.email !== undefined) {
                    emailInput.value = profile.email || '';
                }
            });

            form.addEventListener('click', (event) => {
                const removeButton = event.target.closest('[data-remove-quote-item]');
                if (!removeButton) return;
                const rows = rowsContainer.querySelectorAll('[data-crm-quote-item-row]');
                const row = removeButton.closest('[data-crm-quote-item-row]');
                if (rows.length <= 1) {
                    row.querySelectorAll('input[type="text"], input[type="number"], input[type="hidden"], input[type="file"], textarea').forEach((input) => {
                        input.value = input.name.includes('[quantity]') ? '1' : (input.name.includes('[unit_price]') ? '0' : '');
                    });
                    row.querySelectorAll('select').forEach((select) => { select.selectedIndex = 0; });
                } else {
                    row.remove();
                }
                calculate();
            });

            calculate();
        });
    </script>
@endsection
