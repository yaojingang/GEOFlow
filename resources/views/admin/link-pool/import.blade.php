@extends('admin.layouts.app')
@push('scripts')
    <script src="{{ asset('assets/js/admin-link-pool-import.js') }}" defer></script>
@endpush
@section('content')
@include('admin.link-pool.partials.header')
<div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-0">
    <div><a href="{{ route('admin.link-pool.targets', $siteKey ? ['site' => $siteKey] : []) }}" class="text-sm font-medium text-blue-700">← 返回链接库</a><h2 class="mt-2 text-xl font-semibold text-gray-900">导入链接</h2><p class="mt-1 text-sm leading-6 text-gray-600">支持 GEOFlow 文章和任意外部网页的 HTTP / HTTPS 链接，包括首页、产品页和带参数的地址。外部链接由管理员直接导入，保存后自动检查可访问性。</p></div>
    @if($counts)
        <div role="status" class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            导入完成：新增 {{ $counts['new'] }}，更新 {{ $counts['update'] }}，重复 {{ $counts['duplicate'] }}，错误 {{ $counts['error'] }}。
            <a class="ml-2 font-semibold underline" href="{{ route('admin.link-pool.targets', $siteKey ? ['site' => $siteKey] : []) }}">查看链接库</a>
            <a class="ml-2 font-semibold underline" href="{{ route('admin.link-pool.campaigns.index', ['site' => $siteKey]) }}">配置投放活动</a>
        </div>
        @if($canWrite && $counts['new'] > 0)
            <form method="POST" action="{{ route('admin.link-pool.import.undo', ['batchId' => $counts['batch_id'], 'site' => $siteKey]) }}" data-admin-confirm-form data-admin-confirm-tone="warning" data-admin-confirm-title="撤销本批新增目标" data-admin-confirm-message="仅移除本批新建且尚未被运行中活动使用的目标；已有投放引用的目标会保留。" data-admin-confirm-label="确认撤销">
                @csrf
                <button class="min-h-10 rounded-lg border border-amber-300 bg-amber-50 px-4 text-sm font-medium text-amber-800">撤销本批新增目标</button>
            </form>
        @endif
    @endif
    @if($canWrite)
    <form method="POST" action="{{ route('admin.link-pool.import.preflight', $siteKey ? ['site' => $siteKey] : []) }}" enctype="multipart/form-data" class="link-pool-import-form" data-link-pool-import-form>@csrf
        @if($importDelimiter)<input type="hidden" name="delimiter" value="{{ $importDelimiter }}">@endif
        <div class="link-pool-import-form__intro">
            @if($importDelimiter)<p>当前按{{ $importDelimiter === 'tsv' ? '表格' : 'CSV' }}解析。<button type="submit" name="delimiter" value="" class="font-medium text-blue-700 underline">恢复自动识别</button></p>@endif
            <h3>选择导入方式</h3>
            <p>上传文件或粘贴内容，每次使用一种方式。预检通过后再确认导入。</p>
        </div>
        <div class="link-pool-import-options">
            <section class="link-pool-import-option link-pool-import-option--file" aria-labelledby="link-pool-file-title">
                <div class="link-pool-import-option__heading">
                    <span class="link-pool-import-option__number">01</span>
                    <div><h4 id="link-pool-file-title">上传 CSV 文件</h4><p>适合从表格批量导出的链接</p></div>
                </div>
                <label class="link-pool-upload-zone" data-link-pool-upload-zone>
                    <span class="link-pool-upload-zone__icon" aria-hidden="true"><i data-lucide="file-up" class="h-6 w-6"></i></span>
                    <span class="link-pool-upload-zone__title">选择 CSV 文件</span>
                    <span class="link-pool-upload-zone__drag-hint">或将文件拖放到这里</span>
                    <input id="link-pool-import-file" type="file" name="file" accept=".csv,.txt,text/csv,text/plain" aria-label="选择 CSV 或 TXT 文件" aria-describedby="link-pool-file-help" class="link-pool-upload-zone__input">
                </label>
                <p id="link-pool-file-help" class="link-pool-import-option__help">UTF-8 CSV / TXT · 最大 1 MB · 最多 1000 行</p>
                <div class="link-pool-selected-file" data-link-pool-selected-file hidden>
                    <span class="link-pool-selected-file__icon" aria-hidden="true"><i data-lucide="file-text" class="h-5 w-5"></i></span>
                    <span class="link-pool-selected-file__details"><strong data-link-pool-file-name></strong><small data-link-pool-file-size></small></span>
                    <button type="button" data-link-pool-file-clear aria-label="移除已选文件" class="link-pool-selected-file__clear">移除</button>
                </div>
                <p class="link-pool-import-error" data-link-pool-file-error role="alert" hidden></p>
            </section>
            <section class="link-pool-import-option link-pool-import-option--paste" aria-labelledby="link-pool-paste-title">
                <div class="link-pool-import-option__heading">
                    <span class="link-pool-import-option__number">02</span>
                    <div><h4 id="link-pool-paste-title">粘贴 CSV 内容</h4><p>适合少量链接，也可直接粘贴 Excel / WPS 表格</p></div>
                </div>
                <label for="link-pool-import-links" class="link-pool-import-option__field-label">每行一条链接</label>
                <textarea id="link-pool-import-links" name="links" rows="8" class="link-pool-import-option__textarea" placeholder="url,title&#10;https://geo.example/a/1,文章标题" spellcheck="false">{{ $input }}</textarea>
                <p class="link-pool-import-option__help">支持逗号或表格制表符分隔。必填：url、title。可选：topic、priority、expires_at。</p>
            </section>
        </div>
        <div class="link-pool-import-form__footer"><p>预检不会修改链接库，确认导入后才会保存有效行。</p>@if($errors->has('delimiter'))<div class="flex flex-wrap gap-3"><button type="submit" name="delimiter" value="tsv" class="link-pool-import-form__submit">按表格重新预检</button><button type="submit" name="delimiter" value="csv" class="min-h-10 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-800">按 CSV 重新预检</button></div>@else<button type="submit" class="link-pool-import-form__submit">逐行预检 <span aria-hidden="true">→</span></button>@endif</div>
    </form>
    @endif
    @if($rows !== null)
        @php($validRows = collect($rows)->whereIn('status',['new','update']))
        <section class="space-y-3"><div class="flex flex-wrap items-center justify-between gap-3"><h3 class="text-base font-semibold">预检结果 · {{ count($rows) }} 行</h3>@if(collect($rows)->where('status','error')->isNotEmpty())<form method="POST" action="{{ route('admin.link-pool.import.errors') }}">@csrf
