<div data-geo-workbench-platform-options>
    <div class="mb-2 flex items-center justify-between gap-3">
        <div class="text-sm font-medium text-gray-700">检视平台</div>
        <div data-geo-workbench-module-message class="hidden rounded-lg border border-blue-100 bg-blue-50 px-2.5 py-1 text-xs text-blue-700"></div>
    </div>
    <div class="grid gap-2 md:grid-cols-2">
        @foreach ($realSearchPlatforms as $platform)
            @if ($platform->code === 'ai_web_workbench' && $webWorkbenchPlatformStatuses->isNotEmpty())
                <div class="rounded-lg border border-blue-200 bg-blue-50/60 p-3 md:col-span-2">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="platform_codes[]" value="{{ $platform->code }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $platform->name }}</span>
                            <span class="block truncate text-xs text-blue-700">勾选后检测工作台全部已启用平台</span>
                        </span>
                    </label>
                    <div class="mt-3 border-t border-blue-100 pt-3">
                        <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs font-medium text-gray-700">平台检测选项</div>
                            <button type="submit" form="geo-web-workbench-check-logins-form" data-geo-workbench-action data-busy-label="正在检查..." class="inline-flex w-fit items-center gap-1 rounded-lg border border-blue-200 bg-white px-2.5 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-50">
                                <i data-lucide="refresh-cw" class="h-3.5 w-3.5"></i>
                                <span data-geo-workbench-label>一键检查登录状态</span>
                            </button>
                        </div>
                        <div class="grid gap-2 md:grid-cols-2">
                            @foreach ($webWorkbenchPlatformStatuses as $platformStatus)
                                @php
                                    $platformId = (string) ($platformStatus['platformId'] ?? '');
                                    $platformName = (string) ($platformStatus['platformName'] ?? $platformId);
                                    $internalPlatformCode = 'ai_web_workbench:'.$platformId;
                                    $loginOk = $platformStatus['loginOk'] ?? null;
                                    $statusLabel = (string) ($platformStatus['loginStatus'] ?? '未检测');
                                    $statusClass = $loginOk === true
                                        ? 'text-emerald-700'
                                        : ($loginOk === false ? 'text-amber-700' : 'text-gray-500');
                                @endphp
                                <div class="flex items-center gap-2 rounded-lg border border-white/80 bg-white px-3 py-2 text-sm text-gray-700">
                                    <label class="flex min-w-0 flex-1 items-center gap-2">
                                        <input type="checkbox" name="platform_codes[]" value="{{ $internalPlatformCode }}" @checked($loginOk === true) class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" @disabled($platformId === '')>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate">{{ $platformName }}</span>
                                            <span class="block truncate text-xs {{ $statusClass }}">{{ $statusLabel }}</span>
                                        </span>
                                    </label>
                                    @if ($platformId !== '' && $loginOk !== true)
                                        <button type="submit" form="geo-web-workbench-open-login-form" name="platform_id" value="{{ $platformId }}" data-geo-workbench-action data-busy-label="正在打开..." class="shrink-0 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-100">
                                            <span data-geo-workbench-label>打开登录</span>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <label class="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50/60 px-3 py-2 text-sm text-gray-700">
                    <input type="checkbox" name="platform_codes[]" value="{{ $platform->code }}" checked class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $platform->name }}</span>
                        <span class="block truncate text-xs text-blue-700">真实网页 AI 搜索</span>
                    </span>
                </label>
            @endif
        @endforeach
        @foreach ($realAiModels as $model)
            <label class="flex items-center gap-2 rounded-lg border border-blue-100 bg-blue-50/40 px-3 py-2 text-sm text-gray-700">
                <input type="checkbox" name="platform_codes[]" value="ai_model:{{ (int) $model->id }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="min-w-0">
                    <span class="block truncate">{{ $model->name }}</span>
                    <span class="block truncate text-xs text-gray-500">{{ $model->model_id }}</span>
                </span>
            </label>
        @endforeach
    </div>
    @if ($mockSearchPlatforms->isNotEmpty())
        <details class="mt-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
            <summary class="cursor-pointer text-xs font-medium text-gray-600">测试备用平台</summary>
            <div class="mt-3 grid gap-2 md:grid-cols-3">
                @foreach ($mockSearchPlatforms as $platform)
                    <label class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600">
                        <input type="checkbox" name="platform_codes[]" value="{{ $platform->code }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>{{ $platform->name }}</span>
                    </label>
                @endforeach
            </div>
        </details>
    @endif
</div>
