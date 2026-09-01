<section id="atomic-facts" class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" lang="{{ str_starts_with(app()->getLocale(), 'zh') ? 'zh' : app()->getLocale() }}" @if($activeGenerationRun ?? null) data-active-generation-run='@json($activeGenerationRun)' @endif>
    <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
        <div class="max-w-2xl">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('admin.knowledge_facts.title') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('admin.knowledge_facts.description') }}</p>
        </div>
        @if($factLibrary)
            <div class="flex flex-wrap items-center gap-2 text-xs tabular-nums text-slate-600">
                <span>{{ __('admin.knowledge_facts.working_version') }} {{ $factLibrary->working_version }}</span>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 font-medium">{{ $factLibrary->workflow_status }}</span>
                <span class="rounded-full px-2.5 py-1 font-medium {{ $factLibrary->serving_status === 'ready' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $factLibrary->serving_status }}</span>
            </div>
        @endif
    </header>

    @unless($systemReadOnly)
        <div class="grid gap-6 px-5 py-5 sm:px-6 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.48fr)]">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">{{ __('admin.knowledge_facts.manual_create') }}</h3>
                <form method="POST" action="{{ route('admin.knowledge-bases.facts.store', ['knowledgeBaseId' => $knowledgeBase->id]) }}" class="mt-3 grid gap-3 md:grid-cols-2">
                    @csrf
                    <input name="stable_key" required placeholder="company.founded_at" class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                    <input name="label" required placeholder="{{ __('admin.knowledge_facts.label') }}" class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                    <input name="subject" required placeholder="{{ __('admin.knowledge_facts.subject') }}" class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                    <input name="predicate" required placeholder="{{ __('admin.knowledge_facts.predicate') }}" class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                    <select name="value_type" class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                        @foreach(['string', 'integer', 'decimal', 'number', 'percentage', 'date', 'range', 'boolean', 'url', 'path', 'version'] as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach
                    </select>
                    <button class="min-h-10 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white active:scale-[.98]">{{ __('admin.knowledge_facts.add') }}</button>
                </form>
            </div>

            <div class="border-t border-slate-200 pt-5 xl:border-l xl:border-t-0 xl:pl-6 xl:pt-0">
                <h3 class="text-sm font-semibold text-slate-900">{{ __('admin.knowledge_facts.ai_generation') }}</h3>
                <form method="POST" action="{{ route('admin.knowledge-bases.fact-generation.store', ['knowledgeBaseId' => $knowledgeBase->id]) }}" class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-1" data-atomic-fact-generation-form>
                    @csrf
                    <input type="hidden" name="request_key" value="{{ (string) Str::uuid() }}">
                    <select name="ai_model_id" required class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                        <option value="">{{ __('admin.knowledge_facts.select_model') }}</option>
                        @foreach($factGenerationModels ?? collect() as $model)<option value="{{ $model->id }}">{{ $model->name }} · {{ $model->model_id }}</option>@endforeach
                    </select>
                    <div class="grid grid-cols-[1fr_7rem] gap-3">
                        <select name="mode" class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500"><option value="initial">首次生成</option><option value="supplement">补充事实</option><option value="refresh_stale">更新陈旧事实</option></select>
                        <input name="target_count" type="number" min="1" max="200" value="50" required class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500" aria-label="{{ __('admin.knowledge_facts.target_count') }}">
                    </div>
                    <button class="min-h-10 rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white transition-[background-color,transform] duration-150 hover:bg-orange-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2 active:scale-[.98] disabled:cursor-wait disabled:opacity-60 sm:col-span-2 xl:col-span-1" data-atomic-fact-generation-submit>{{ __('admin.knowledge_facts.start_generation') }}</button>
                </form>
            </div>
        </div>
    @endunless

    @unless($systemReadOnly)
        <dialog
            class="fixed inset-0 m-auto w-[min(560px,calc(100vw-2rem))] max-w-none overflow-hidden rounded-2xl border-0 bg-white p-0 text-left text-slate-900 shadow-[0_24px_72px_rgba(15,23,42,0.28)] backdrop:bg-[rgba(15,23,42,0.48)]"
            data-atomic-fact-generation-dialog
            aria-labelledby="atomic-fact-generation-dialog-title"
            aria-describedby="atomic-fact-generation-dialog-message atomic-fact-generation-dialog-note"
        >
            <div class="flex max-h-[min(720px,calc(100dvh-2rem))] flex-col">
                <header class="flex items-start gap-4 border-b border-slate-100 px-6 pb-5 pt-6 max-[520px]:px-5">
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-700" data-atomic-fact-generation-icon-wrap aria-hidden="true">
                        <i data-lucide="sparkles" class="h-5 w-5" data-atomic-fact-generation-icon></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.knowledge_facts.dialog.eyebrow') }}</p>
                        <h2 id="atomic-fact-generation-dialog-title" class="mt-1 text-xl font-semibold leading-7 text-slate-950 text-balance" data-atomic-fact-generation-title>{{ __('admin.knowledge_facts.dialog.starting_title') }}</h2>
                        <p class="sr-only" data-atomic-fact-generation-announcement aria-live="polite" role="status"></p>
                    </div>
                    <button type="button" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-slate-500 transition-[background-color,color,transform] duration-150 hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 active:scale-[.96]" data-atomic-fact-generation-close aria-label="{{ __('admin.knowledge_facts.dialog.close') }}">
                        <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-6 max-[520px]:px-5">
                    <div class="flex items-start gap-3 rounded-xl bg-slate-50 px-4 py-4">
                        <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-orange-700 ring-1 ring-slate-200" data-atomic-fact-generation-status-icon aria-hidden="true">
                            <i data-lucide="loader-circle" class="h-4 w-4 animate-spin"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900" data-atomic-fact-generation-status>{{ __('admin.knowledge_facts.dialog.status.starting') }}</p>
                            <p id="atomic-fact-generation-dialog-message" class="mt-1 text-sm leading-6 text-slate-600" data-atomic-fact-generation-message>{{ __('admin.knowledge_facts.dialog.starting_message') }}</p>
                        </div>
                    </div>

                    <ol class="mt-6 space-y-4" aria-label="{{ __('admin.knowledge_facts.dialog.steps_label') }}">
                        @foreach (['prepare', 'extract', 'review'] as $index => $step)
                            <li class="flex items-start gap-3" data-atomic-fact-generation-step="{{ $step }}">
                                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 ring-1 ring-slate-200" data-atomic-fact-generation-step-marker>{{ $index + 1 }}</span>
                                <div class="pt-0.5">
                                    <p class="text-sm font-semibold text-slate-700" data-atomic-fact-generation-step-title>{{ __('admin.knowledge_facts.dialog.steps.'.$step.'.title') }}</p>
                                    <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ __('admin.knowledge_facts.dialog.steps.'.$step.'.description') }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4" data-atomic-fact-generation-metrics>
                        @foreach([['progress', '进度'], ['candidates', '候选事实'], ['conflicts', '冲突'], ['elapsed', '已用时间']] as [$key, $label])
                            <div class="rounded-lg border border-slate-200 bg-white p-3"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 text-sm font-bold text-slate-900" data-atomic-fact-generation-metric="{{ $key }}">0</dd></div>
                        @endforeach
                    </dl>

                    <p id="atomic-fact-generation-dialog-note" class="mt-6 rounded-lg border border-orange-100 bg-orange-50 px-3.5 py-3 text-xs leading-5 text-orange-900" data-atomic-fact-generation-note>{{ __('admin.knowledge_facts.dialog.background_note') }}</p>
                    <p class="mt-4 hidden rounded-lg bg-rose-50 px-3.5 py-3 text-sm leading-6 text-rose-800" data-atomic-fact-generation-error role="alert"></p>
                </div>

                <footer class="flex flex-wrap justify-end gap-2.5 border-t border-slate-100 bg-slate-50 px-6 py-4 max-[520px]:flex-col max-[520px]:px-5">
                    <button type="button" class="hidden min-h-10 items-center justify-center rounded-lg border border-rose-200 bg-white px-4 text-sm font-semibold text-rose-700 hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 max-[520px]:w-full" data-atomic-fact-generation-cancel>取消生成</button>
                    <button type="button" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 transition-[background-color,border-color,color,transform] duration-150 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-atomic-fact-generation-close>{{ __('admin.knowledge_facts.dialog.background_action') }}</button>
                    <button type="button" class="hidden min-h-10 items-center justify-center rounded-lg bg-orange-600 px-4 text-sm font-semibold text-white transition-[background-color,transform] duration-150 hover:bg-orange-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-atomic-fact-generation-review>{{ __('admin.knowledge_facts.dialog.review_action') }}</button>
                </footer>
            </div>
        </dialog>

        @php
            $atomicFactGenerationCopy = [
                'status' => __('admin.knowledge_facts.dialog.status'),
                'title' => __('admin.knowledge_facts.dialog.title'),
                'message' => __('admin.knowledge_facts.dialog.message'),
                'background_note' => __('admin.knowledge_facts.dialog.background_note'),
                'poll_unavailable' => __('admin.knowledge_facts.dialog.poll_unavailable'),
                'start_failed' => __('admin.knowledge_facts.dialog.start_failed'),
                'cancel_failed' => __('admin.knowledge_facts.dialog.cancel_failed'),
            ];
        @endphp
        <script type="application/json" data-atomic-fact-generation-copy>{!! json_encode($atomicFactGenerationCopy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endunless

    <div class="border-t border-slate-200 px-5 py-5 sm:px-6">
        <div class="space-y-4">
            @forelse(($factLibrary?->facts ?? collect()) as $fact)
                <article class="rounded-lg border border-slate-200">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-4">
                        <div><span class="font-semibold text-slate-900">{{ $fact->label }}</span><p class="mt-1 text-sm text-slate-600">{{ $fact->subject }} · {{ $fact->predicate }}</p></div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $fact->review_status === 'reviewed' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">{{ $fact->review_status === 'reviewed' ? '已审核' : '待审核' }}</span>
                    </div>

                    @unless($systemReadOnly)
                        <div class="flex flex-wrap gap-2 border-t border-slate-100 px-4 py-3">
                            <form method="POST" action="{{ route('admin.knowledge-bases.facts.review', [$knowledgeBase->id, $fact->id]) }}">@csrf<input type="hidden" name="lock_version" value="{{ $fact->lock_version }}"><input type="hidden" name="review_status" value="reviewed"><button class="min-h-10 rounded-lg border border-emerald-200 px-3 text-xs font-semibold text-emerald-700 active:scale-[.98]">{{ __('admin.knowledge_facts.mark_reviewed') }}</button></form>
                            <form method="POST" action="{{ route('admin.knowledge-bases.facts.archive', [$knowledgeBase->id, $fact->id]) }}">@csrf<input type="hidden" name="lock_version" value="{{ $fact->lock_version }}"><button class="min-h-10 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-600 active:scale-[.98]">{{ __('admin.knowledge_facts.archive') }}</button></form>
                            @if(($mergeTargets ?? collect())->where('id', '!=', $fact->id)->isNotEmpty())
                                <form method="POST" action="{{ route('admin.knowledge-bases.facts.merge', [$knowledgeBase->id, $fact->id]) }}" class="flex min-w-0 flex-col gap-2 sm:flex-row">@csrf<select name="target_fact_id" class="min-h-10 w-full min-w-0 rounded-lg border-slate-300 text-xs sm:max-w-72">@foreach(($mergeTargets ?? collect())->where('id', '!=', $fact->id) as $target)<option value="{{ $target->id }}">{{ $target->label }}</option>@endforeach</select><button class="min-h-10 shrink-0 rounded-lg border border-slate-200 px-3 text-xs font-semibold">{{ __('admin.knowledge_facts.merge') }}</button></form>
                            @endif
                        </div>
                    @endunless

                    <div class="space-y-3 border-t border-slate-100 bg-slate-50/70 px-4 py-4">
                        @foreach($fact->values as $value)
                            <div class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-slate-200">
                                <div class="flex flex-wrap items-start justify-between gap-2"><p class="max-w-3xl text-sm leading-6 text-slate-800">{{ $value->canonical_answer }}</p><span class="text-xs text-slate-500">{{ $value->review_status === 'reviewed' ? '标准值已审核' : '标准值待审核' }} · {{ $value->evidences_count }} 条证据</span></div>
                                <details class="mt-2 text-xs text-slate-500"><summary class="cursor-pointer font-semibold hover:text-slate-800">技术详情</summary><dl class="mt-2 grid gap-1 rounded-md bg-slate-50 p-3 sm:grid-cols-2"><div><dt>稳定键</dt><dd class="break-all font-mono">{{ $fact->stable_key }}</dd></div><div><dt>值类型</dt><dd class="font-mono">{{ $fact->value_type }}</dd></div><div><dt>锁版本</dt><dd class="font-mono">{{ $fact->lock_version }} / {{ $value->lock_version }}</dd></div><div><dt>原始值</dt><dd class="break-all font-mono">{{ json_encode($value->canonical_value_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd></div></dl></details>
                                @unless($systemReadOnly)
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('admin.knowledge-bases.fact-values.update', [$knowledgeBase->id, $value->id]) }}">@csrf @method('PUT')<input type="hidden" name="lock_version" value="{{ $value->lock_version }}"><input type="hidden" name="review_status" value="reviewed"><button class="min-h-10 rounded-lg border border-emerald-200 px-3 text-xs font-semibold text-emerald-700">{{ __('admin.knowledge_facts.mark_reviewed') }}</button></form>
                                        <form method="POST" action="{{ route('admin.knowledge-bases.fact-values.archive', [$knowledgeBase->id, $value->id]) }}">@csrf<input type="hidden" name="lock_version" value="{{ $value->lock_version }}"><button class="min-h-10 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-600">{{ __('admin.knowledge_facts.archive_value') }}</button></form>
                                        @if(($factEvidenceChunks ?? collect())->isNotEmpty())
                                            <form method="POST" action="{{ route('admin.knowledge-bases.fact-evidences.store', [$knowledgeBase->id, $value->id]) }}" class="flex min-w-0 flex-1 gap-2">@csrf<select name="knowledge_chunk_id" required class="min-h-10 min-w-0 flex-1 rounded-lg border-slate-300 text-xs">@foreach($factEvidenceChunks as $chunk)<option value="{{ $chunk->id }}">#{{ $chunk->id }} {{ Str::limit($chunk->section_path ?: $chunk->content_hash, 48) }}</option>@endforeach</select><label class="inline-flex min-h-10 items-center gap-1 text-xs"><input type="checkbox" name="is_primary" value="1" class="rounded border-slate-300 text-orange-600">primary</label><button class="min-h-10 rounded-lg border border-orange-200 px-3 text-xs font-semibold text-orange-700">{{ __('admin.knowledge_facts.add_evidence') }}</button></form>
                                        @endif
                                    </div>
                                @endunless
                            </div>
                        @endforeach

                        @unless($systemReadOnly)
                            <details class="rounded-lg border border-dashed border-slate-300 bg-white px-3 py-3">
                                <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ __('admin.knowledge_facts.add_value') }}</summary>
                                <form method="POST" action="{{ route('admin.knowledge-bases.fact-values.store', [$knowledgeBase->id, $fact->id]) }}" class="mt-3 grid gap-3 md:grid-cols-[1fr_8rem_1.4fr_auto]">@csrf<input name="canonical_value_json[value]" required placeholder="{{ __('admin.knowledge_facts.standard_value') }}" class="min-h-10 rounded-lg border-slate-300 text-sm"><input name="canonical_value_json[unit]" placeholder="{{ __('admin.knowledge_facts.unit') }}" class="min-h-10 rounded-lg border-slate-300 text-sm"><input name="canonical_answer" required placeholder="{{ __('admin.knowledge_facts.standard_answer') }}" class="min-h-10 rounded-lg border-slate-300 text-sm"><button class="min-h-10 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white">{{ __('admin.knowledge_facts.save') }}</button></form>
                            </details>
                            @if($fact->values->count() > 1)
                                <details class="rounded-lg border border-dashed border-slate-300 bg-white px-3 py-3">
                                    <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ __('admin.knowledge_facts.split') }}</summary>
                                    <form method="POST" action="{{ route('admin.knowledge-bases.facts.split', [$knowledgeBase->id, $fact->id]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">@csrf<input name="stable_key" required placeholder="company.new_metric" class="min-h-10 rounded-lg border-slate-300 text-sm"><input name="label" required placeholder="{{ __('admin.knowledge_facts.label') }}" class="min-h-10 rounded-lg border-slate-300 text-sm"><div class="flex flex-wrap gap-3 sm:col-span-2">@foreach($fact->values as $value)<label class="inline-flex min-h-10 items-center gap-2 text-xs text-slate-600"><input type="checkbox" name="value_ids[]" value="{{ $value->id }}" class="rounded border-slate-300 text-orange-600">#{{ $value->id }} {{ Str::limit($value->canonical_answer, 28) }}</label>@endforeach</div><button class="min-h-10 rounded-lg border border-slate-300 px-4 text-sm font-semibold sm:col-span-2">{{ __('admin.knowledge_facts.split_selected') }}</button></form>
                                </details>
                            @endif
                        @endunless
                    </div>
                </article>
            @empty
                <p class="rounded-lg bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">{{ __('admin.knowledge_facts.empty') }}</p>
            @endforelse
        </div>
    </div>

    @if(($factGenerationRuns ?? collect())->isNotEmpty() || ($factLibrary?->revisions->isNotEmpty() ?? false))
        <div class="grid gap-6 border-t border-slate-200 px-5 py-5 sm:px-6 lg:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">{{ __('admin.knowledge_facts.generation_runs') }}</h3>
                <div class="mt-3 space-y-2">
                    @foreach($factGenerationRuns as $run)
                        <div class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                            <div class="flex items-center justify-between gap-2"><span class="font-medium">{{ ['initial' => '首次生成', 'supplement' => '补充生成', 'refresh_stale' => '更新陈旧事实'][$run->mode] ?? '生成任务' }} · {{ ['queued' => '等待中', 'running' => '生成中', 'completed' => '已完成', 'partial' => '部分完成', 'failed' => '失败', 'cancelled' => '已取消', 'obsolete' => '需重新生成'][$run->status] ?? $run->status }}</span><span>目标 {{ $run->target_count }} 条</span></div>
                            @if($run->isActive() && !$systemReadOnly)<form method="POST" action="{{ route('admin.knowledge-bases.fact-generation.cancel', [$knowledgeBase->id, $run->id]) }}" class="mt-2">@csrf<button class="min-h-10 rounded-lg border border-rose-200 px-3 font-semibold text-rose-700">{{ __('admin.knowledge_facts.cancel') }}</button></form>@endif
                            @foreach((array) data_get($run->result_json, 'conflicts', []) as $candidate)
                                @php($candidateKey = $candidate['_candidate_key'] ?? hash('sha256', json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
                                <form method="POST" action="{{ route('admin.knowledge-bases.fact-generation.resolve', [$knowledgeBase->id, $run->id]) }}" class="mt-2 grid gap-2 rounded-md bg-white p-2 ring-1 ring-slate-200 sm:grid-cols-[1fr_11rem_auto]">@csrf<input type="hidden" name="candidate_key" value="{{ $candidateKey }}"><input name="stable_key" value="{{ $candidate['stable_key'] ?? '' }}" class="min-h-10 rounded-lg border-slate-300 text-xs"><select name="action" class="min-h-10 rounded-lg border-slate-300 text-xs"><option value="merge_as_value">merge_as_value</option><option value="create_with_new_key">create_with_new_key</option><option value="discard">discard</option></select><button class="min-h-10 rounded-lg bg-slate-900 px-3 font-semibold text-white">{{ __('admin.knowledge_facts.resolve') }}</button></form>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-slate-900">{{ __('admin.knowledge_facts.revisions') }}</h3>
                <div class="mt-3 space-y-2">@forelse(($factLibrary?->revisions ?? collect()) as $revision)<div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600"><span>v{{ $revision->version }} · {{ Str::limit($revision->library_hash, 18) }}</span>@unless($systemReadOnly)<form method="POST" action="{{ route('admin.knowledge-bases.fact-revisions.restore', [$knowledgeBase->id, $revision->id]) }}">@csrf<button class="min-h-10 rounded-lg border border-slate-200 px-3 font-semibold">{{ __('admin.knowledge_facts.restore') }}</button></form>@endunless</div>@empty<p class="text-sm text-slate-500">{{ __('admin.knowledge_facts.no_revisions') }}</p>@endforelse</div>
            </div>
        </div>
    @endif

    @if($factLibrary && !$systemReadOnly)
        <footer class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div><p class="text-sm font-semibold text-slate-800">发布准备度</p>@if(!($publishReadiness['ready'] ?? false))<ul class="mt-1 text-xs leading-5 text-amber-800">@foreach(($publishReadiness['blockers'] ?? []) as $blocker)<li>· {{ $blocker }}</li>@endforeach</ul>@else<p class="mt-1 text-xs text-emerald-700">事实、标准答案、冲突和证据检查均已通过。</p>@endif</div>
            <form method="POST" action="{{ route('admin.knowledge-bases.facts.publish', ['knowledgeBaseId' => $knowledgeBase->id]) }}">@csrf<button @disabled(!($publishReadiness['ready'] ?? false)) class="min-h-10 w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white active:scale-[.98] disabled:cursor-not-allowed disabled:bg-slate-300 sm:w-auto">{{ __('admin.knowledge_facts.publish') }}</button></form>
        </footer>
    @endif
</section>
