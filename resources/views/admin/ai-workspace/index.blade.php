@extends('admin.layouts.app')

@section('sidebar-recent-action')
    <button class="gf-icon-button gf-icon-button--small" type="button" data-ai-new aria-label="{{ __('admin.ai_workspace.new_conversation') }}"><i data-lucide="square-pen"></i></button>
@endsection

@section('sidebar-recent-content')
    <div class="gf-sidebar__items gf-ai-history__list" data-ai-history-list aria-live="polite">
        <p class="gf-sidebar__empty">{{ __('admin.ai_workspace.history_empty') }}</p>
    </div>
@endsection

@section('content')
<div class="gf-ai-workspace" data-ai-workspace
    data-runtime-enabled="{{ $runtimeEnabled ? 'true' : 'false' }}"
    data-runtime-unavailable-message="{{ $runtimeEnabled ? '' : ($runtimeConfigured ? $runtimeUnavailableReason : __('admin.ai_workspace.runtime_disabled')) }}"
    data-ai-configurator-url="{{ \App\Support\AdminWeb::routePath('admin.ai.configurator') }}"
    data-admin-id="{{ (int) auth('admin')->id() }}"
    data-conversations-url="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.conversations.index') }}"
    data-conversation-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.conversations.show', ['conversation' => '__ID__']) }}"
    data-message-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.messages.store', ['conversation' => '__ID__']) }}"
    data-run-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.runs.show', ['run' => '__ID__']) }}"
    data-plan-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.runs.plan.update', ['run' => '__ID__']) }}"
    data-cancel-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.runs.cancel', ['run' => '__ID__']) }}"
    data-approval-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.approvals.approve', ['approval' => '__ID__']) }}"
    data-reject-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.approvals.reject', ['approval' => '__ID__']) }}"
    data-retry-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.steps.retry', ['step' => '__ID__']) }}">
    <script type="application/json" data-ai-labels>{!! \Illuminate\Support\Js::encode($aiWorkspaceLabels) !!}</script>

    <div class="gf-ai-workbench">
        @unless($runtimeEnabled)
            <div class="gf-ai-runtime-notice" role="status">
                <i data-lucide="shield-alert"></i>
                <span>{{ $runtimeConfigured ? $runtimeUnavailableReason : __('admin.ai_workspace.runtime_disabled') }}</span>
            </div>
        @endunless

        <section class="gf-ai-start" data-ai-start>
            <div class="gf-ai-heading">
                <span class="gf-ai-demo-badge"><i data-lucide="shield-check"></i>{{ __('admin.ai_workspace.demo_badge') }}</span>
                <h1>{{ __('admin.ai_workspace.headline') }}</h1>
                <p>{{ __('admin.ai_workspace.subtitle') }}</p>
            </div>
            <div class="gf-ai-suggestions">
                <div class="gf-ai-suggestions__head">
                    <span>{{ __('admin.ai_workspace.suggestions') }}</span>
                    <small><i data-lucide="sparkles"></i>{{ __('admin.ai_workspace.capability_count', ['count' => $capabilityCount]) }}</small>
                </div>
                <div class="gf-ai-suggestions__items">
                    @foreach ($featuredCapabilities as $capability)
                        <button type="button" data-ai-suggestion="{{ $capability['prompt'] }}" data-capability-key="{{ $capability['key'] }}">
                            <i data-lucide="{{ $capability['icon'] }}"></i>{{ $capability['name'] }}
                        </button>
                    @endforeach
                </div>
            </div>
            <section class="gf-ai-capability" aria-label="{{ __('admin.ai_workspace.capability_count', ['count' => $capabilityCount]) }}">
                <div class="gf-ai-capability__header">
                    <div>
                        <span>{{ __('admin.ai_workspace.conversation_title') }}</span>
                        <h2>{{ __('admin.ai_workspace.capability_directory_title') }}</h2>
                        <p>{{ __('admin.ai_workspace.capability_directory_description') }}</p>
                    </div>
                    <strong><i></i>{{ __('admin.ai_workspace.capability_count', ['count' => $capabilityCount]) }}</strong>
                </div>
                <div class="gf-ai-capability__groups">
                    @foreach ($capabilityGroups as $group)
                        <details class="gf-ai-capability-group" data-capability-group="{{ $group['key'] }}" @if($loop->first) open @endif>
                            <summary>
                                <span class="gf-ai-capability-group__icon"><i data-lucide="{{ $group['icon'] }}"></i></span>
                                <span class="gf-ai-capability-group__copy">
                                    <strong>{{ $group['title'] }}</strong>
                                    <small>{{ $group['description'] }}</small>
                                </span>
                                <span class="gf-ai-capability-group__count">{{ count($group['capabilities']) }}</span>
                                <i class="gf-ai-capability-group__chevron" data-lucide="chevron-down"></i>
                            </summary>
                            <div class="gf-ai-capability-group__items">
                                @foreach ($group['capabilities'] as $capability)
                                    <article class="gf-ai-capability-item" data-capability-key="{{ $capability['key'] }}">
                                        <div class="gf-ai-capability-item__main">
                                            <h3>{{ $capability['name'] }}</h3>
                                            <p>{{ $capability['description'] }}</p>
                                            <div class="gf-ai-capability-item__meta">
                                                <span class="is-{{ $capability['maturity'] }}">{{ $capability['maturity_label'] }}</span>
                                                <span>{{ $capability['scope_label'] }}</span>
                                                <span>{{ $capability['approval_label'] }}</span>
                                            </div>
                                        </div>
                                        <div class="gf-ai-capability-item__action">
                                            <small>{{ $capability['required_label'] }}</small>
                                            <button type="button" data-ai-suggestion="{{ $capability['prompt'] }}">
                                                {{ $capability['action_label'] }}<i data-lucide="arrow-up-right"></i>
                                            </button>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>
            <p class="gf-ai-policy-note"><i data-lucide="shield-check"></i>{{ __('admin.ai_workspace.disclaimer') }}</p>
        </section>

        <section class="gf-ai-thread" data-ai-thread hidden>
            <div class="gf-ai-thread__messages" data-ai-messages aria-live="polite"></div>
            <div class="gf-ai-thread__runs" data-ai-runs></div>
        </section>

        <div class="sr-only" data-ai-alert role="alert" hidden></div>

        <form class="gf-ai-composer" data-ai-form>
            <label class="sr-only" for="gf-ai-prompt">{{ __('admin.ai_workspace.placeholder') }}</label>
            <textarea id="gf-ai-prompt" rows="3" maxlength="4000" data-ai-input placeholder="{{ __('admin.ai_workspace.placeholder') }}"></textarea>
            <div class="gf-ai-composer__toolbar">
                <div class="gf-ai-composer__tools">
                    <button type="button" aria-label="{{ __('admin.ai_workspace.thinking') }}" data-ai-suggestion="{{ __('admin.ai_workspace.sample_task') }}"><i data-lucide="sparkles"></i></button>
                    @foreach (array_slice($featuredCapabilities, 0, 2) as $capability)
                        <button type="button" data-ai-suggestion="{{ $capability['prompt'] }}" data-capability-key="{{ $capability['key'] }}">
                            <i data-lucide="{{ $capability['icon'] }}"></i><span>{{ $capability['name'] }}</span>
                        </button>
                    @endforeach
                </div>
                <div class="gf-ai-composer__submit">
                    <span>{{ __('admin.ai_workspace.send_shortcut') }}</span>
                    <button class="gf-ai-send" type="submit" aria-label="{{ __('admin.ai_workspace.send') }}" disabled>
                        <i data-lucide="arrow-up"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="gf-modal-backdrop gf-ai-error-modal" data-gf-modal="ai-workspace-error" data-ai-error-dialog data-kind="runtime" hidden>
        <section class="gf-modal gf-ai-error-dialog" role="alertdialog" aria-modal="true" aria-labelledby="gf-ai-error-title" aria-describedby="gf-ai-error-description gf-ai-error-detail gf-ai-error-hint">
            <button class="gf-icon-button gf-ai-error-dialog__close" type="button" data-dialog-close aria-label="{{ __('admin.ui_v3.close_dialog') }}">
                <i data-lucide="x"></i>
            </button>
            <span class="gf-ai-error-dialog__icon" aria-hidden="true"><i data-lucide="shield-alert"></i></span>
            <span class="gf-ai-error-dialog__eyebrow">{{ __('admin.ai_workspace.error_dialog_eyebrow') }}</span>
            <h2 id="gf-ai-error-title" data-ai-error-title>{{ __('admin.ai_workspace.error_runtime_title') }}</h2>
            <p id="gf-ai-error-description" class="gf-ai-error-dialog__description" data-ai-error-description>{{ __('admin.ai_workspace.error_runtime_description') }}</p>
            <div id="gf-ai-error-detail" class="gf-ai-error-dialog__detail">
                <i data-lucide="info"></i><span data-ai-error-detail>{{ $runtimeConfigured ? $runtimeUnavailableReason : __('admin.ai_workspace.runtime_disabled') }}</span>
            </div>
            <p id="gf-ai-error-hint" class="gf-ai-error-dialog__hint" data-ai-error-hint>{{ __('admin.ai_workspace.error_runtime_hint') }}</p>
            <footer class="gf-ai-error-dialog__footer">
                <button class="gf-button" type="button" data-dialog-close data-ai-error-secondary>{{ __('admin.ai_workspace.continue_editing') }}</button>
                <a class="gf-button gf-button--primary" href="{{ \App\Support\AdminWeb::routePath('admin.ai.configurator') }}" target="_blank" rel="noopener" data-ai-error-configurator>
                    <i data-lucide="settings-2"></i><span data-ai-error-configurator-label>{{ __('admin.ai_workspace.open_configurator') }}</span>
                </a>
                <button class="gf-button gf-button--primary" type="button" data-ai-error-reload hidden>
                    <i data-lucide="refresh-cw"></i><span data-ai-error-reload-label>{{ __('admin.ai_workspace.refresh_page') }}</span>
                </button>
            </footer>
        </section>
    </div>
</div>
@endsection
