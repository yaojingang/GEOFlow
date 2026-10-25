@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/article-prose.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/prompt-test.css') }}">
@endpush

<section data-prompt-test
    data-options-url="{{ route('admin.ai-prompts.test-options', ['promptId' => $prompt->id]) }}"
    data-generate-url="{{ route('admin.ai-prompts.test', ['promptId' => $prompt->id]) }}"
    data-run-url="{{ route('admin.ai-prompts.tests.show', ['promptId' => $prompt->id, 'runId' => '__RUN__']) }}"
    aria-label="{{ __('prompt_test.heading') }}">
    <script type="application/json" data-prompt-test-labels>{!! \Illuminate\Support\Js::encode(__('prompt_test')) !!}</script>

    <form class="prompt-test-config" data-test-config aria-label="{{ __('prompt_test.configure') }}">
        <fieldset data-config-fields disabled>
            <div class="prompt-test-config-row">
                <div class="prompt-test-config-field">
                    <label id="prompt-test-knowledge-label">{{ __('prompt_test.knowledge') }}</label>
                    <details class="prompt-test-picker" data-knowledge-picker>
                        <summary aria-labelledby="prompt-test-knowledge-label prompt-test-knowledge-selection"><span id="prompt-test-knowledge-selection" data-knowledge-summary>{{ __('prompt_test.select_knowledge_short') }}</span></summary>
                        <div class="prompt-test-picker-panel">
                            <p class="prompt-test-muted">{{ __('prompt_test.knowledge_hint') }}</p>
                            <input type="search" data-knowledge-search aria-label="{{ __('prompt_test.knowledge_search') }}" placeholder="{{ __('prompt_test.knowledge_search') }}">
                            <div data-knowledge-list class="prompt-test-choices"></div>
                            <button type="button" data-more-knowledge class="prompt-test-text-button" hidden>{{ __('prompt_test.more') }}</button>
                        </div>
                    </details>
                    <p class="prompt-test-error" data-field-error="knowledge_base_ids" role="alert"></p>
                </div>
                <div class="prompt-test-config-field prompt-test-title-field">
                    <div class="prompt-test-title-source" role="group" aria-label="{{ __('prompt_test.title_source') }}">
                        <label><input type="radio" name="title_source" value="library" checked> {{ __('prompt_test.title_library') }}</label>
                        <label><input type="radio" name="title_source" value="manual"> {{ __('prompt_test.manual') }}</label>
                    </div>
                    <div data-library-fields>
                        <details class="prompt-test-picker" data-title-picker>
                            <summary aria-label="{{ __('prompt_test.title') }}"><span data-title-summary>{{ __('prompt_test.choose_title') }}</span></summary>
                            <div class="prompt-test-picker-panel">
                                <label for="prompt-test-library">{{ __('prompt_test.title_library') }}</label>
                                <select id="prompt-test-library" data-title-library><option value="">{{ __('prompt_test.choose_library') }}</option></select>
                                <input type="search" data-title-search aria-label="{{ __('prompt_test.title_search') }}" placeholder="{{ __('prompt_test.title_search') }}" disabled>
                                <div data-title-list class="prompt-test-choices"></div>
                                <button type="button" data-more-titles class="prompt-test-text-button" hidden>{{ __('prompt_test.more') }}</button>
                            </div>
                        </details>
                    </div>
                    <div data-manual-fields hidden>
                        <input id="prompt-test-title" data-manual-title aria-label="{{ __('prompt_test.title') }}" placeholder="{{ __('prompt_test.title') }}" maxlength="500">
                    </div>
                    <p class="prompt-test-error" data-field-error="title" role="alert"></p>
                </div>
                <div class="prompt-test-config-field"><label for="prompt-test-keyword">{{ __('prompt_test.keyword') }}</label><input id="prompt-test-keyword" data-keyword maxlength="200"></div>
                <div class="prompt-test-config-field"><label for="prompt-test-model">{{ __('prompt_test.generation_model') }}</label><select id="prompt-test-model" data-generation-model required><option value="">{{ __('prompt_test.choose_model') }}</option></select></div>
                <div class="prompt-test-config-field"><label for="prompt-test-diagnostic-model">{{ __('prompt_test.diagnostic_model') }}</label><select id="prompt-test-diagnostic-model" data-diagnostic-model><option value="">{{ __('prompt_test.same_model') }}</option></select></div>
                <button type="submit" data-start class="prompt-test-button prompt-test-primary" disabled>{{ __('prompt_test.start') }}</button>
            </div>
            <div data-selected-knowledge class="prompt-test-selected"></div>
            <p data-selected-title class="prompt-test-selected"></p>
        </fieldset>
        <div class="prompt-test-config-note">
            <p class="prompt-test-muted">{{ __('prompt_test.draft_hint') }}</p>
            <span data-config-loading class="prompt-test-muted" role="status">{{ __('prompt_test.loading') }}</span>
            <button type="button" data-reload-config class="prompt-test-text-button" hidden>{{ __('prompt_test.reload_options') }}</button>
        </div>
        <p class="prompt-test-error" data-config-error role="alert" hidden></p>
    </form>

    <div class="prompt-test-result" data-test-result hidden>
        <header class="prompt-test-result-header">
            <div><h2>{{ __('prompt_test.heading') }}</h2><p data-run-meta class="prompt-test-muted"></p></div>
            <div class="prompt-test-actions">
                <button type="button" data-adjust class="prompt-test-button">{{ __('prompt_test.adjust') }}</button>
                <button type="button" data-again class="prompt-test-button">{{ __('prompt_test.again') }}</button>
                <button type="button" data-stop class="prompt-test-button" hidden>{{ __('prompt_test.stop') }}</button>
            </div>
        </header>
        <div class="prompt-test-status-row"><span data-status role="status" aria-live="polite"></span><span data-run-changed class="prompt-test-muted" hidden>{{ __('prompt_test.unsaved') }}</span></div>
        <p class="prompt-test-error" data-run-error role="alert" hidden></p>
        <button type="button" data-refresh class="prompt-test-text-button" hidden>{{ __('prompt_test.refresh_status') }}</button>
        <div class="prompt-test-article-toolbar">
            <div class="prompt-test-actions" role="group" aria-label="{{ __('prompt_test.preview') }}">
                <button type="button" data-show-preview class="prompt-test-tab" aria-pressed="true">{{ __('prompt_test.preview') }}</button>
                <button type="button" data-show-source class="prompt-test-tab" aria-pressed="false">{{ __('prompt_test.source') }}</button>
            </div>
            <button type="button" data-copy-article class="prompt-test-text-button">{{ __('prompt_test.copy') }}</button>
        </div>
        <article class="prompt-test-article">
            <h2 data-article-title></h2>
            <div data-article-preview class="article-prose"></div>
            <pre data-article-source class="prompt-test-source" hidden></pre>
        </article>
        <section class="prompt-test-diagnosis" data-diagnosis hidden aria-labelledby="prompt-test-diagnosis-title">
            <div class="prompt-test-diagnosis-header"><h2 id="prompt-test-diagnosis-title">{{ __('prompt_test.diagnosis') }}</h2><span data-diagnosis-version class="prompt-test-muted"></span></div>
            <p class="prompt-test-muted">{{ __('prompt_test.diagnosis_hint') }}</p>
            <p data-diagnosis-status role="status"></p>
            <div data-diagnosis-results></div>
            <div data-diagnosis-retry class="prompt-test-retry" hidden>
                <label for="prompt-test-retry-model">{{ __('prompt_test.retry_model') }}</label>
                <select id="prompt-test-retry-model" data-retry-model></select>
                <button type="button" data-retry class="prompt-test-button">{{ __('prompt_test.retry') }}</button>
            </div>
        </section>
        <p data-action-notice class="prompt-test-notice" role="status" aria-live="polite"></p>
    </div>
    <dialog class="prompt-test-dialog" data-snapshot-dialog aria-labelledby="prompt-test-snapshot-title">
        <header class="prompt-test-dialog-header"><h2 id="prompt-test-snapshot-title">{{ __('prompt_test.snapshot') }}</h2><button type="button" data-close-snapshot class="prompt-test-close" aria-label="{{ __('prompt_test.close') }}">×</button></header>
        <div class="prompt-test-dialog-body"><p>{{ __('prompt_test.stale') }}</p><textarea data-snapshot readonly rows="18" aria-label="{{ __('prompt_test.snapshot') }}"></textarea></div>
    </dialog>
</section>
