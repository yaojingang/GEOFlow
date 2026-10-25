@php($platformConfig = isset($channel) ? $channel->resolvedPlatformWebConfig() : [])
<fieldset class="space-y-4 rounded-lg border border-gray-200 p-5" data-platform-web-config>
    <legend class="text-sm font-semibold text-gray-900">人工辅助发布</legend>
    <p class="text-sm text-gray-600">头条号、搜狐号、网易号：打开目标编辑器，复制标题与正文，由操作员检查并发布，再提交人工回执。更新与删除请在平台中人工处理。</p>
    <div>
        <label for="platform" class="block text-sm font-medium text-gray-700">平台</label>
        <select name="platform" id="platform" class="mt-1 w-full rounded-md border-gray-300 text-sm">
            @foreach (\App\Services\GeoFlow\Distribution\PlatformWeb\PlatformCatalog::all() as $key => $item)
                <option value="{{ $key }}" @selected(old('platform', $platformConfig['platform'] ?? 'toutiao') === $key)>{{ $item['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="manual_publication_account_id" class="block text-sm font-medium text-gray-700">平台账号（须与所选平台一致）</label>
        <select name="manual_publication_account_id" id="manual_publication_account_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
            <option value="">请选择</option>
            @foreach ($platformAccounts as $account)
                <option value="{{ $account->id }}" @selected((int) old('manual_publication_account_id', $platformConfig['manual_publication_account_id'] ?? 0) === (int) $account->id)>{{ \App\Services\GeoFlow\Distribution\PlatformWeb\PlatformCatalog::label($account->platform) }} · {{ $account->account_name }} · {{ $account->persona->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="editor_url" class="block text-sm font-medium text-gray-700">编辑器地址覆盖（留空使用平台默认地址）</label>
        <input type="url" name="editor_url" id="editor_url" value="{{ old('editor_url', $platformConfig['editor_url'] ?? '') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
    </div>
    <input type="hidden" name="append_source_link" value="0">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="append_source_link" value="1" @checked(old('append_source_link', $platformConfig['append_source_link'] ?? false))>附加来源链接</label>
</fieldset>
