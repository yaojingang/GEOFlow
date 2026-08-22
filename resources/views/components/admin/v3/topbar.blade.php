@props(['admin', 'current' => [], 'updateNotification' => []])
@php
    $updateState = is_array($updateNotification['state'] ?? null) ? $updateNotification['state'] : [];
    $updateLinks = is_array($updateNotification['links'] ?? null) ? $updateNotification['links'] : [];
    $hasUpdate = !empty($updateState['is_update_available']);
    $isSuperAdmin = $admin->canManageProtectedWorkflows();
@endphp
<header class="gf-topbar">
    <button class="gf-icon-button gf-mobile-only" type="button" aria-label="{{ __('admin.ui_v3.open_sidebar') }}" data-sidebar-open><i data-lucide="menu"></i></button>
    <div class="gf-topbar__context"><i data-lucide="{{ $current['icon'] ?? 'layout-dashboard' }}"></i><span>{{ $current['label'] ?? __('admin.nav.data_center') }}</span></div>
    <div class="gf-topbar__actions">
        <div class="gf-popover-wrap">
            <button class="gf-icon-button gf-icon-button--round" type="button" aria-label="{{ __('admin.header.notifications.label') }}" data-popover-button="notifications"><i data-lucide="bell"></i>@if($hasUpdate)<span class="gf-notification-dot"></span>@endif</button>
            <div class="gf-popover" data-popover="notifications" hidden>
                <strong>{{ __('admin.header.notifications.title') }}</strong>
                <p>{{ $hasUpdate ? __('admin.header.notifications.update_available', ['version' => (string) ($updateState['latest_version'] ?? '')]) : __('admin.header.notifications.no_update_desc') }}</p>
                <div class="gf-popover__actions">
                    @if ($hasUpdate && $isSuperAdmin && config('geoflow.update_center_enabled', true))<a class="gf-button gf-button--primary gf-button--small" href="{{ \App\Support\AdminWeb::routePath('admin.system-updates.index') }}">{{ __('admin.header.notifications.open_update_center') }}</a>@endif
                    <a class="gf-button gf-button--small" href="{{ $updateLinks['github'] ?? 'https://github.com/yaojingang/GEOFlow' }}" target="_blank" rel="noopener noreferrer">GitHub</a>
                </div>
            </div>
        </div>
        <label class="gf-language">
            <i data-lucide="languages"></i><span class="sr-only">{{ __('admin.header.language') }}</span>
            <select data-locale-select aria-label="{{ __('admin.header.language') }}">
                @foreach (\App\Support\AdminWeb::supportedLocales() as $localeCode => $localeLabel)
                    <option value="{{ \App\Support\AdminWeb::routePath('admin.locale.switch', ['locale' => $localeCode]) }}" @selected(app()->getLocale() === $localeCode)>{{ $localeLabel }}</option>
                @endforeach
            </select>
        </label>
        <div class="gf-popover-wrap">
            <button class="gf-user-button" type="button" aria-label="{{ __('admin.ui_v3.open_user_menu') }}" data-popover-button="user"><span class="gf-user-avatar"><i data-lucide="user"></i></span><i data-lucide="chevron-down"></i></button>
            <div class="gf-popover gf-popover--user" data-popover="user" hidden>
                <strong>{{ $admin->name }}</strong><span>{{ $isSuperAdmin ? __('admin.header.super_admin') : __('admin.header.admin') }}</span>
                <a href="{{ \App\Support\AdminWeb::routePath('admin.account.show') }}">{{ __('admin.account.page_title') }}</a>
                <a href="{{ \App\Support\AdminWeb::routePath('admin.site-settings.index') }}">{{ __('admin.nav.site_settings') }}</a>
                <form method="POST" action="{{ \App\Support\AdminWeb::routePath('admin.logout') }}" data-no-unsaved>@csrf<button type="submit" class="gf-popover__danger">{{ __('admin.button.logout') }}</button></form>
            </div>
        </div>
    </div>
</header>
