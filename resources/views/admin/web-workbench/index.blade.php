@extends('admin.layouts.app')

@php
    $statusOk = (bool) ($status['ok'] ?? false);
    $tasks = collect((array) ($status['tasks'] ?? []));
    $platformStatuses = collect((array) ($status['platforms'] ?? []));
    $result = is_array($cliResult ?? null) ? $cliResult : null;
@endphp

@section('content')
    <div class="space-y-6">
        <section data-admin-ai-inspection-shell class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="inline-flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
                        <i data-lucide="messages-square" class="h-3.5 w-3.5"></i>
                        AI网页工作台
                    </p>
                    <h1 class="mt-4 text-2xl font-semibold text-gray-900">AI 检视工作台</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-500">
                        多平台 AI 网页对话工作台通过本机 <span class="font-mono text-gray-700">ai-web-workbench</span> CLI 调用已登录的网页 AI 平台，适合做 GEO 多平台搜索、回答采集和引用来源导出。
                    </p>
                </div>
                <form method="POST" action="{{ route('admin.web-workbench.open') }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="external-link" class="h-4 w-4"></i>
                        打开工作台 UI
                    </button>
                </form>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <div class="text-xs text-gray-500">CLI 命令</div>
                    <div class="mt-2 truncate font-mono text-sm font-medium text-gray-900">{{ $commandPath ?: 'ai-web-workbench' }}</div>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <div class="text-xs text-gray-500">状态</div>
                    <div class="mt-2 text-sm font-semibold {{ $statusOk ? 'text-emerald-700' : 'text-amber-700' }}">
                        {{ $statusOk ? '可调用' : '待检查' }}
                    </div>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <div class="text-xs text-gray-500">数据目录</div>
                    <div class="mt-2 truncate font-mono text-xs font-medium text-gray-900">{{ $dataDir ?: '未读取到' }}</div>
                </div>
            </div>

            @if (! $statusOk && trim((string) ($status['error'] ?? '')) !== '')
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ $status['error'] }}
                </div>
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">平台监视</h2>
                    <p class="mt-1 text-sm text-gray-500">执行时会检测账号登录状态；未正常登录的平台会在这里提示打开工作台 UI 登录。</p>
                </div>
                <span class="w-fit rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">{{ $platformStatuses->count() }} 个网页平台</span>
            </div>
            <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @forelse ($platformStatuses as $platform)
                    @php
                        $platformId = (string) ($platform['platformId'] ?? '');
                        $platformName = (string) ($platform['platformName'] ?? $platformId);
                        $loginOk = $platform['loginOk'] ?? null;
                        $statusLabel = (string) ($platform['loginStatus'] ?? '未检测');
                        $statusClass = $loginOk === true
                            ? 'bg-emerald-50 text-emerald-700'
                            : ($loginOk === false ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-600');
                    @endphp
                    <article class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-gray-900">{{ $platformName }}</div>
                                <div class="mt-1 font-mono text-xs text-gray-500">{{ $platformId }}</div>
                            </div>
                            <span class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-gray-500">
                            <div>完成 <span class="font-medium text-gray-900">{{ (int) ($platform['completedCount'] ?? 0) }}</span></div>
                            <div>总计 <span class="font-medium text-gray-900">{{ (int) ($platform['runCount'] ?? 0) }}</span></div>
                        </div>
                        @if (trim((string) ($platform['loginHint'] ?? '')) !== '')
                            <div class="mt-3 text-xs leading-5 text-gray-500">{{ $platform['loginHint'] }}</div>
                        @endif
                        @if (trim((string) ($platform['lastError'] ?? '')) !== '')
                            <div class="mt-2 line-clamp-2 text-xs text-amber-700">{{ $platform['lastError'] }}</div>
                        @endif
                        <button
                            type="submit"
                            form="workbench-run-form"
                            name="platform_ids[]"
                            value="{{ $platformId }}"
                            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @disabled($platformId === '')
                        >
                            <i data-lucide="radar" class="h-4 w-4"></i>
                            单平台检测
                        </button>
                    </article>
                @empty
                    <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center text-sm text-gray-500 md:col-span-2 xl:col-span-4">
                        暂未读取到平台明细。先刷新状态或打开工作台 UI 执行一次检测。
                    </div>
                @endforelse
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">直接调用 CLI</h2>
                <p class="mt-1 text-sm text-gray-500">等同于执行 <span class="font-mono">ai-web-workbench run --question "..." --platform chatgpt --json</span>；不选平台时默认检测全部平台。</p>

                <form id="workbench-run-form" method="POST" action="{{ route('admin.web-workbench.run') }}" class="mt-5 space-y-4">
                    @csrf
                    <div>
                        <label for="workbench-question" class="block text-sm font-medium text-gray-700">发送问题</label>
                        <textarea
                            id="workbench-question"
                            name="question"
                            rows="5"
                            class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="例如：重庆涪陵全屋定制客户为什么只问价格不预约量尺？"
                        >{{ old('question') }}</textarea>
                    </div>
                    @if ($platformStatuses->isNotEmpty())
                        <div>
                            <div class="mb-2 text-sm font-medium text-gray-700">选择检测平台</div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($platformStatuses as $platform)
                                    @php
                                        $platformId = (string) ($platform['platformId'] ?? '');
                                        $platformName = (string) ($platform['platformName'] ?? $platformId);
                                    @endphp
                                    <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                        <input type="checkbox" name="platform_ids[]" value="{{ $platformId }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span class="min-w-0 flex-1 truncate">{{ $platformName }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" name="show_worker" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" @checked(old('show_worker'))>
                        打开可见 worker 观察网页执行
                    </label>
                    <div>
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                            <i data-lucide="terminal" class="h-4 w-4"></i>
                            运行 CLI
                        </button>
                    </div>
                </form>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">导出任务</h2>
                <p class="mt-1 text-sm text-gray-500">等同于执行 <span class="font-mono">ai-web-workbench export &lt;taskId&gt; --json</span>。</p>
                <form method="POST" action="{{ route('admin.web-workbench.export') }}" class="mt-5 space-y-4">
                    @csrf
                    <div>
                        <label for="workbench-task-id" class="block text-sm font-medium text-gray-700">任务 ID</label>
                        <input
                            id="workbench-task-id"
                            name="task_id"
                            value="{{ old('task_id') }}"
                            class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="task-20260521151946-9g5em8"
                        >
                    </div>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="download" class="h-4 w-4"></i>
                        导出 Markdown
                    </button>
                </form>
            </section>
        </div>

        @if ($result)
            <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">最近一次 CLI 结果</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ $result['command'] ?? '' }}</p>
                    </div>
                    <span class="w-fit rounded-lg px-2.5 py-1 text-xs font-medium {{ ($result['ok'] ?? false) ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">
                        {{ ($result['ok'] ?? false) ? '成功' : '异常' }}
                    </span>
                </div>
                <div class="mt-4 grid gap-3 text-sm md:grid-cols-4">
                    <div class="rounded-lg bg-gray-50 p-3">
                        <div class="text-xs text-gray-500">动作</div>
                        <div class="mt-1 font-medium text-gray-900">{{ $result['action'] ?? '-' }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3">
                        <div class="text-xs text-gray-500">任务 ID</div>
                        <div class="mt-1 truncate font-mono text-xs text-gray-900">{{ $result['task_id'] ?? '-' }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3">
                        <div class="text-xs text-gray-500">完成平台</div>
                        <div class="mt-1 font-medium text-gray-900">{{ (int) ($result['completed_count'] ?? 0) }} / {{ (int) ($result['sent_count'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3">
                        <div class="text-xs text-gray-500">退出码</div>
                        <div class="mt-1 font-medium text-gray-900">{{ $result['exit_code'] ?? '-' }}</div>
                    </div>
                </div>
                @if (trim((string) ($result['markdown_path'] ?? '')) !== '')
                    <div class="mt-4 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800">
                        Markdown：<span class="font-mono">{{ $result['markdown_path'] }}</span>
                    </div>
                @endif
                <pre class="mt-4 max-h-[460px] overflow-auto rounded-lg bg-gray-950 p-4 text-xs leading-6 text-gray-100">{{ json_encode($result['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</pre>
            </section>
        @endif

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">最近任务</h2>
                    <p class="mt-1 text-sm text-gray-500">来自 <span class="font-mono">ai-web-workbench status --limit 10 --json</span>。</p>
                </div>
                <a href="{{ route('admin.web-workbench.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                    刷新状态
                </a>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse ($tasks as $task)
                    @php
                        $taskId = (string) ($task['id'] ?? '');
                    @endphp
                    <div class="grid gap-4 px-6 py-4 lg:grid-cols-[minmax(0,1fr)_160px_160px] lg:items-center">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-gray-900">{{ $task['title'] ?? $task['question'] ?? $taskId }}</div>
                            <div class="mt-1 truncate font-mono text-xs text-gray-500">{{ $taskId }}</div>
                            @if (trim((string) ($task['markdownPath'] ?? '')) !== '')
                                <div class="mt-1 truncate text-xs text-gray-500">{{ $task['markdownPath'] }}</div>
                            @endif
                        </div>
                        <div class="text-sm">
                            <div class="text-gray-900">{{ $task['status'] ?? '未知' }}</div>
                            <div class="mt-1 text-xs text-gray-500">完成 {{ (int) ($task['completedCount'] ?? 0) }} / {{ (int) ($task['runCount'] ?? 0) }}</div>
                        </div>
                        <form method="POST" action="{{ route('admin.web-workbench.export') }}" class="lg:text-right">
                            @csrf
                            <input type="hidden" name="task_id" value="{{ $taskId }}">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" @disabled($taskId === '')>
                                <i data-lucide="download" class="h-4 w-4"></i>
                                导出
                            </button>
                        </form>
                    </div>
                @empty
                    <div class="px-6 py-10 text-center text-sm text-gray-500">还没有读取到工作台任务。</div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