@if($importDelimiter)<input type="hidden" name="delimiter" value="{{ $importDelimiter }}">@endif
<textarea name="links" hidden>{{ $input }}</textarea><button class="min-h-10 rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-700">下载错误 CSV</button></form>@endif</div><p class="text-sm text-gray-600">可新增 {{ collect($rows)->where('status','new')->count() }} · 可更新 {{ collect($rows)->where('status','update')->count() }} · 重复 {{ collect($rows)->where('status','duplicate')->count() }} · 错误 {{ collect($rows)->where('status','error')->count() }}</p>
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white"><table class="min-w-full divide-y divide-gray-100 text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-600"><tr><th class="px-3 py-3">行</th><th class="px-3 py-3">输入 URL / 标题</th><th class="px-3 py-3">归属与当前 URL</th><th class="px-3 py-3">结果</th></tr></thead><tbody class="divide-y divide-gray-100">@foreach($rows as $row)<tr><td class="px-3 py-3">{{ $row['line'] }}</td><td class="max-w-xs break-all px-3 py-3">{{ $row['title'] }}<br><span class="text-xs text-gray-500">{{ $row['url'] }}</span></td><td class="max-w-xs break-all px-3 py-3 text-xs">{{ ($row['target_type'] ?? '') === 'external' ? '外部链接' : ($row['site_key'] ?? '—') }}<br>{{ $row['canonical_url'] ?? '' }}@if($row['title_differs'] ?? false)<br><span class="text-amber-700">文章现标题：{{ $row['article_title'] }}</span>@endif</td><td class="px-3 py-3 {{ $row['status']==='error' ? 'text-red-700' : 'text-green-700' }}">{{ ['new'=>'可新增','update'=>'可更新','duplicate'=>'重复跳过','error'=>'错误'][$row['status']] }}<br><span class="text-xs">{{ $row['reason'] }}</span></td></tr>@endforeach</tbody></table></div>
            @if($canWrite && $validRows->isNotEmpty() && !$counts)
                <form method="POST" action="{{ route('admin.link-pool.import.store', $siteKey ? ['site' => $siteKey] : []) }}" class="space-y-3 rounded-lg border border-blue-100 bg-blue-50 p-4">@csrf
@if($importDelimiter)<input type="hidden" name="delimiter" value="{{ $importDelimiter }}">@endif
<textarea name="links" hidden>{{ $input }}</textarea>@foreach($rows as $row)@if(($row['title_differs'] ?? false) && in_array($row['status'],['new','update']))<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="use_article_title_lines[]" value="{{ $row['line'] }}" @checked(in_array($row['line'], old('use_article_title_lines', []))) class="rounded border-gray-300">第 {{ $row['line'] }} 行改用文章现标题「{{ $row['article_title'] }}」</label>
                    @endif
                @endforeach
                @if($validRows->contains(fn ($row) => $row['title_differs'] ?? false))<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="ack_titles" value="1" @checked(old('ack_titles')) class="rounded border-gray-300">我已核对展示标题准确描述目标内容（含与原文章标题不同的行）</label>@endif<button class="min-h-10 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white">确认导入有效行</button></form>
            @endif
        </section>
    @endif
</div>
@endsection
