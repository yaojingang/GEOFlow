@props(['admin', 'navigation' => [], 'current' => []])
@php
    $activeKey = (string) ($current['key'] ?? 'dashboard');
    $accountName = trim((string) $admin->name) ?: (string) $admin->username;
    $accountInitial = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($accountName, 0, 1));
@endphp
<aside class="gf-sidebar" aria-label="{{ __('admin.ui_v3.sidebar_label') }}">
    <div
        class="gf-sidebar__resize-handle"
        role="separator"
        tabindex="0"
        aria-label="{{ __('admin.ui_v3.resize_sidebar') }}"
        aria-orientation="vertical"
        aria-valuemin="224"
        aria-valuemax="384"
        aria-valuenow="256"
        data-sidebar-resize
    ></div>
    <div class="gf-sidebar__brand">
        <a class="gf-wordmark" href="{{ \App\Support\AdminWeb::routePath('admin.dashboard') }}">GEOFlow</a>
        <button class="gf-icon-button" type="button" aria-label="{{ __('admin.ui_v3.collapse_sidebar') }}" aria-expanded="true" data-sidebar-collapse><i data-lucide="panel-left-close"></i></button>
        <button class="gf-icon-button gf-mobile-only" type="button" aria-label="{{ __('admin.ui_v3.close_sidebar') }}" data-sidebar-close><i data-lucide="x"></i></button>
    </div>
    <nav class="gf-sidebar__nav" aria-label="{{ __('admin.ui_v3.primary_navigation') }}">
        <div class="gf-sidebar__primary">
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
        </div>
        <section
            class="gf-sidebar__recent"
            data-sidebar-recent
            data-recent-url="{{ \App\Support\AdminWeb::routePath('admin.recent.index') }}"
            data-recent-empty="{{ __('admin.ui_v3.recent_empty') }}"
            data-recent-loading="{{ __('admin.ui_v3.recent_loading') }}"
            data-recent-load-failed="{{ __('admin.ui_v3.recent_load_failed') }}"
            data-recent-retry="{{ __('admin.ui_v3.recent_retry') }}"
            data-archive-label="{{ __('admin.ai_workspace.archive') }}"
        >
            <div class="gf-sidebar__recent-head">
                <h2 class="gf-sidebar__heading">
                    <button type="button" aria-expanded="true" aria-controls="gf-sidebar-recent-body" data-sidebar-recent-toggle>
                        <span>{{ __('admin.ui_v3.recent') }}</span><i data-lucide="chevron-down" aria-hidden="true"></i>
                    </button>
                </h2>
                <div class="gf-sidebar__recent-actions">
                    @if (isset($recentAction))
                        {{ $recentAction }}
                    @else
                        <a class="gf-icon-button gf-icon-button--small" href="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace') }}" aria-label="{{ __('admin.ai_workspace.new_conversation') }}"><i data-lucide="square-pen"></i></a>
                    @endif
                </div>
            </div>
            <div class="gf-sidebar__recent-body" id="gf-sidebar-recent-body" data-sidebar-recent-body>
                <div class="gf-sidebar__recent-scroll" data-sidebar-recent-scroll>
                    <div class="gf-sidebar__items gf-ai-history__list" data-sidebar-recent-list data-ai-history-list aria-live="polite"></div>
                    <p class="gf-sidebar__empty" data-sidebar-recent-status hidden>{{ __('admin.ui_v3.recent_empty') }}</p>
                    <button class="gf-sidebar__recent-retry" type="button" data-sidebar-recent-retry hidden>{{ __('admin.ui_v3.recent_retry') }}</button>
                </div>
            </div>
        </section>
    </nav>
    <div class="gf-sidebar__account-bar" aria-label="{{ __('admin.ui_v3.account_shortcuts') }}">
        <button class="gf-sidebar__account" type="button" data-dialog-open="account" aria-label="{{ __('admin.ui_v3.open_account', ['name' => $accountName]) }}">
            <span class="gf-account-avatar">{{ $accountInitial }}</span><span class="gf-account-name">{{ $accountName }}</span><i data-lucide="chevron-right"></i>
        </button>
        <button class="gf-icon-button gf-sidebar__utility" type="button" data-dialog-open="qr" aria-label="{{ __('admin.ui_v3.open_qr') }}" title="{{ __('admin.ui_v3.open_qr') }}"><i data-lucide="qr-code"></i></button>
        <button class="gf-icon-button gf-sidebar__utility" type="button" data-dialog-open="quick-settings" aria-label="{{ __('admin.ui_v3.open_settings') }}"><i data-lucide="settings"></i></button>
    </div>
</aside>
