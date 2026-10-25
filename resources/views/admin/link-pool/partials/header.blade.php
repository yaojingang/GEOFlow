@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('assets/css/admin-link-pool.css') }}?v=20260930-2">
    @endpush
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const tabs = document.querySelector('[data-link-pool-tabs]');
                const active = tabs?.querySelector('[aria-current="page"]');
                if (!active || tabs.scrollWidth <= tabs.clientWidth) return;
                const tabRect = active.getBoundingClientRect();
                const navRect = tabs.getBoundingClientRect();
                tabs.scrollLeft += tabRect.left - navRect.left - (navRect.width - tabRect.width) / 2;
            });
        </script>
    @endpush
@endonce
<div class="mx-auto max-w-7xl space-y-5 px-4 pb-4 sm:px-0">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            @if($canWrite)
                <a href="{{ \App\Support\AdminWeb::routePath('admin.distribution.index') }}" data-link-pool-distribution-return class="inline-flex min-h-10 items-center gap-2 rounded-md text-sm font-medium text-gray-600 transition-colors hover:text-blue-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-600">
                    <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
                    {{ __('admin.distribution.page_heading') }}
                </a>
            @else
                <p class="text-xs font-semibold tracking-wide text-blue-700">GEOFlow / CONTENT OPERATIONS</p>
            @endif
            <h1 class="mt-1 text-2xl font-bold text-gray-900">内链投放</h1>
            <p class="mt-1 text-sm text-gray-600">管理文章推荐入口、投放活动和实际效果</p>
        </div>
        @if($canWrite)
            <form method="POST" action="{{ route('admin.link-pool.emergency') }}" data-admin-confirm-form data-admin-confirm-tone="{{ $emergencyDisabled ? 'success' : 'danger' }}" data-admin-confirm-title="{{ $emergencyDisabled ? '解除全局停用' : '紧急停用全部站点' }}" data-admin-confirm-message="此操作将立即影响全部站点的文章推荐区。" data-admin-confirm-label="{{ $emergencyDisabled ? '解除停用' : '确认停用' }}">
                @csrf
                <input type="hidden" name="disabled" value="{{ $emergencyDisabled ? '0' : '1' }}">
                <button class="min-h-10 rounded-lg border px-4 text-sm font-semibold {{ $emergencyDisabled ? 'border-green-300 bg-green-50 text-green-800' : 'border-red-200 bg-white text-red-700 hover:bg-red-50' }}">
                    {{ $emergencyDisabled ? '解除全局停用' : '紧急停用全部站点' }}
                </button>
            </form>
        @endif
    </div>
    @if($emergencyDisabled)
        <p role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">全部站点推荐已停用。历史记录和预览仍可查看。</p>
    @endif
    @if(session('link_pool_message'))
        <p role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('link_pool_message') }}</p>
    @endif
    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200" aria-label="内链投放页面" data-link-pool-tabs>
        @foreach([
            ['admin.link-pool.overview','总览'],['admin.link-pool.targets','链接库'],['admin.link-pool.campaigns.index','投放活动'],['admin.link-pool.dashboard','效果看板'],['admin.link-pool.settings','站点设置']
        ] as [$routeName,$label])
            @php($activeTab = request()->routeIs($routeName) || str_starts_with((string) request()->route()?->getName(), str_replace('.index','',$routeName).'.') || ($routeName === 'admin.link-pool.targets' && request()->routeIs('admin.link-pool.import.*')))
            <a href="{{ route($routeName, isset($siteKey) && $siteKey ? ['site' => $siteKey] : []) }}" @if($activeTab) aria-current="page" @endif class="shrink-0 border-b-2 px-4 py-3 text-sm font-medium {{ $activeTab ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-600 hover:text-gray-900' }}">{{ $label }}</a>
        @endforeach
    </nav>
    <form method="GET" action="{{ request()->routeIs('admin.link-pool.import.*') ? route('admin.link-pool.import.form') : url()->current() }}" class="link-pool-site-context">
        <div class="link-pool-site-context__heading">
            <span class="link-pool-site-context__icon" aria-hidden="true"><i data-lucide="globe-2" class="h-5 w-5"></i></span>
            <div>
                <label for="link-pool-site" class="link-pool-site-context__label">来源站点</label>
                <p class="link-pool-site-context__description">选择当前查看的站点范围</p>
            </div>
        </div>
        <select id="link-pool-site" name="site" onchange="this.form.submit()" class="link-pool-site-context__select">
            <option value="">全部站点</option>
            <option value="primary" @selected(($siteKey ?? null) === 'primary')>主站</option>
            @foreach($profiles as $profile)
                <option value="hosted:{{ $profile->id }}" @selected(($siteKey ?? null) === 'hosted:'.$profile->id)>{{ $profile->channel?->name ?? $profile->hostname }} · {{ $profile->hostname }} · {{ ['online'=>'在线','maintenance'=>'维护中','archived'=>'已归档'][$profile->serving_status] ?? '暂停公开' }} / {{ $profile->indexing_status === 'index' ? '允许收录' : '暂停收录' }}</option>
            @endforeach
        </select>
        @if(isset($siteKey) && $siteKey)
            @php($siteSettings = app(\App\Services\Site\LinkPoolSiteService::class)->settings($siteKey))
            <span class="link-pool-site-context__status {{ $siteSettings['enabled'] ? 'link-pool-site-context__status--active' : 'link-pool-site-context__status--inactive' }}">{{ $siteSettings['enabled'] ? '推荐已开启' : '推荐尚未开启' }}</span>
        @endif
        <p class="link-pool-site-context__hint">
            {{ request()->routeIs('admin.link-pool.import.*') ? '导入目标站点按 CSV 中的文章或外部网页 URL 自动识别。' : '筛选本页的活动与统计数据。' }}
            @unless($canWrite) 只读 · 写操作需要超级管理员权限 @endunless
        </p>
    </form>
</div>
