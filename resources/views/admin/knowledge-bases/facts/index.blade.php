@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0" data-atomic-fact-workbench @if($activeGenerationRun) data-active-generation-run='@json($activeGenerationRun)' @endif>
        <div class="mb-7">
            <x-admin.v3.knowledge-base-subnav :knowledge-base="$knowledgeBase" active="facts" />
        </div>

        <header class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-semibold text-orange-700">知识治理</p>
                <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-950">原子事实工作台</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">审核标准答案与证据，处理冲突并发布可供文章质检使用的稳定版本。</p>
            </div>
            <a href="{{ route('admin.knowledge-bases.detail', $knowledgeBase->id) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-500">返回知识库详情</a>
        </header>

        <section aria-label="事实库状态" class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @foreach([
                ['事实总数', $factSummary['fact_count'], 'list-checks'],
                ['待审核', $factSummary['pending_count'], 'clipboard-check'],
                ['冲突', $factSummary['conflict_count'], 'triangle-alert'],
                ['生效版本', $factSummary['active_version'] ? 'v'.$factSummary['active_version'] : '未发布', 'git-branch'],
                ['服务状态', $factSummary['ready'] ? '质检可用' : '准备中', 'shield-check'],
            ] as [$label, $value, $icon])
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-slate-500"><i data-lucide="{{ $icon }}" class="h-4 w-4"></i><span class="text-xs font-semibold">{{ $label }}</span></div>
                    <p class="mt-2 text-xl font-bold text-slate-950">{{ $value }}</p>
                </div>
            @endforeach
        </section>

        <form method="GET" class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row">
            <label class="flex-1"><span class="sr-only">搜索事实</span><input name="q" value="{{ request('q') }}" placeholder="搜索指标、主体或关系" class="min-h-10 w-full rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500"></label>
            <label><span class="sr-only">状态筛选</span><select name="status" class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500"><option value="">全部状态</option><option value="pending" @selected(request('status') === 'pending')>待审核</option><option value="reviewed" @selected(request('status') === 'reviewed')>已审核</option><option value="conflict" @selected(request('status') === 'conflict')>有冲突</option></select></label>
            <button class="min-h-10 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-500">筛选</button>
        </form>

        @include('admin.knowledge-bases.partials.atomic-facts', ['factLibrary' => $factLibrary, 'systemReadOnly' => $systemReadOnly])
        <div class="mt-5">{{ $facts->links() }}</div>
    </div>
@endsection
