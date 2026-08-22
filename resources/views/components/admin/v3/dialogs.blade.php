@props(['admin', 'siteUrl'])
@php
    $isSuperAdmin = $admin->canManageProtectedWorkflows();
    $accountName = trim((string) $admin->name) ?: (string) $admin->username;
    $accountInitial = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($accountName, 0, 1));
@endphp
<div class="gf-modal-backdrop" data-gf-modal="account" hidden>
    <section class="gf-modal" role="dialog" aria-modal="true" aria-labelledby="gf-account-title">
        <header class="gf-modal__header"><div><h2 id="gf-account-title">{{ __('admin.account.page_title') }}</h2><p>{{ __('admin.account.dialog_subtitle') }}</p></div><button class="gf-icon-button" type="button" data-dialog-close aria-label="{{ __('admin.ui_v3.close_dialog') }}"><i data-lucide="x"></i></button></header>
        <div class="gf-modal__body">
            <div class="gf-account-summary"><span class="gf-account-avatar gf-account-avatar--large">{{ $accountInitial }}</span><div><strong>{{ $accountName }}</strong><span>{{ $admin->username }}</span></div></div>
            <dl class="gf-facts">
                <div><dt>{{ __('admin.account.role') }}</dt><dd>{{ $isSuperAdmin ? __('admin.header.super_admin') : __('admin.header.admin') }}</dd></div>
                <div><dt>{{ __('admin.account.status') }}</dt><dd>{{ __('admin.account.status_active') }}</dd></div>
                <div><dt>{{ __('admin.account.last_login') }}</dt><dd>{{ $admin->last_login?->format('Y-m-d H:i') ?: __('admin.account.never') }}</dd></div>
            </dl>
        </div>
        <footer class="gf-modal__footer"><span>{{ __('admin.account.security_hint') }}</span><a class="gf-button gf-button--primary" href="{{ \App\Support\AdminWeb::routePath('admin.account.show') }}">{{ __('admin.account.manage') }}</a></footer>
    </section>
</div>
<div class="gf-modal-backdrop" data-gf-modal="qr" hidden data-qr-value="{{ $siteUrl }}">
    <section class="gf-modal" role="dialog" aria-modal="true" aria-labelledby="gf-qr-title">
        <header class="gf-modal__header"><div><h2 id="gf-qr-title">{{ __('admin.ui_v3.qr_title') }}</h2><p>{{ __('admin.ui_v3.qr_subtitle') }}</p></div><button class="gf-icon-button" type="button" data-dialog-close aria-label="{{ __('admin.ui_v3.close_dialog') }}"><i data-lucide="x"></i></button></header>
        <div class="gf-modal__body"><div class="gf-qr-placeholder"><span><canvas width="132" height="132" data-qr-canvas></canvas></span><div><strong>GEOFlow</strong><p>{{ $siteUrl }}</p><button class="gf-text-button" type="button" data-copy-value="{{ $siteUrl }}"><i data-lucide="copy"></i>{{ __('admin.ui_v3.copy_link') }}</button></div></div></div>
        <footer class="gf-modal__footer"><span>{{ __('admin.ui_v3.qr_hint') }}</span><button class="gf-button" type="button" data-dialog-close>{{ __('admin.ui_v3.close_dialog') }}</button></footer>
    </section>
</div>
<div class="gf-modal-backdrop" data-gf-modal="quick-settings" hidden>
    <section class="gf-modal" role="dialog" aria-modal="true" aria-labelledby="gf-settings-title">
        <header class="gf-modal__header"><div><h2 id="gf-settings-title">{{ __('admin.ui_v3.quick_settings') }}</h2><p>{{ __('admin.ui_v3.quick_settings_subtitle') }}</p></div><button class="gf-icon-button" type="button" data-dialog-close aria-label="{{ __('admin.ui_v3.close_dialog') }}"><i data-lucide="x"></i></button></header>
        <div class="gf-modal__body"><div class="gf-shortcut-list">
            <a href="{{ \App\Support\AdminWeb::routePath('admin.site-settings.index') }}"><i data-lucide="settings"></i><span><strong>{{ __('admin.nav.site_settings') }}</strong><small>{{ __('admin.ui_v3.site_settings_hint') }}</small></span><i data-lucide="chevron-right"></i></a>
            <a href="{{ \App\Support\AdminWeb::routePath('admin.account.show') }}"><i data-lucide="user-round-cog"></i><span><strong>{{ __('admin.account.page_title') }}</strong><small>{{ __('admin.ui_v3.account_settings_hint') }}</small></span><i data-lucide="chevron-right"></i></a>
            @if ($isSuperAdmin)
                <a href="{{ \App\Support\AdminWeb::routePath('admin.admin-users.index') }}"><i data-lucide="users"></i><span><strong>{{ __('admin.nav.admin_users') }}</strong><small>{{ __('admin.ui_v3.user_settings_hint') }}</small></span><i data-lucide="chevron-right"></i></a>
                <a href="{{ \App\Support\AdminWeb::routePath('admin.admin-activity-logs') }}"><i data-lucide="clipboard-list"></i><span><strong>{{ __('admin.nav.activity_logs') }}</strong><small>{{ __('admin.ui_v3.audit_settings_hint') }}</small></span><i data-lucide="chevron-right"></i></a>
            @endif
        </div></div>
        <footer class="gf-modal__footer"><span>{{ __('admin.ui_v3.permission_hint') }}</span><button class="gf-button" type="button" data-dialog-close>{{ __('admin.ui_v3.close_dialog') }}</button></footer>
    </section>
</div>
