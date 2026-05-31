@extends('admin.layouts.app')

@php
    $guideSteps = [
        [
            'label' => '先完成企业资料',
            'description' => '把品牌名称、业务范围、服务区域、客户痛点、案例和禁用表达补齐，后面的 AI 检视和内容生成才不会跑偏。',
            'url' => route('admin.geo.workspace').'#setup',
            'icon' => 'building-2',
            'where' => '顶部点「GEO获客」，再切到「企业资料」。',
            'fields' => ['品牌名称、简称、别名', '产品服务、服务区域、客户痛点', '案例素材、信任背书、禁用表达'],
            'done' => '品牌完整度至少到 80%，并且禁用表达不为空。',
            'pitfall' => '不要只写广告口号，要写 AI 能引用的客观事实。',
        ],
        [
            'label' => '再做 AI 检视',
            'description' => '用真实客户问题去问多个 AI 平台，先判断客户能不能在 AI 里搜到你，再决定要补哪些内容。',
            'url' => route('admin.web-workbench.index'),
            'icon' => 'messages-square',
            'where' => '进入「AI检视」，把客户会问的问题一行一个写进去。',
            'fields' => ['品牌词问题：某品牌怎么样', '品类推荐问题：本地全屋定制哪家靠谱', '避坑问题：怎么避免报价和售后踩坑'],
            'done' => '拿到每个平台的原始回答、品牌是否出现、引用来源和可见度分。',
            'pitfall' => '不要只问品牌名，要问真实客户会搜索的成交型问题。',
        ],
        [
            'label' => '沉淀引用来源',
            'description' => '把 AI 已引用、竞品正在占位、或适合仿写的网页收集起来，作为后续内容生产的证据来源。',
            'url' => route('admin.geo.citation-sources.index'),
            'icon' => 'link-2',
            'where' => '进入「引用来源」，查看 AI 回答里的链接，也可以手动补充竞品页面。',
            'fields' => ['页面 URL', '页面标题', '为什么值得引用', '适合仿写的角度'],
            'done' => '每个核心问题至少有 3-5 个可参考来源。',
            'pitfall' => '不要把低质量目录页当成引用源，优先保留有案例、过程、价格解释、避坑内容的页面。',
        ],
        [
            'label' => '生成内容资产',
            'description' => '把引用来源转成草稿、正式文章、公众号发布包和素材库，让企业在互联网上有可被 AI 引用的内容。',
            'url' => route('admin.articles.index'),
            'icon' => 'newspaper',
            'where' => '进入「内容资产」，先看草稿，再转正式文章，最后做发布包。',
            'fields' => ['标题是否包含机会词', '正文是否回答真实问题', '是否引用企业事实和案例', '是否通过审核'],
            'done' => '草稿转为正式文章，并能进入公众号或站点发布流程。',
            'pitfall' => '不要只发文章；要确保文章能回答 AI 检视里暴露的问题。',
        ],
        [
            'label' => '发布后复测',
            'description' => '内容发布后再次检视 AI 回答，记录是否出现品牌、是否引用新页面，再安排下一轮优化。',
            'url' => route('admin.tasks.index'),
            'icon' => 'repeat-2',
            'where' => '发布后回到「发布复测」或「任务管理」，对同一批问题再跑一次。',
            'fields' => ['发布链接', '复测问题', '复测前后得分', '是否新增品牌露出或引用'],
            'done' => '能看到前后变化，并沉淀下一轮补内容任务。',
            'pitfall' => '不要发布完就结束；GEO 是发布、复测、再补内容的循环。',
        ],
    ];
    $dailyActions = [
        ['label' => '今天只想看结果', 'description' => '打开总览，看是否有待审核、失败任务和最新内容表现。', 'url' => route('admin.dashboard'), 'icon' => 'layout-dashboard'],
        ['label' => '今天要找客户机会', 'description' => '进入 GEO 工作台，从企业资料、机会词和 AI 检视开始。', 'url' => route('admin.geo.workspace'), 'icon' => 'radar'],
        ['label' => '今天要写内容', 'description' => '进入内容资产，看草稿、文章、公众号包和待审核内容。', 'url' => route('admin.articles.index'), 'icon' => 'file-text'],
        ['label' => '今天要检查系统', 'description' => '进入模型和设置，检查 API、提示词、站点、敏感词和发布基础配置。', 'url' => route('admin.ai.configurator'), 'icon' => 'sliders-horizontal'],
    ];
@endphp

