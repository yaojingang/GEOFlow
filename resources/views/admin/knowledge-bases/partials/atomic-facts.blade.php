<section id="atomic-facts" class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" lang="{{ str_starts_with(app()->getLocale(), 'zh') ? 'zh' : app()->getLocale() }}">
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
                        @foreach(['string', 'integer', 'decimal', 'number', 'date', 'boolean', 'url'] as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach
                    </select>
                    <button class="min-h-10 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white active:scale-[.98]">{{ __('admin.knowledge_facts.add') }}</button>
                </form>
            </div>

            <div class="border-t border-slate-200 pt-5 xl:border-l xl:border-t-0 xl:pl-6 xl:pt-0">
                <h3 class="text-sm font-semibold text-slate-900">{{ __('admin.knowledge_facts.ai_generation') }}</h3>
                <form method="POST" action="{{ route('admin.knowledge-bases.fact-generation.store', ['knowledgeBaseId' => $knowledgeBase->id]) }}" class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ (string) Str::uuid() }}">
                    <select name="ai_model_id" required class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                        <option value="">{{ __('admin.knowledge_facts.select_model') }}</option>
                        @foreach($factGenerationModels ?? collect() as $model)<option value="{{ $model->id }}">{{ $model->name }} · {{ $model->model_id }}</option>@endforeach
                    </select>
                    <div class="grid grid-cols-[1fr_7rem] gap-3">
                        <select name="mode" class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500"><option value="initial">initial</option><option value="supplement">supplement</option><option value="refresh_stale">refresh_stale</option></select>
                        <input name="target_count" type="number" min="1" max="200" value="50" required class="min-h-10 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500" aria-label="{{ __('admin.knowledge_facts.target_count') }}">
                    </div>
                    <button class="min-h-10 rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white active:scale-[.98] sm:col-span-2 xl:col-span-1">{{ __('admin.knowledge_facts.start_generation') }}</button>
                </form>
            </div>
        </div>
    @endunless

    <div class="border-t border-slate-200 px-5 py-5 sm:px-6">
        <div class="space-y-4">
            @forelse(($factLibrary?->facts ?? collect()) as $fact)
                <article class="rounded-lg border border-slate-200">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-4">
                        <div><div class="flex flex-wrap items-center gap-2"><span class="font-semibold text-slate-900">{{ $fact->label }}</span><code class="text-xs text-slate-500">{{ $fact->stable_key }}</code></div><p class="mt-1 text-sm text-slate-600">{{ $fact->subject }} · {{ $fact->predicate }} · {{ $fact->value_type }}</p></div>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">{{ $fact->review_status }} · v{{ $fact->lock_version }}</span>
                    </div>

                    @unless($systemReadOnly)
                        <div class="flex flex-wrap gap-2 border-t border-slate-100 px-4 py-3">
                            <form method="POST" action="{{ route('admin.knowledge-bases.facts.review', [$knowledgeBase->id, $fact->id]) }}">@csrf<input type="hidden" name="lock_version" value="{{ $fact->lock_version }}"><input type="hidden" name="review_status" value="reviewed"><button class="min-h-10 rounded-lg border border-emerald-200 px-3 text-xs font-semibold text-emerald-700 active:scale-[.98]">{{ __('admin.knowledge_facts.mark_reviewed') }}</button></form>
                            <form method="POST" action="{{ route('admin.knowledge-bases.facts.archive', [$knowledgeBase->id, $fact->id]) }}">@csrf<input type="hidden" name="lock_version" value="{{ $fact->lock_version }}"><button class="min-h-10 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-600 active:scale-[.98]">{{ __('admin.knowledge_facts.archive') }}</button></form>
                            @if(($factLibrary?->facts->count() ?? 0) > 1)
                                <form method="POST" action="{{ route('admin.knowledge-bases.facts.merge', [$knowledgeBase->id, $fact->id]) }}" class="flex gap-2">@csrf<select name="target_fact_id" class="min-h-10 rounded-lg border-slate-300 text-xs">@foreach($factLibrary->facts->where('id', '!=', $fact->id) as $target)<option value="{{ $target->id }}">{{ $target->label }}</option>@endforeach</select><button class="min-h-10 rounded-lg border border-slate-200 px-3 text-xs font-semibold">{{ __('admin.knowledge_facts.merge') }}</button></form>
                            @endif
                        </div>
                    @endunless

                    <div class="space-y-3 border-t border-slate-100 bg-slate-50/70 px-4 py-4">
                        @foreach($fact->values as $value)
                            <div class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-slate-200">
                                <div class="flex flex-wrap items-start justify-between gap-2"><p class="max-w-3xl text-sm leading-6 text-slate-800">{{ $value->canonical_answer }}</p><span class="text-xs text-slate-500">{{ $value->review_status }} · {{ $value->evidences_count }} evidence</span></div>
                                <p class="mt-1 break-all font-mono text-xs text-slate-500">{{ json_encode($value->canonical_value_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</p>
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
                            <div class="flex items-center justify-between gap-2"><span class="font-medium">#{{ $run->id }} · {{ $run->mode }} · {{ $run->status }}</span><span>{{ $run->target_count }}</span></div>
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

    @if($factLibrary && !$systemReadOnly && $factLibrary->facts->where('is_enabled', true)->isNotEmpty())
        <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6"><form method="POST" action="{{ route('admin.knowledge-bases.facts.publish', ['knowledgeBaseId' => $knowledgeBase->id]) }}">@csrf<button class="min-h-10 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white active:scale-[.98]">{{ __('admin.knowledge_facts.publish') }}</button></form></footer>
    @endif
</section>
