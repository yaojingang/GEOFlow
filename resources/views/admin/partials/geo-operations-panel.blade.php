@php
    $panel = $panel ?? null;
    $toneClasses = [
        'green' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'blue' => 'border-blue-200 bg-blue-50 text-blue-700',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-700',
        'red' => 'border-red-200 bg-red-50 text-red-700',
        'slate' => 'border-slate-200 bg-slate-50 text-slate-700',
    ];
    $geoPrimarySteps = [
        [
            'label' => '企业资料',
            'description' => '补齐品牌、服务、案例和禁用表达',
            'url' => route('admin.geo.workspace').'#setup',
            'icon' => 'building-2',
        ],
        [
            'label' => '检视任务',
            'description' => '用多平台问答确认客户能不能搜到你',
            'url' => route('admin.web-workbench.index'),
            'icon' => 'messages-square',
        ],
        [
            'label' => '引用来源',
            'description' => '沉淀 AI 已引用或应该引用的网页证据',
            'url' => route('admin.geo.citation-sources.index'),
            'icon' => 'link-2',
        ],
        [
            'label' => '内容资产',
            'description' => '把引用源转成文章、素材和发布包',
            'url' => route('admin.articles.index'),
            'icon' => 'newspaper',
        ],
        [
            'label' => '发布复测',
            'description' => '分发后再次检视 AI 回答变化',
            'url' => route('admin.tasks.index'),
            'icon' => 'repeat-2',
        ],
    ];
@endphp

@if(is_array($panel))
    <section data-geo-primary-entry class="mb-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="grid gap-0 lg:grid-cols-[280px_minmax(0,1fr)]">
            <div class="border-b border-slate-100 bg-slate-50 px-5 py-5 lg:border-b-0 lg:border-r sm:px-6">
                <div class="inline-flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
                    <i data-lucide="route" class="h-3.5 w-3.5"></i>
                    GEO 获客主线
                </div>
                <h2 class="mt-3 text-lg font-semibold text-slate-950">{{ $panel['module_label'] ?? 'GEO 运营入口' }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $panel['description'] ?? '按企业资料、检视任务、引用来源、内容资产、发布复测推进。' }}</p>
            </div>
            <div class="grid divide-y divide-slate-100 md:grid-cols-5 md:divide-x md:divide-y-0">
                @foreach($geoPrimarySteps as $index => $step)
                    <a href="{{ $step['url'] }}" class="group flex min-h-32 flex-col justify-between px-4 py-4 transition-colors hover:bg-blue-50/60">
                        <span class="flex items-center justify-between gap-3">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-slate-100 text-slate-600 group-hover:bg-blue-100 group-hover:text-blue-700">
                                <i data-lucide="{{ $step['icon'] }}" class="h-4 w-4"></i>
                            </span>
                            <span class="text-xs font-medium text-slate-400">0{{ $index + 1 }}</span>
                        </span>
                        <span class="mt-4">
                            <span class="block text-sm font-semibold text-slate-950">{{ $step['label'] }}</span>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">{{ $step['description'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="mb-8 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-5 py-4 sm:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <div class="inline-flex items-center gap-2 rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1 text-xs font-medium text-cyan-700">
                        <i data-lucide="radar" class="h-3.5 w-3.5"></i>
                        {{ $panel['title'] ?? 'GEO 运营就绪' }}
                    </div>
                    <h2 class="mt-3 text-lg font-semibold text-gray-900">{{ $panel['module_label'] ?? '' }}</h2>
                    <p class="mt-1 max-w-4xl text-sm leading-6 text-gray-600">{{ $panel['description'] ?? '' }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach(($panel['quick_links'] ?? []) as $link)
                        <a href="{{ $link['url'] ?? '#' }}" class="inline-flex items-center rounded-md border px-3 py-2 text-sm font-medium transition-colors {{ !empty($link['primary']) ? 'border-blue-600 bg-blue-600 text-white hover:bg-blue-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                            <i data-lucide="{{ $link['icon'] ?? 'arrow-right' }}" class="mr-2 h-4 w-4"></i>
                            {{ $link['label'] ?? '' }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 divide-y divide-gray-100 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
            @foreach(($panel['cards'] ?? []) as $card)
                @php
                    $cardTone = (string) ($card['tone'] ?? 'slate');
                    $badgeClass = $toneClasses[$cardTone] ?? $toneClasses['slate'];
                @endphp
                <a href="{{ $card['url'] ?? '#' }}" class="block px-5 py-5 transition-colors hover:bg-gray-50 sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border {{ $badgeClass }}">
                            <i data-lucide="{{ $card['icon'] ?? 'circle' }}" class="h-4 w-4"></i>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-gray-500">{{ $card['label'] ?? '' }}</span>
                            <span class="mt-1 block text-xl font-semibold text-gray-900">{{ $card['value'] ?? '' }}</span>
                            <span class="mt-1 block text-sm leading-6 text-gray-600">{{ $card['detail'] ?? '' }}</span>
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
@endif
