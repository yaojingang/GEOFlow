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
                    <div class="gf-ai-help__notice" role="status">
                        <i data-lucide="circle-alert"></i>
                        <span>{{ __('admin.ai_workspace.local_help_available') }}</span>
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
                    <button class="gf-ai-help__tool-mode is-active" type="button" data-ai-fill-prompt="{{ __('admin.ai_workspace.composer_content_prompt') }}" aria-label="{{ __('admin.ai_workspace.composer_content') }}">
                        <i data-lucide="file-plus-2"></i><span>{{ __('admin.ai_workspace.composer_content') }}</span>
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
