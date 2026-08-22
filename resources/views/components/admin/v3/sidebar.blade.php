@props(['admin', 'navigation' => [], 'current' => [], 'recent' => []])
@php
    $activeKey = (string) ($current['key'] ?? 'dashboard');
    $accountName = trim((string) $admin->name) ?: (string) $admin->username;
    $accountInitial = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($accountName, 0, 1));
@endphp
<aside class="gf-sidebar" aria-label="{{ __('admin.ui_v3.sidebar_label') }}">
    <div class="gf-sidebar__brand">
        <a class="gf-wordmark" href="{{ \App\Support\AdminWeb::routePath('admin.dashboard') }}">GEOFlow</a>
        <button class="gf-icon-button" type="button" aria-label="{{ __('admin.ui_v3.collapse_sidebar') }}" aria-expanded="true" data-sidebar-collapse><i data-lucide="panel-left-close"></i></button>
        <button class="gf-icon-button gf-mobile-only" type="button" aria-label="{{ __('admin.ui_v3.close_sidebar') }}" data-sidebar-close><i data-lucide="x"></i></button>
    </div>
    <nav class="gf-sidebar__nav" aria-label="{{ __('admin.ui_v3.primary_navigation') }}">
        @foreach ($navigation as $group)
            <section class="gf-sidebar__group">
                @if (!empty($group['label']))<h2 class="gf-sidebar__heading">{{ $group['label'] }}</h2>@endif
                <div class="gf-sidebar__items">
                    @foreach ($group['items'] as $item)
                        <a class="gf-sidebar__link" href="{{ \App\Support\AdminWeb::routePath($item['route']) }}" title="{{ $item['label'] }}" @if($item['key'] === $activeKey) aria-current="page" @endif>
                            <i data-lucide="{{ $item['icon'] }}"></i><span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
        <section class="gf-sidebar__recent">
            <div class="gf-sidebar__recent-head"><h2 class="gf-sidebar__heading">{{ __('admin.ui_v3.recent') }}</h2><span class="gf-icon-button gf-icon-button--small" aria-hidden="true"><i data-lucide="sliders-horizontal"></i></span></div>
            @if ($recent !== [])
                <div class="gf-sidebar__items">
                    @foreach ($recent as $entry)
                        <a class="gf-sidebar__link" href="{{ \App\Support\AdminWeb::routePath($entry['route']) }}"><span class="gf-recent-dot gf-recent-dot--{{ $entry['tone'] }}"></span><span>{{ $entry['label'] }}</span></a>
                    @endforeach
                </div>
            @else
                <p class="gf-sidebar__empty">{{ __('admin.ui_v3.recent_empty') }}</p>
            @endif
        </section>
    </nav>
    <div class="gf-sidebar__account-bar" aria-label="{{ __('admin.ui_v3.account_shortcuts') }}">
        <button class="gf-sidebar__account" type="button" data-dialog-open="account" aria-label="{{ __('admin.ui_v3.open_account', ['name' => $accountName]) }}">
            <span class="gf-account-avatar">{{ $accountInitial }}</span><span class="gf-account-name">{{ $accountName }}</span><i data-lucide="chevron-right"></i>
        </button>
        <button class="gf-icon-button gf-sidebar__utility" type="button" data-dialog-open="qr" aria-label="{{ __('admin.ui_v3.open_qr') }}"><i data-lucide="qr-code"></i></button>
        <button class="gf-icon-button gf-sidebar__utility" type="button" data-dialog-open="quick-settings" aria-label="{{ __('admin.ui_v3.open_settings') }}"><i data-lucide="settings"></i></button>
    </div>
</aside>
