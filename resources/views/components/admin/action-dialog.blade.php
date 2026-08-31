@php
    $initialNotice = session('admin_action_notice');
    if (! is_array($initialNotice) && session('message')) {
        $initialNotice = [
            'tone' => 'success',
            'title' => __('admin.action_dialog.success_title'),
            'message' => (string) session('message'),
        ];
    }

    if (is_array($initialNotice)) {
        $actionUrl = trim((string) ($initialNotice['action_url'] ?? ''));
        if ($actionUrl === ''
            || ! str_starts_with($actionUrl, '/')
            || str_starts_with($actionUrl, '//')
            || str_contains($actionUrl, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $actionUrl) === 1) {
            unset($initialNotice['action_url'], $initialNotice['action_label']);
        }
    }
@endphp

<div class="admin-action-layer" data-admin-action-layer data-cancel-label="{{ __('admin.action_dialog.cancel') }}" data-close-label="{{ __('admin.action_dialog.close') }}" data-confirm-label="{{ __('admin.action_dialog.confirm') }}" data-success-title="{{ __('admin.action_dialog.success_title') }}" data-info-title="{{ __('admin.action_dialog.info_title') }}" data-error-title="{{ __('admin.action_dialog.error_title') }}" hidden>
    <dialog
        class="admin-action-dialog"
        data-admin-action-dialog
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="admin-action-dialog-title"
        aria-describedby="admin-action-dialog-message admin-action-dialog-guidance"
    >
        <div class="admin-action-dialog__surface">
            <div class="admin-action-dialog__content">
                <span class="admin-action-dialog__icon" data-admin-action-icon aria-hidden="true">
                    <i data-lucide="circle-help" aria-hidden="true"></i>
                </span>
                <div class="admin-action-dialog__copy">
                    <h2 id="admin-action-dialog-title" class="admin-action-dialog__title" data-admin-action-title tabindex="-1"></h2>
                    <p id="admin-action-dialog-message" class="admin-action-dialog__message" data-admin-action-message></p>
                    <p id="admin-action-dialog-guidance" class="admin-action-dialog__guidance" data-admin-action-guidance hidden></p>
                    <div class="admin-action-dialog__field" data-admin-action-field hidden>
                        <div data-admin-action-fields></div>
                    </div>
                </div>
            </div>
            <div class="admin-action-dialog__actions">
                <button type="button" class="admin-action-button admin-action-button--secondary" data-admin-action-cancel></button>
                <button type="button" class="admin-action-button admin-action-button--primary" data-admin-action-confirm></button>
            </div>
        </div>
    </dialog>
</div>

<div class="admin-action-notice-region" data-admin-action-notice-region aria-live="polite" aria-atomic="true">
    <section class="admin-action-notice" data-admin-action-notice role="status" hidden>
        <span class="admin-action-notice__icon" data-admin-notice-icon aria-hidden="true">
            <i data-lucide="circle-check" aria-hidden="true"></i>
        </span>
        <div class="admin-action-notice__copy">
            <strong data-admin-notice-title></strong>
            <p data-admin-notice-message></p>
            <p data-admin-notice-guidance hidden></p>
            <a data-admin-notice-action hidden></a>
        </div>
        <button type="button" class="admin-action-notice__close" data-admin-notice-close aria-label="{{ __('admin.action_dialog.close') }}">
            <i data-lucide="x" aria-hidden="true"></i>
        </button>
    </section>
</div>

@if (is_array($initialNotice))
    <script type="application/json" data-admin-action-initial-notice>@json($initialNotice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
@endif