@section('content')
    <div data-admin-operation-guide class="space-y-6">
        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="grid gap-0 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div class="px-5 py-6 sm:px-6 lg:px-7">
                    <p class="inline-flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
                        <i data-lucide="circle-help" class="h-3.5 w-3.5"></i>
                        操作说明
                    </p>
                    <h1 class="mt-4 text-2xl font-semibold text-slate-950">系统操作说明</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        这个后台的核心不是“多发文章”，而是帮企业先被 AI 搜到、被 AI 引用，再把内容发布出去并复测效果。
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a href="{{ route('admin.geo.workspace') }}" class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="route" class="h-4 w-4"></i>
                            按主线开始
                        </a>
                        <a href="{{ route('admin.dashboard') }}" class="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            <i data-lucide="layout-dashboard" class="h-4 w-4"></i>
                            返回总览
                        </a>
                    </div>
                </div>
                <div class="border-t border-slate-100 bg-slate-50 px-5 py-6 lg:border-l lg:border-t-0">
                    <h2 class="text-sm font-semibold text-slate-950">日常最短路径</h2>
                    <div class="mt-4 space-y-3">
                        <div class="rounded-md border border-slate-200 bg-white px-4 py-3">
                            <div class="text-xs text-slate-500">第 1 步</div>
                            <div class="mt-1 text-sm font-semibold text-slate-950">企业资料补齐</div>
                        </div>
                        <div class="rounded-md border border-slate-200 bg-white px-4 py-3">
                            <div class="text-xs text-slate-500">第 2 步</div>
                            <div class="mt-1 text-sm font-semibold text-slate-950">AI 检视和引用来源</div>
                        </div>
                        <div class="rounded-md border border-slate-200 bg-white px-4 py-3">
                            <div class="text-xs text-slate-500">第 3 步</div>
                            <div class="mt-1 text-sm font-semibold text-slate-950">内容发布与复测</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                <h2 class="text-base font-semibold text-slate-950">怎么实际操作</h2>
                <p class="mt-1 text-sm text-slate-500">不是只给入口跳转，而是告诉新用户每一步点哪里、填什么、做到什么程度。</p>
            </div>
            <div class="divide-y divide-slate-100">
                @foreach($guideSteps as $index => $step)
                    <div class="grid gap-4 px-5 py-5 lg:grid-cols-[260px_minmax(0,1fr)_260px]">
                        <div>
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-blue-50 text-blue-700">
                                    {{ $index + 1 }}
                                </span>
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">{{ $step['label'] }}</div>
                                    <div class="mt-1 text-xs leading-5 text-slate-500">{{ $step['description'] }}</div>
                                </div>
                            </div>
                            <a href="{{ $step['url'] }}" class="mt-4 inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700">
                                <i data-lucide="{{ $step['icon'] }}" class="h-4 w-4"></i>
                                打开对应页面
                            </a>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-xs font-semibold text-slate-500">点击哪里</div>
                                <div class="mt-2 text-sm leading-6 text-slate-800">{{ $step['where'] }}</div>
                            </div>
                            <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-xs font-semibold text-slate-500">填写什么</div>
                                <ul class="mt-2 space-y-1 text-sm leading-6 text-slate-800">
                                    @foreach($step['fields'] as $field)
                                        <li class="flex gap-2">
                                            <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-500"></span>
                                            <span>{{ $field }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3">
                                <div class="text-xs font-semibold text-emerald-700">完成标准</div>
                                <div class="mt-2 text-sm leading-6 text-emerald-950">{{ $step['done'] }}</div>
                            </div>
                            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3">
                                <div class="text-xs font-semibold text-amber-700">常见卡点</div>
                                <div class="mt-2 text-sm leading-6 text-amber-950">{{ $step['pitfall'] }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-4">
            @foreach($dailyActions as $action)
                <a href="{{ $action['url'] }}" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-blue-200 hover:bg-blue-50/50">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-slate-100 text-slate-600">
                        <i data-lucide="{{ $action['icon'] }}" class="h-4 w-4"></i>
                    </span>
                    <span class="mt-4 block text-sm font-semibold text-slate-950">{{ $action['label'] }}</span>
                    <span class="mt-2 block text-xs leading-5 text-slate-500">{{ $action['description'] }}</span>
                </a>
            @endforeach
        </section>

        <section class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900">
            <div class="font-semibold">使用原则</div>
            <p class="mt-1">先看 AI 是否能搜到企业，再决定写什么内容；先沉淀真实引用来源，再生成文章；发布之后一定复测，避免只发内容、不知道有没有效果。</p>
        </section>
    </div>
@endsection
