@props(['admin', 'siteUrl'])
@php
    $isSuperAdmin = $admin->canManageProtectedWorkflows();
    $accountName = trim((string) $admin->name) ?: (string) $admin->username;
    $accountInitial = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($accountName, 0, 1));
    $projectGithubUrl = 'https://github.com/yaojingang/GEOFlow';
    $authorXUrl = 'https://x.com/yaojingang';
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
<div class="gf-modal-backdrop" data-gf-modal="qr" hidden>
    <section class="gf-modal gf-modal--community" role="dialog" aria-modal="true" aria-labelledby="gf-qr-title" aria-describedby="gf-qr-subtitle">
        <header class="gf-modal__header">
            <div>
                <span class="gf-modal__eyebrow">GEOFlow Community</span>
                <h2 id="gf-qr-title">{{ __('admin.ui_v3.qr_title') }}</h2>
                <p id="gf-qr-subtitle">{{ __('admin.ui_v3.qr_subtitle') }}</p>
            </div>
            <button class="gf-icon-button" type="button" data-dialog-close aria-label="{{ __('admin.ui_v3.close_dialog') }}"><i data-lucide="x"></i></button>
        </header>
        <div class="gf-modal__body gf-community-dialog">
            <figure class="gf-community-dialog__qr">
                <div class="gf-community-dialog__qr-frame">
                    <img
                        src="{{ asset('assets/images/yao-jingang-wechat.jpg') }}"
                        width="888"
                        height="1128"
                        alt="{{ __('admin.ui_v3.qr_image_alt') }}"
                    >
                </div>
                <figcaption><i data-lucide="scan-line" aria-hidden="true"></i>{{ __('admin.ui_v3.qr_scan_hint') }}</figcaption>
            </figure>
            <div class="gf-community-dialog__content">
                <div class="gf-community-dialog__intro">
                    <span>{{ __('admin.ui_v3.qr_author') }}</span>
                    <h3>{{ __('admin.ui_v3.qr_invitation_title') }}</h3>
                    <p>{{ __('admin.ui_v3.qr_invitation') }}</p>
                </div>
                <nav class="gf-community-links" aria-label="{{ __('admin.ui_v3.qr_resources') }}">
                    <p>{{ __('admin.ui_v3.qr_resources') }}</p>
                    <a href="{{ $projectGithubUrl }}" target="_blank" rel="noopener noreferrer">
                        <span class="gf-community-links__icon"><i data-lucide="git-fork" aria-hidden="true"></i></span>
                        <span><strong>GitHub</strong><small>{{ __('admin.ui_v3.qr_github_hint') }}</small></span>
                        <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                    </a>
                    <a href="{{ $authorXUrl }}" target="_blank" rel="noopener noreferrer">
                        <span class="gf-community-links__icon gf-community-links__icon--x" aria-hidden="true">X</span>
                        <span><strong>{{ __('admin.ui_v3.qr_x_label') }}</strong><small>{{ __('admin.ui_v3.qr_x_hint') }}</small></span>
                        <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                    </a>
                </nav>
            </div>
        </div>
        <footer class="gf-modal__footer gf-community-dialog__footer"><span><i data-lucide="message-circle-heart" aria-hidden="true"></i>{{ __('admin.ui_v3.qr_hint') }}</span><button class="gf-button" type="button" data-dialog-close>{{ __('admin.ui_v3.close_dialog') }}</button></footer>
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
