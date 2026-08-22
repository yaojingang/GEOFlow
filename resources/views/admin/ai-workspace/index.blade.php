@extends('admin.layouts.app')

@section('content')
<div class="gf-ai-workspace" data-ai-workspace
    data-status-pending="{{ __('admin.ai_workspace.status_pending') }}"
    data-status-running="{{ __('admin.ai_workspace.status_running') }}"
    data-status-completed="{{ __('admin.ai_workspace.status_completed') }}"
    data-confirmed="{{ __('admin.ai_workspace.confirmed') }}"
    data-adjust-hint="{{ __('admin.ai_workspace.adjust_hint') }}">
    <section class="gf-ai-start" data-ai-start>
        <div class="gf-ai-heading">
            <span class="gf-ai-demo-badge"><i data-lucide="flask-conical"></i>{{ __('admin.ai_workspace.demo_badge') }}</span>
            <h1>{{ __('admin.ai_workspace.headline') }}</h1>
            <p>{{ __('admin.ai_workspace.subtitle') }}</p>
        </div>
        <form class="gf-ai-composer gf-ai-composer--start" data-ai-form>
            <label class="sr-only" for="gf-ai-prompt">{{ __('admin.ai_workspace.placeholder') }}</label>
            <textarea id="gf-ai-prompt" rows="4" maxlength="4000" data-ai-input placeholder="{{ __('admin.ai_workspace.placeholder') }}">{{ __('admin.ai_workspace.sample_task') }}</textarea>
            <div class="gf-ai-composer__toolbar">
                <div class="gf-ai-composer__tools"><button type="button" aria-label="{{ __('admin.ai_workspace.suggest_assets') }}"><i data-lucide="plus"></i></button><span><i data-lucide="sparkles"></i>GEOFlow Agent</span></div>
                <button class="gf-ai-send" type="submit" aria-label="{{ __('admin.ai_workspace.send') }}"><i data-lucide="arrow-up"></i></button>
            </div>
        </form>
        <div class="gf-ai-suggestions">
            <div class="gf-ai-suggestions__head"><span>{{ __('admin.ai_workspace.suggestions') }}</span></div>
            <div class="gf-ai-suggestions__items">
                <button type="button" data-ai-suggestion="{{ __('admin.ai_workspace.suggest_diagnosis') }}"><i data-lucide="scan-search"></i>{{ __('admin.ai_workspace.suggest_diagnosis') }}</button>
                <button type="button" data-ai-suggestion="{{ __('admin.ai_workspace.suggest_task') }}"><i data-lucide="workflow"></i>{{ __('admin.ai_workspace.suggest_task') }}</button>
                <button type="button" data-ai-suggestion="{{ __('admin.ai_workspace.suggest_assets') }}"><i data-lucide="database"></i>{{ __('admin.ai_workspace.suggest_assets') }}</button>
                <button type="button" data-ai-suggestion="{{ __('admin.ai_workspace.suggest_distribution') }}"><i data-lucide="radio-tower"></i>{{ __('admin.ai_workspace.suggest_distribution') }}</button>
            </div>
        </div>
    </section>

    <section class="gf-ai-conversation" data-ai-conversation hidden>
        <div class="gf-ai-user-message" data-ai-user-message></div>
        <article class="gf-ai-agent-message">
            <header class="gf-ai-agent-message__identity"><span><i data-lucide="sparkles"></i></span><div><strong>{{ __('admin.ai_workspace.conversation_title') }}</strong><small>{{ __('admin.ai_workspace.demo_badge') }}</small></div></header>
            <p>{{ __('admin.ai_workspace.intro') }}</p>
            <div class="gf-ai-progress" data-ai-progress>
                @foreach (['intent', 'skills', 'research', 'organize', 'execute', 'result'] as $stage)
                    <div class="gf-ai-stage" data-ai-stage data-stage-key="{{ $stage }}"><span class="gf-ai-stage__indicator"><i data-lucide="circle"></i></span><span>{{ __('admin.ai_workspace.stage_'.$stage) }}</span><small data-ai-stage-status>{{ __('admin.ai_workspace.status_pending') }}</small></div>
                @endforeach
            </div>
            <section class="gf-ai-result" data-ai-result data-requires-confirmation="true" hidden>
                <div class="gf-ai-result__header"><span><i data-lucide="circle-check-big"></i></span><div><h2>{{ __('admin.ai_workspace.result_title') }}</h2><p>{{ __('admin.ai_workspace.result_summary') }}</p></div></div>
                <dl class="gf-ai-result__grid">
                    <div><dt>{{ __('admin.ai_workspace.result_completed') }}</dt><dd>{{ __('admin.ai_workspace.result_completed_value') }}</dd></div>
                    <div><dt>{{ __('admin.ai_workspace.result_schedule') }}</dt><dd>{{ __('admin.ai_workspace.result_schedule_value') }}</dd></div>
                    <div><dt>{{ __('admin.ai_workspace.result_risk') }}</dt><dd>{{ __('admin.ai_workspace.result_risk_value') }}</dd></div>
                </dl>
                <label class="gf-ai-auto-confirm"><input type="checkbox" data-ai-auto-confirm><span>{{ __('admin.ai_workspace.auto_confirm') }}</span></label>
                <div class="gf-ai-result__actions"><button class="gf-button" type="button" data-ai-adjust><i data-lucide="pencil-line"></i>{{ __('admin.ai_workspace.adjust') }}</button><button class="gf-button gf-button--primary" type="button" data-ai-confirm><i data-lucide="check"></i>{{ __('admin.ai_workspace.confirm') }}</button></div>
            </section>
        </article>
        <p class="gf-ai-disclaimer">{{ __('admin.ai_workspace.disclaimer') }}</p>
        <form class="gf-ai-composer gf-ai-composer--conversation" data-ai-followup-form>
            <label class="sr-only" for="gf-ai-followup">{{ __('admin.ai_workspace.placeholder') }}</label>
            <textarea id="gf-ai-followup" rows="2" maxlength="2000" placeholder="{{ __('admin.ai_workspace.placeholder') }}"></textarea>
            <div class="gf-ai-composer__toolbar"><span>{{ __('admin.ai_workspace.demo_badge') }}</span><button class="gf-ai-send" type="submit" aria-label="{{ __('admin.ai_workspace.send') }}"><i data-lucide="arrow-up"></i></button></div>
        </form>
    </section>
</div>
@endsection
