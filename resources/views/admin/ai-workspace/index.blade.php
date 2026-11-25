@extends('admin.layouts.app')

@section('sidebar-recent-action')
    <button class="gf-icon-button gf-icon-button--small" type="button" data-ai-new aria-label="{{ __('admin.ai_workspace.new_conversation') }}"><i data-lucide="square-pen"></i></button>
@endsection

@section('content')
<div class="gf-ai-help" data-ai-workspace
    data-user-initial="{{ $userInitial }}"
    data-admin-base-path="{{ \App\Support\AdminWeb::appPath(\App\Support\AdminWeb::basePath()) }}"
    data-login-url="{{ route('admin.login') }}"
    data-runtime-enabled="{{ $assistantAvailable ? 'true' : 'false' }}"
    data-conversations-url="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.conversations.index') }}"
    data-conversation-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.conversations.show', ['conversation' => '__ID__']) }}"
    data-message-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.messages.store', ['conversation' => '__ID__']) }}"
    data-update-url-template="{{ \App\Support\AdminWeb::routePath('admin.ai-workspace.conversations.update', ['conversation' => '__ID__']) }}">
    <script type="application/json" data-ai-labels>{!! \Illuminate\Support\Js::encode($aiWorkspaceLabels) !!}</script>

    <div class="gf-ai-help__shell">
        <section class="gf-ai-help__thread" data-ai-thread hidden>
            <header class="gf-ai-help__thread-head">
                <div>
                    <span class="gf-ai-help__thread-mark" aria-hidden="true"><i data-lucide="sparkles"></i></span>
                    <span>
                        <strong data-ai-thread-title>{{ __('admin.ai_workspace.conversation_title') }}</strong>
                        <small>{{ __('admin.ai_workspace.assistant_role') }}</small>
                    </span>
                </div>
                <div class="gf-ai-help__thread-actions">
                    <button class="gf-icon-button gf-icon-button--small" type="button" data-ai-rename aria-label="{{ __('admin.ai_workspace.rename') }}" title="{{ __('admin.ai_workspace.rename') }}"><i data-lucide="pencil-line"></i></button>
                    <button class="gf-icon-button gf-icon-button--small" type="button" data-ai-new aria-label="{{ __('admin.ai_workspace.new_conversation') }}" title="{{ __('admin.ai_workspace.new_conversation') }}"><i data-lucide="square-pen"></i></button>
                </div>
            </header>
            <button class="gf-ai-help__earlier" type="button" data-ai-load-earlier hidden>{{ __('admin.ai_workspace.load_earlier') }}</button>
            <div class="gf-ai-help__messages" data-ai-messages aria-live="off"></div>
            <button class="gf-ai-help__jump" type="button" data-ai-jump-latest hidden><i data-lucide="arrow-down"></i>{{ __('admin.ai_workspace.jump_latest') }}</button>
        </section>

        <section class="gf-ai-help__welcome" data-ai-start>
            <div class="gf-ai-help__home-intro">
                <div class="gf-ai-help__heading">
                    <h1>{{ __('admin.ai_workspace.headline') }}</h1>
                    <p>{{ __('admin.ai_workspace.subtitle') }}</p>
                </div>

                @unless($assistantAvailable)
                    <div class="gf-ai-help__notice" data-ai-connection-notice data-state="idle">
                        <i data-lucide="circle-alert"></i>
                        <span class="gf-ai-help__notice-message" data-ai-connection-message role="status" aria-live="polite">{{ $assistantConnection['message'] }}</span>
                        <div class="gf-ai-help__notice-actions" data-ai-connection-actions>
                            @if ($assistantConnection['test_url'])
                                <button type="button" data-ai-connection-check data-test-url="{{ $assistantConnection['test_url'] }}" disabled>{{ __('admin.ai_workspace.connection_check') }}</button>
                            @endif
                            @if ($assistantConnection['settings_url'])
                                <a href="{{ $assistantConnection['settings_url'] }}" @if(str_contains($assistantConnection['settings_url'], '#site-settings-ai-workspace')) data-ai-runtime-settings @endif>{{ $assistantConnection['settings_label'] }}</a>
                            @endif
                        </div>
                    </div>
                @endunless
            </div>
        </section>

        <div class="sr-only" data-ai-alert role="status" aria-live="polite" aria-atomic="true" hidden></div>

        <form class="gf-ai-help__composer" data-ai-form>
            <label class="sr-only" for="gf-ai-prompt">{{ __('admin.ai_workspace.placeholder') }}</label>
            <textarea id="gf-ai-prompt" rows="1" maxlength="4000" data-ai-input placeholder="{{ __('admin.ai_workspace.placeholder') }}"></textarea>
            <p class="gf-ai-help__composer-error" data-ai-composer-error role="alert" hidden></p>
            <footer>
                <div class="gf-ai-help__tools">
                    <button type="button" data-ai-fill-prompt="{{ __('admin.ai_workspace.suggest_assets') }}" aria-label="{{ __('admin.ai_workspace.suggest_assets') }}"><i data-lucide="plus"></i></button>
                    <button class="gf-ai-help__tool-icon" type="button" data-ai-fill-prompt="{{ __('admin.ai_workspace.composer_magic_prompt') }}" aria-label="{{ __('admin.ai_workspace.composer_magic') }}"><i data-lucide="sparkles"></i></button>
                    <button class="gf-ai-help__tool-mode" type="button" data-ai-fill-prompt="{{ __('admin.ai_workspace.composer_diagnosis_prompt') }}" aria-label="{{ __('admin.ai_workspace.composer_diagnosis') }}">
                        <i data-lucide="chart-no-axes-combined"></i><span>{{ __('admin.ai_workspace.composer_diagnosis') }}</span><em>{{ __('admin.ai_workspace.new_badge') }}</em>
                    </button>
                    <button class="gf-ai-help__tool-mode is-active" type="button" data-ai-fill-prompt="{{ __('ai-task.ui.startPrompt') }}" aria-label="{{ __('ai-task.ui.start') }}">
                        <i data-lucide="file-plus-2"></i><span>{{ __('ai-task.ui.start') }}</span>
                    </button>
                </div>
                <span class="gf-ai-help__shortcut">{{ __('admin.ai_workspace.send_shortcut') }}</span>
                <button class="gf-ai-help__stop" type="button" data-ai-stop hidden><i data-lucide="square"></i>{{ __('admin.ai_workspace.stop') }}</button>
                <button class="gf-ai-help__send" type="submit" data-ai-send aria-label="{{ __('admin.ai_workspace.send') }}" disabled><i data-lucide="arrow-up"></i></button>
            </footer>
        </form>

        <section class="gf-ai-help__starters" aria-labelledby="gf-ai-starter-title">
            <header>
                <span id="gf-ai-starter-title">{{ __('admin.ai_workspace.suggestions') }}</span>
                <div>
                    <button type="button" data-ai-fill-prompt="{{ __('admin.ai_workspace.assets_prompt') }}"><i data-lucide="file-up"></i>{{ __('admin.ai_workspace.assets_action') }}</button>
                    <small>{{ __('admin.ai_workspace.skill_hint') }}</small>
                </div>
            </header>
            <div class="gf-ai-help__starter-actions">
                @foreach($starterActions as $action)
                    <button type="button" data-ai-suggestion="{{ $action['prompt'] }}">
                        <i data-lucide="{{ $action['icon'] }}"></i><span>{{ $action['name'] }}</span>
                    </button>
                @endforeach
            </div>
        </section>


        <section class="gf-ai-help__agents" aria-labelledby="gf-ai-agent-title">
            <header>
                <span id="gf-ai-agent-title">智能体矩阵</span>
                <small>禅道 · 数字员工 · 运维 · n8n · MCP 工具统一接入</small>
            </header>
            <div class="gf-ai-help__agent-grid">
                @foreach($agentMatrix as $agent)
                    <div class="gf-ai-help__agent-card">
                        <div class="gf-ai-help__agent-head">
                            <i data-lucide="{{ $agent['icon'] }}"></i>
                            <div>
                                <strong>{{ $agent['name'] }}</strong>
                                <span class="gf-ai-help__agent-status gf-ai-help__agent-status--{{ $agent['status'] }}">
                                    @if($agent['status'] === 'active') 在线
                                    @elseif($agent['status'] === 'pending') 待接入
                                    @else 离线 @endif
                                </span>
                            </div>
                        </div>
                        <p>{{ $agent['detail'] }}</p>
                        @if(!empty($agent['url']))
                            <a href="{{ $agent['url'] }}" @if(!empty($agent['external'])) target="_blank" rel="noopener" @endif>进入 →</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <style>
            .gf-ai-help__agents { margin: 8px 0 16px; }
            .gf-ai-help__agents header { display: flex; align-items: baseline; gap: 10px; margin-bottom: 12px; }
            .gf-ai-help__agents header span { font-weight: 600; color: var(--gf-gray-900); }
            .gf-ai-help__agents header small { color: var(--gf-gray-500); font-size: 12px; }
            .gf-ai-help__agent-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
            .gf-ai-help__agent-card { background: var(--gf-gray-50); border: 1px solid var(--gf-gray-200); border-radius: 14px; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
            .gf-ai-help__agent-head { display: flex; align-items: center; gap: 10px; }
            .gf-ai-help__agent-head > i { width: 34px; height: 34px; border-radius: 10px; background: var(--gf-blue-50); color: var(--gf-blue-600); display: flex; align-items: center; justify-content: center; flex: none; }
            .gf-ai-help__agent-head strong { display: block; font-size: 14px; color: var(--gf-gray-900); }
            .gf-ai-help__agent-head > div { display: flex; flex-direction: column; gap: 2px; }
            .gf-ai-help__agent-status { font-size: 11px; font-weight: 600; }
            .gf-ai-help__agent-status--active { color: var(--gf-emerald-600); }
            .gf-ai-help__agent-status--pending { color: var(--gf-amber-600); }
            .gf-ai-help__agent-status--offline { color: var(--gf-gray-500); }
            .gf-ai-help__agent-card p { font-size: 12.5px; color: var(--gf-gray-600); line-height: 1.6; margin: 0; }
            .gf-ai-help__agent-card a { font-size: 13px; color: var(--gf-blue-600); font-weight: 600; text-decoration: none; margin-top: auto; }
            .gf-ai-help__agent-card a:hover { text-decoration: underline; }
        </style>

        @php($showcaseSlides = __('admin.ai_workspace.showcase.slides'))
        <section class="gf-ai-help__showcase" data-ai-showcase aria-roledescription="carousel" aria-label="{{ __('admin.ai_workspace.showcase.label') }}">
            <div class="gf-ai-help__showcase-frame">
                @foreach($showcaseSlides as $index => $slide)
                    <article class="gf-ai-help__showcase-slide" data-ai-showcase-slide data-tone="{{ $slide['tone'] ?? 'blue' }}" role="group" aria-roledescription="slide" aria-label="{{ __('admin.ai_workspace.showcase.position', ['current' => $index + 1, 'total' => count($showcaseSlides)]) }}" @if($index !== 0) hidden @endif>
                        <div class="gf-ai-help__showcase-copy">
                            <h2>{{ $slide['title'] }} <i data-lucide="chevron-right"></i></h2>
                            <p>{{ $slide['description'] }}</p>
                            @if(!empty($slide['callout_title']))
                                <div class="gf-ai-help__showcase-callout">
                                    <span><i data-lucide="clipboard-clock"></i></span>
                                    <div><strong>{{ $slide['callout_title'] }}</strong><small>{{ $slide['callout_copy'] }}</small></div>
                                </div>
                            @else
                                <div class="gf-ai-help__showcase-tags">
                                    @foreach($slide['tags'] as $tag)<span>{{ $tag }}</span>@endforeach
                                </div>
                            @endif
                        </div>
                        <aside class="gf-ai-help__showcase-visual" aria-hidden="true">
                            @if(($slide['tone'] ?? 'blue') === 'amber')
                                <i class="gf-ai-help__showcase-shield" data-lucide="shield-check"></i>
                            @else
                                <div>
                                    <strong><i data-lucide="{{ $slide['visual_icon'] }}"></i>{{ $slide['visual_title'] }}</strong>
                                    <span>
                                        @foreach($slide['visual_items'] as $item)<em>{{ $item }}</em>@endforeach
                                    </span>
                                </div>
                            @endif
                        </aside>
                    </article>
                @endforeach
            </div>
            <nav class="gf-ai-help__showcase-controls" aria-label="{{ __('admin.ai_workspace.showcase.navigation') }}">
                <button type="button" data-ai-showcase-prev aria-label="{{ __('admin.ai_workspace.showcase.previous') }}"><i data-lucide="chevron-left"></i></button>
                <div>
                    @foreach($showcaseSlides as $index => $slide)
                        <button type="button" data-ai-showcase-dot="{{ $index }}" aria-label="{{ __('admin.ai_workspace.showcase.goto', ['slide' => $index + 1]) }}" @if($index === 0) aria-current="true" @endif></button>
                    @endforeach
                </div>
                <button type="button" data-ai-showcase-next aria-label="{{ __('admin.ai_workspace.showcase.next') }}"><i data-lucide="chevron-right"></i></button>
            </nav>
        </section>
    </div>
</div>
@endsection
