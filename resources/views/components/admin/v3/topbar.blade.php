@props([
    'admin',
    'updateNotification' => [],
    'pageTitle' => null,
    'pageIcon' => null,
])
@php
    $updateState = is_array($updateNotification['state'] ?? null) ? $updateNotification['state'] : [];
    $updateLinks = is_array($updateNotification['links'] ?? null) ? $updateNotification['links'] : [];
    $hasUpdate = !empty($updateState['is_update_available']);
    $isSuperAdmin = $admin->canManageProtectedWorkflows();
    $supportedLocales = \App\Support\AdminWeb::supportedLocales();
    $currentLocale = app()->getLocale();
    $currentLocaleLabel = $supportedLocales[$currentLocale] ?? reset($supportedLocales);
    $localeAbbreviations = [
        'zh_CN' => 'ZH',
        'en' => 'EN',
        'ja' => 'JA',
        'es' => 'ES',
        'ru' => 'RU',
        'pt_BR' => 'PT',
    ];
    $pageTitle = is_string($pageTitle) ? trim($pageTitle) : '';
    $pageIcon = is_string($pageIcon) ? trim($pageIcon) : '';
@endphp
<header class="gf-topbar">
    <button class="gf-icon-button gf-mobile-only" type="button" aria-label="{{ __('admin.ui_v3.open_sidebar') }}" data-sidebar-open><i data-lucide="menu"></i></button>
    @if ($pageTitle !== '')
        <div class="gf-topbar__identity" data-gf-topbar-identity @if($pageIcon !== '') data-page-icon="{{ $pageIcon }}" @endif>
            @if ($pageIcon !== '')
                <span class="gf-topbar__identity-icon" aria-hidden="true"><i data-lucide="{{ $pageIcon }}"></i></span>
            @endif
            <span class="gf-topbar__title">{{ $pageTitle }}</span>
        </div>
    @endif
    <div class="gf-topbar__actions">
        <button class="gf-button gf-button--small gf-pwa-install" type="button" data-pwa-install hidden aria-label="{{ __('admin.ui_v3.install_workbench_label') }}"><i data-lucide="app-window"></i><span>{{ __('admin.ui_v3.install_workbench') }}</span></button>
        <div class="gf-popover-wrap">
            @if ($isSuperAdmin && config('geoflow.update_center_enabled', true))
                <a
                    class="gf-icon-button gf-icon-button--round"
                    href="{{ \App\Support\AdminWeb::routePath('admin.system-updates.index') }}"
                    aria-label="{{ __('admin.header.notifications.open_update_center') }}"
                    title="{{ __('admin.header.notifications.open_update_center') }}"
                    data-system-update-link
                ><i data-lucide="bell"></i>@if($hasUpdate)<span class="gf-notification-dot" data-update-indicator></span>@endif</a>
            @else
                <button class="gf-icon-button gf-icon-button--round" type="button" aria-label="{{ __('admin.header.notifications.label') }}" data-popover-button="notifications"><i data-lucide="bell"></i>@if($hasUpdate)<span class="gf-notification-dot" data-update-indicator></span>@endif</button>
                <div class="gf-popover" data-popover="notifications" hidden>
                    <strong>{{ __('admin.header.notifications.title') }}</strong>
                    <p>{{ $hasUpdate ? __('admin.header.notifications.update_available', ['version' => (string) ($updateState['latest_version'] ?? '')]) : __('admin.header.notifications.no_update_desc') }}</p>
                    <div class="gf-popover__actions">
                        <a class="gf-button gf-button--small" href="{{ $updateLinks['github'] ?? 'https://github.com/yaojingang/GEOFlow' }}" target="_blank" rel="noopener noreferrer">GitHub</a>
                    </div>
                </div>
            @endif
        </div>
        <div class="gf-popover-wrap gf-language-wrap">
            <button
                class="gf-language"
                type="button"
                aria-label="{{ __('admin.login.language_label') }}: {{ $currentLocaleLabel }}"
                data-popover-button="language"
            >
                <i data-lucide="languages"></i>
                <span class="gf-language__label" lang="{{ str_replace('_', '-', $currentLocale) }}">{{ $currentLocaleLabel }}</span>
                <i class="gf-language__chevron" data-lucide="chevron-down"></i>
            </button>
            <div class="gf-popover gf-popover--language" data-popover="language" hidden>
                <span class="gf-language-menu__label" id="gf-language-menu-label">{{ __('admin.login.language_label') }}</span>
                <div class="gf-language-list" aria-labelledby="gf-language-menu-label">
                    @foreach ($supportedLocales as $localeCode => $localeLabel)
                        @php($isCurrentLocale = $currentLocale === $localeCode)
                        <a
                            @class(['gf-language-option', 'is-current' => $isCurrentLocale])
                            href="{{ \App\Support\AdminWeb::routePath('admin.locale.switch', ['locale' => $localeCode]) }}"
                            lang="{{ str_replace('_', '-', $localeCode) }}"
                            hreflang="{{ str_replace('_', '-', $localeCode) }}"
                            aria-label="{{ __('admin.header.language_switch_to', ['language' => $localeLabel]) }}"
                            @if($isCurrentLocale) aria-current="true" @endif
                        >
                            <span class="gf-language-option__code">{{ $localeAbbreviations[$localeCode] ?? strtoupper(substr($localeCode, 0, 2)) }}</span>
                            <span>{{ $localeLabel }}</span>
                            <i data-lucide="check"></i>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
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
