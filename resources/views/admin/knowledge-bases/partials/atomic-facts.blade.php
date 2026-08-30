<section id="atomic-facts" class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ __('admin.knowledge_facts.title') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('admin.knowledge_facts.description') }}</p>
        </div>
        @if($factLibrary)
            <div class="flex items-center gap-2 text-xs text-slate-500">
                <span>{{ __('admin.knowledge_facts.working_version') }} {{ $factLibrary->working_version }}</span>
                <span class="rounded-full bg-slate-100 px-2 py-1">{{ $factLibrary->serving_status }}</span>
            </div>
        @endif
    </div>

    @if(!$systemReadOnly)
        <form method="POST" action="{{ route('admin.knowledge-bases.facts.store', ['knowledgeBaseId' => $knowledgeBase->id]) }}" class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            @csrf
            <input name="stable_key" required placeholder="company.founded_at" class="rounded-lg border-slate-300 text-sm">
            <input name="label" required placeholder="{{ __('admin.knowledge_facts.label') }}" class="rounded-lg border-slate-300 text-sm">
            <input name="subject" required placeholder="{{ __('admin.knowledge_facts.subject') }}" class="rounded-lg border-slate-300 text-sm">
            <input name="predicate" required placeholder="{{ __('admin.knowledge_facts.predicate') }}" class="rounded-lg border-slate-300 text-sm">
            <div class="flex gap-2">
                <select name="value_type" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm"><option value="string">string</option><option value="integer">integer</option><option value="decimal">decimal</option><option value="date">date</option><option value="boolean">boolean</option></select>
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">{{ __('admin.knowledge_facts.add') }}</button>
            </div>
        </form>
    @endif

    <div class="mt-5 space-y-3">
        @forelse(($factLibrary?->facts ?? collect()) as $fact)
            <article class="rounded-lg border border-slate-200 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div><span class="font-medium text-slate-900">{{ $fact->label }}</span><code class="ml-2 text-xs text-slate-500">{{ $fact->stable_key }}</code></div>
                    <span class="text-xs text-slate-500">{{ $fact->review_status }} · v{{ $fact->lock_version }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-600">{{ $fact->subject }} · {{ $fact->predicate }}</p>
                @foreach($fact->values as $value)
                    <div class="mt-3 rounded-md bg-slate-50 p-3 text-sm text-slate-700">
                        <p>{{ $value->canonical_answer }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ json_encode($value->canonical_value_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }} · {{ $value->evidences->count() }} evidence</p>
                    </div>
                @endforeach
            </article>
        @empty
            <p class="rounded-lg bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">{{ __('admin.knowledge_facts.empty') }}</p>
        @endforelse
    </div>

    @if($factLibrary && !$systemReadOnly && $factLibrary->facts->isNotEmpty())
        <form method="POST" action="{{ route('admin.knowledge-bases.facts.publish', ['knowledgeBaseId' => $knowledgeBase->id]) }}" class="mt-5">
            @csrf
            <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white">{{ __('admin.knowledge_facts.publish') }}</button>
        </form>
    @endif
</section>
