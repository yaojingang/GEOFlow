@extends('admin.layouts.app')

@section('content')
<div class="gf-page-header">
    <div class="gf-page-header__copy">
        <span class="gf-eyebrow">{{ __('admin.nav.site_settings') }}</span>
        <h1>{{ __('admin.account.page_title') }}</h1>
        <p>{{ __('admin.account.page_description') }}</p>
    </div>
</div>

<div class="gf-account-page">
    <section class="gf-settings-panel">
        <header class="gf-settings-panel__header"><div><h2>{{ __('admin.account.profile_title') }}</h2><p>{{ __('admin.account.profile_description') }}</p></div></header>
        <form method="POST" action="{{ \App\Support\AdminWeb::routePath('admin.account.profile.update') }}" data-admin-unsaved>
            @csrf
            @method('PUT')
            <input type="hidden" name="profile_version" value="{{ \App\Support\AdminAccountProfileVersion::for($admin) }}">
            <div class="gf-form-section">
                <div class="gf-form-section__intro"><h3>{{ __('admin.account.profile_title') }}</h3><p>{{ __('admin.account.profile_description') }}</p></div>
                <div class="gf-form-section__fields">
                    <label class="gf-field"><span>{{ __('admin.account.username') }}</span><input type="text" value="{{ $admin->username }}" disabled><small>{{ __('admin.account.role') }} · {{ $admin->isSuperAdmin() ? __('admin.header.super_admin') : __('admin.header.admin') }}</small></label>
                    <label class="gf-field"><span>{{ __('admin.account.display_name') }}</span><input type="text" name="display_name" value="{{ old('display_name', $admin->display_name) }}" maxlength="100" autocomplete="name"></label>
                    <label class="gf-field"><span>{{ __('admin.account.email') }}</span><input type="email" name="email" value="{{ old('email', $admin->email) }}" maxlength="100" autocomplete="email"></label>
                </div>
            </div>
            <footer class="gf-settings-actions"><span>{{ __('admin.account.last_login') }} · {{ $admin->last_login?->format('Y-m-d H:i') ?: __('admin.account.never') }}</span><button class="gf-button gf-button--primary" type="submit"><i data-lucide="save"></i>{{ __('admin.account.save_profile') }}</button></footer>
        </form>
    </section>

    <aside class="gf-account-page__aside">
        <section class="gf-settings-panel">
            <header class="gf-settings-panel__header"><div><h2>{{ __('admin.account.permissions_title') }}</h2><p>{{ __('admin.account.role') }} · {{ $admin->isSuperAdmin() ? __('admin.header.super_admin') : __('admin.header.admin') }}</p></div></header>
            <ul class="gf-permission-list">
                <li><span><i data-lucide="circle-check"></i></span>{{ __('admin.account.permission_business') }}</li>
                <li><span><i data-lucide="circle-check"></i></span>{{ __('admin.account.permission_settings') }}</li>
                @if ($admin->isSuperAdmin())<li><span><i data-lucide="circle-check"></i></span>{{ __('admin.account.permission_protected') }}</li>@endif
            </ul>
        </section>

        <section class="gf-settings-panel">
            <header class="gf-settings-panel__header"><div><h2>{{ __('admin.account.password_title') }}</h2><p>{{ __('admin.account.password_description') }}</p></div></header>
            <form method="POST" action="{{ \App\Support\AdminWeb::routePath('admin.account.password.update') }}" data-admin-unsaved>
                @csrf
                @method('PUT')
                <div class="gf-password-fields">
                    <label class="gf-field"><span>{{ __('admin.account.current_password') }}</span><input type="password" name="current_password" required autocomplete="current-password"></label>
                    <label class="gf-field"><span>{{ __('admin.account.new_password') }}</span><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
                    <label class="gf-field"><span>{{ __('admin.account.confirm_password') }}</span><input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
                </div>
                <footer class="gf-settings-actions"><span>{{ __('admin.account.security_hint') }}</span><button class="gf-button" type="submit"><i data-lucide="key-round"></i>{{ __('admin.account.update_password') }}</button></footer>
            </form>
        </section>
    </aside>
</div>
@endsection
