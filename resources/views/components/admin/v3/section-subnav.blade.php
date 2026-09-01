@props([
    'items' => [],
    'label',
    'name',
    'embedded' => false,
])

@php
    $tones = [
        'site' => 'bg-blue-600',
        'theme' => 'bg-slate-600',
        'forms' => 'bg-emerald-500',
        'users' => 'bg-cyan-500',
        'security' => 'bg-violet-500',
        'updates' => 'bg-amber-500',
        'models' => 'bg-blue-600',
        'prompts' => 'bg-emerald-500',
        'special' => 'bg-violet-500',
        'sources' => 'bg-cyan-500',
        'task-list' => 'bg-blue-600',
        'workers' => 'bg-emerald-500',
        'jobs' => 'bg-violet-500',
        'article-list' => 'bg-blue-600',
        'categories' => 'bg-emerald-500',
        'review' => 'bg-violet-500',
        'trash' => 'bg-rose-500',
        'knowledge-bases' => 'bg-orange-500',
        'keywords' => 'bg-blue-600',
        'titles' => 'bg-emerald-500',
        'images' => 'bg-violet-500',
        'authors' => 'bg-indigo-500',
        'url-import' => 'bg-cyan-500',
        'knowledge-current' => 'bg-blue-600',
        'knowledge-chunks' => 'bg-emerald-500',
        'knowledge-facts' => 'bg-violet-500',
    ];
@endphp

<nav
    class="{{ $embedded ? 'min-w-0 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden' : 'gf-context-nav' }}"
    data-section-navigation="{{ $name }}"
    @if($name === 'settings') data-settings-navigation @endif
    @if($name === 'ai-configurator') data-ai-configurator-navigation @endif
    @if($name === 'tasks') data-tasks-navigation @endif
    @if($name === 'articles') data-articles-navigation @endif
    @if($name === 'materials') data-materials-navigation @endif
    @if($name === 'distribution') data-distribution-navigation @endif
    aria-label="{{ $label }}"
>
    <div class="{{ $embedded ? 'flex min-w-max items-end gap-6' : 'gf-context-nav__inner' }}">
        @foreach ($items as $item)
            @php
                $href = \App\Support\AdminWeb::routePath($item['route'], $item['parameters'] ?? []);
                if (! empty($item['fragment'])) {
                    $href .= '#'.ltrim((string) $item['fragment'], '#');
                }
            @endphp
            <a
                href="{{ $href }}"
                data-section-navigation-item="{{ $item['key'] }}"
                @if($name === 'settings') data-settings-navigation-item="{{ $item['key'] }}" @endif
                @if($name === 'ai-configurator') data-ai-configurator-navigation-item="{{ $item['key'] }}" @endif
                @if($name === 'tasks') data-tasks-navigation-item="{{ $item['key'] }}" @endif
                @if($name === 'articles') data-articles-navigation-item="{{ $item['key'] }}" @endif
                @if($name === 'materials') data-materials-navigation-item="{{ $item['key'] }}" @endif
                @if($name === 'distribution') data-distribution-navigation-item="{{ $item['key'] }}" @endif
                @if($item['active']) aria-current="page" @endif
                class="relative inline-flex min-h-10 items-center gap-2 border-b-2 px-0.5 pb-2 text-sm font-semibold transition duration-[120ms] motion-reduce:transition-none active:scale-[.98] motion-reduce:active:scale-100 {{ $item['active'] ? 'border-blue-600 text-gray-950' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-800' }}"
            >
                @if(! empty($item['icon']))
                    <i
                        data-lucide="{{ $item['icon'] }}"
                        class="h-4 w-4 transition-colors duration-[120ms] {{ $item['active'] ? 'text-blue-600' : 'text-gray-500' }}"
                        data-section-navigation-icon
                        @if($name === 'distribution') data-distribution-navigation-icon @endif
                        aria-hidden="true"
                    ></i>
                @else
                    <span
                        class="h-2 w-2 rounded-full {{ $tones[$item['key']] ?? 'bg-slate-500' }}"
                        data-section-navigation-dot
                        @if($name === 'settings') data-settings-navigation-dot @endif
                        @if($name === 'ai-configurator') data-ai-configurator-navigation-dot @endif
                        @if($name === 'tasks') data-tasks-navigation-dot @endif
                        @if($name === 'articles') data-articles-navigation-dot @endif
                        @if($name === 'materials') data-materials-navigation-dot @endif
                    ></span>
                @endif
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
