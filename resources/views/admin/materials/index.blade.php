@extends('admin.layouts.app')

@section('content')
    @php
        $knowledgeBases = (int) ($stats['knowledge_bases'] ?? 0);
        $knowledgeChunks = (int) ($stats['knowledge_chunks'] ?? 0);
        $vectorizedChunks = (int) ($stats['vectorized_chunks'] ?? 0);
        $unvectorizedChunks = (int) ($stats['unvectorized_chunks'] ?? 0);
        $activeEmbeddingModels = (int) ($stats['active_embedding_models'] ?? 0);
        $vectorProgress = $knowledgeChunks > 0 ? min(100, (int) round(($vectorizedChunks / max(1, $knowledgeChunks)) * 100)) : 0;
        $knowledgeHealth = $knowledgeBases <= 0
            ? 'empty'
            : ($activeEmbeddingModels <= 0 ? 'no_embedding' : ($unvectorizedChunks > 0 ? 'needs_vectorization' : 'ready'));
        $knowledgeHealthStyles = [
            'ready' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'needs_vectorization' => 'bg-amber-50 text-amber-700 ring-amber-200',
            'no_embedding' => 'bg-red-50 text-red-700 ring-red-200',
            'empty' => 'bg-slate-100 text-slate-700 ring-slate-200',
        ];
        $strategyLabels = [
            'rule' => __('admin.materials.chunk_strategy_rule'),
            'auto' => __('admin.materials.chunk_strategy_auto'),
            'semantic_llm' => __('admin.materials.chunk_strategy_semantic_llm'),
        ];
        $foundationCards = [
            [
                'title' => __('admin.materials.keyword_manage_title'),
                'summary' => __('admin.materials.keywords_summary'),
                'icon' => 'key',
                'tone' => 'bg-blue-50 text-blue-600',
                'href' => route('admin.keyword-libraries.index'),
                'action' => __('admin.materials.manage_keyword_libraries'),
                'metrics' => [
                    __('admin.materials.keyword_library_count') => __('admin.materials.unit_libraries', ['count' => (int) $stats['keyword_libraries']]),
                    __('admin.materials.keyword_total_count') => __('admin.materials.unit_items', ['count' => (int) $stats['total_keywords']]),
                ],
            ],
            [
                'title' => __('admin.materials.title_manage_title'),
                'summary' => __('admin.materials.titles_summary'),
                'icon' => 'type',
                'tone' => 'bg-emerald-50 text-emerald-600',
                'href' => route('admin.title-libraries.index'),
                'action' => __('admin.materials.manage_title_libraries'),
                'metrics' => [
                    __('admin.materials.title_library_count') => __('admin.materials.unit_libraries', ['count' => (int) $stats['title_libraries']]),
                    __('admin.materials.title_total_count') => __('admin.materials.unit_items', ['count' => (int) $stats['total_titles']]),
                ],
            ],
            [
                'title' => __('admin.materials.image_manage_title'),
                'summary' => __('admin.materials.images_summary'),
                'icon' => 'image',
                'tone' => 'bg-purple-50 text-purple-600',
                'href' => route('admin.image-libraries.index'),
                'action' => __('admin.materials.manage_image_libraries'),
                'metrics' => [
                    __('admin.materials.image_library_count') => __('admin.materials.unit_libraries', ['count' => (int) $stats['image_libraries']]),
                    __('admin.materials.image_total_count') => __('admin.materials.unit_images', ['count' => (int) $stats['total_images']]),
                ],
            ],
            [
                'title' => __('admin.materials.author_manage_title'),
                'summary' => __('admin.materials.authors_summary'),
                'icon' => 'users',
                'tone' => 'bg-indigo-50 text-indigo-600',
                'href' => route('admin.authors.index'),
                'action' => __('admin.materials.manage_authors'),
                'metrics' => [
                    __('admin.materials.author_total_count') => __('admin.materials.author_count', ['count' => (int) $stats['authors']]),
                    __('admin.materials.author_usage_label') => __('admin.materials.author_usage_desc'),
                ],
            ],
        ];
        $evidenceCards = [
            [
                'title' => __('admin.materials.evidence_source_title'),
                'desc' => __('admin.materials.evidence_source_desc'),
                'value' => (int) ($stats['metadata_ready_count'] ?? 0).' / '.$knowledgeBases,
                'icon' => 'fingerprint',
                'tone' => 'bg-blue-50 text-blue-600',
            ],
            [
                'title' => __('admin.materials.evidence_review_title'),
                'desc' => __('admin.materials.evidence_review_desc'),
                'value' => (int) ($stats['reviewed_knowledge_bases'] ?? 0),
                'icon' => 'shield-check',
                'tone' => 'bg-emerald-50 text-emerald-600',
            ],
            [
                'title' => __('admin.materials.evidence_risk_title'),
                'desc' => __('admin.materials.evidence_risk_desc'),
                'value' => (int) ($stats['high_risk_pending_count'] ?? 0),
                'icon' => 'triangle-alert',
                'tone' => ((int) ($stats['high_risk_pending_count'] ?? 0) > 0 ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-700'),
            ],
            [
                'title' => __('admin.materials.evidence_vector_title'),
                'desc' => __('admin.materials.evidence_vector_desc'),
                'value' => $vectorizedChunks.' / '.$knowledgeChunks,
                'icon' => 'database-zap',
                'tone' => 'bg-orange-50 text-orange-600',
            ],
        ];
    @endphp

    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.materials.heading') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.materials.subtitle') }}</p>
        </div>

        <section class="mb-8 overflow-hidden rounded-lg border border-orange-100 bg-white shadow">
            <div class="border-b border-orange-100 bg-orange-50/50 px-6 py-5 lg:px-8">
                <div class="space-y-5">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-sm font-semibold text-orange-700 ring-1 ring-orange-200">
                            <i data-lucide="brain" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.materials.knowledge_hub_label') }}
                        </span>
                        <div class="grid w-full grid-cols-1 gap-3 sm:w-auto sm:grid-cols-2 lg:grid-cols-4 lg:min-w-[760px]">
                            <a href="{{ route('admin.knowledge-bases.create') }}" class="inline-flex items-center justify-center whitespace-nowrap rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-700">
                                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.materials.knowledge_hub_create') }}
                            </a>
                            <a href="{{ route('admin.enterprise-knowledge.create') }}" class="inline-flex items-center justify-center whitespace-nowrap rounded-md border border-orange-200 bg-white px-4 py-2 text-sm font-semibold text-orange-700 hover:bg-orange-50">
                                <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.materials.knowledge_hub_enterprise') }}
                            </a>
                            <a href="{{ route('admin.knowledge-bases.index') }}" class="inline-flex items-center justify-center whitespace-nowrap rounded-md border border-orange-200 bg-white px-4 py-2 text-sm font-semibold text-orange-700 hover:bg-orange-50">
                                <i data-lucide="database" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.materials.manage_knowledge_bases') }}
                            </a>
                            <a href="{{ route('admin.ai-models.index') }}" class="inline-flex items-center justify-center whitespace-nowrap rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                <i data-lucide="settings" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.materials.knowledge_hub_vector_config') }}
                            </a>
                        </div>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold tracking-tight text-gray-900">{{ __('admin.materials.knowledge_hub_title') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.materials.knowledge_hub_desc') }}</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 divide-y divide-gray-200 lg:grid-cols-[minmax(0,1.35fr)_minmax(360px,0.65fr)] lg:divide-x lg:divide-y-0">
                <div class="px-6 py-6 lg:px-8">
                    <div class="grid grid-cols-2 gap-x-8 gap-y-6 lg:grid-cols-4">
                        <div>
                            <div class="text-sm font-medium text-gray-500">{{ __('admin.materials.knowledge_base_count') }}</div>
                            <div class="mt-2 text-3xl font-bold text-gray-900">{{ $knowledgeBases }}</div>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-500">{{ __('admin.materials.knowledge_hub_chunks') }}</div>
                            <div class="mt-2 text-3xl font-bold text-gray-900">{{ $knowledgeChunks }}</div>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-500">{{ __('admin.materials.knowledge_hub_vectorized') }}</div>
                            <div class="mt-2 text-3xl font-bold text-emerald-700">{{ $vectorizedChunks }}</div>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-500">{{ __('admin.materials.knowledge_hub_used_by_tasks') }}</div>
                            <div class="mt-2 text-3xl font-bold text-gray-900">{{ (int) $stats['knowledge_usage_count'] }}</div>
                        </div>
                    </div>

                    <div class="mt-7">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-gray-700">{{ __('admin.materials.knowledge_hub_vector_progress') }}</span>
                            <span class="font-semibold text-gray-900">{{ $vectorizedChunks }} / {{ $knowledgeChunks }}</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100">
                            <div class="h-2 rounded-full bg-orange-500" style="width: {{ $vectorProgress }}%"></div>
                        </div>
                    </div>

                    <div class="mt-7 grid grid-cols-1 gap-4 border-t border-gray-100 pt-6 md:grid-cols-3 xl:grid-cols-6">
                        @foreach ([
                            ['icon' => 'file-input', 'title' => __('admin.materials.knowledge_flow_ingest'), 'desc' => __('admin.materials.knowledge_flow_ingest_desc')],
                            ['icon' => 'fingerprint', 'title' => __('admin.materials.knowledge_flow_evidence'), 'desc' => __('admin.materials.knowledge_flow_evidence_desc')],
                            ['icon' => 'scissors', 'title' => __('admin.materials.knowledge_flow_chunk'), 'desc' => __('admin.materials.knowledge_flow_chunk_desc')],
                            ['icon' => 'scan-search', 'title' => __('admin.materials.knowledge_flow_vector'), 'desc' => __('admin.materials.knowledge_flow_vector_desc')],
                            ['icon' => 'search-check', 'title' => __('admin.materials.knowledge_flow_recall'), 'desc' => __('admin.materials.knowledge_flow_recall_desc')],
                            ['icon' => 'wand-sparkles', 'title' => __('admin.materials.knowledge_flow_generate'), 'desc' => __('admin.materials.knowledge_flow_generate_desc')],
                        ] as $step)
                            <div class="min-w-0">
                                <div class="flex h-10 w-10 items-center justify-center rounded-md bg-orange-50 text-orange-600">
                                    <i data-lucide="{{ $step['icon'] }}" class="h-5 w-5"></i>
                                </div>
                                <div class="mt-3 text-sm font-semibold text-gray-900">{{ $step['title'] }}</div>
                                <p class="mt-1 text-xs leading-5 text-gray-500">{{ $step['desc'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-7">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.materials.evidence_layer_title') }}</h3>
                            <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('admin.materials.evidence_layer_desc') }}</p>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                            @foreach ($evidenceCards as $card)
                                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md {{ $card['tone'] }}">
                                            <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
                                        </div>
                                        <div class="text-right text-lg font-bold text-gray-900">{{ $card['value'] }}</div>
                                    </div>
                                    <h4 class="mt-4 text-sm font-semibold text-gray-900">{{ $card['title'] }}</h4>
                                    <p class="mt-1 text-xs leading-5 text-gray-500">{{ $card['desc'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="flex flex-col px-6 py-6 lg:min-h-full lg:px-8">
                    <div class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold ring-1 {{ $knowledgeHealthStyles[$knowledgeHealth] ?? $knowledgeHealthStyles['empty'] }}">
                        <i data-lucide="activity" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.materials.knowledge_health_'.$knowledgeHealth) }}
                    </div>

                    <dl class="mt-6 space-y-4 text-sm lg:space-y-5">
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-gray-500">{{ __('admin.materials.knowledge_hub_embedding_model') }}</dt>
                            <dd class="max-w-[220px] text-right font-semibold text-gray-900">{{ (string) ($stats['default_embedding_model'] ?? '') !== '' ? (string) $stats['default_embedding_model'] : __('admin.materials.knowledge_hub_embedding_missing') }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-gray-500">{{ __('admin.materials.knowledge_hub_chunk_strategy') }}</dt>
                            <dd class="text-right font-semibold text-gray-900">{{ $strategyLabels[(string) ($stats['chunk_strategy'] ?? 'rule')] ?? $strategyLabels['rule'] }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-gray-500">{{ __('admin.materials.knowledge_hub_retrieval_mode') }}</dt>
                            <dd class="text-right font-semibold text-gray-900">{{ __('admin.materials.knowledge_hub_retrieval_hybrid') }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-gray-500">{{ __('admin.materials.knowledge_hub_metadata_ready') }}</dt>
                            <dd class="text-right font-semibold text-gray-900">{{ (int) ($stats['metadata_ready_count'] ?? 0) }} / {{ $knowledgeBases }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-gray-500">{{ __('admin.materials.knowledge_hub_reviewed') }}</dt>
                            <dd class="text-right font-semibold text-gray-900">{{ (int) ($stats['reviewed_knowledge_bases'] ?? 0) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-gray-500">{{ __('admin.materials.knowledge_hub_high_risk_pending') }}</dt>
                            <dd class="text-right font-semibold {{ (int) ($stats['high_risk_pending_count'] ?? 0) > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ (int) ($stats['high_risk_pending_count'] ?? 0) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-gray-500">{{ __('admin.materials.knowledge_hub_unvectorized') }}</dt>
                            <dd class="text-right font-semibold {{ $unvectorizedChunks > 0 ? 'text-amber-700' : 'text-gray-900' }}">{{ $unvectorizedChunks }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-gray-500">{{ __('admin.materials.knowledge_hub_latest_update') }}</dt>
                            <dd class="text-right font-semibold text-gray-900">{{ (string) ($stats['latest_knowledge_updated_at'] ?? '') !== '' ? (string) $stats['latest_knowledge_updated_at'] : __('admin.materials.knowledge_hub_never_updated') }}</dd>
                        </div>
                    </dl>

                    <div class="mt-6 grid grid-cols-1 gap-3 border-t border-gray-100 pt-6 lg:mt-auto">
                        <a href="{{ route('admin.knowledge-bases.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.materials.knowledge_hub_refresh_chunks') }}
                        </a>
                        <a href="{{ route('admin.url-import') }}" class="inline-flex items-center justify-center rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100">
                            <i data-lucide="globe" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.materials.knowledge_hub_import_from_url') }}
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section class="mb-8">
            <div class="mb-4">
                <h2 class="text-xl font-bold text-gray-900">{{ __('admin.materials.foundation_title') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.materials.foundation_subtitle') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($foundationCards as $card)
                    <a href="{{ $card['href'] }}" class="block rounded-lg border border-gray-200 bg-white p-6 shadow transition hover:-translate-y-0.5 hover:border-gray-300 hover:shadow-md">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex h-12 w-12 items-center justify-center rounded-md {{ $card['tone'] }}">
                                <i data-lucide="{{ $card['icon'] }}" class="h-6 w-6"></i>
                            </div>
                            <i data-lucide="arrow-up-right" class="h-5 w-5 text-gray-300"></i>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-gray-900">{{ $card['title'] }}</h3>
                        <p class="mt-2 min-h-12 text-sm leading-6 text-gray-600">{{ $card['summary'] }}</p>
                        <div class="mt-5 space-y-2">
                            @foreach ($card['metrics'] as $label => $value)
                                <div class="flex items-center justify-between gap-4 text-sm">
                                    <span class="text-gray-500">{{ $label }}</span>
                                    <span class="shrink-0 font-semibold text-gray-900">{{ $value }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-5 text-sm font-semibold text-blue-600">{{ $card['action'] }}</div>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="mb-8 overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
            <div class="p-6 lg:p-8">
                <div class="max-w-5xl">
                    <span class="inline-flex items-center rounded-full bg-cyan-50 px-3 py-1 text-sm font-medium text-cyan-700">
                        <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.materials.url_import') }}
                    </span>
                    <h2 class="mt-5 text-2xl font-bold tracking-tight text-gray-900">{{ __('admin.materials.url_import_title') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-gray-600">{{ __('admin.materials.url_import_description') }}</p>
                </div>

                <form method="POST" action="{{ route('admin.url-import.store') }}" class="mt-7">
                    @csrf
                    <label for="quick_url_import_url" class="block text-sm font-semibold text-gray-800">{{ __('admin.materials.url_import_target_label') }}</label>
                    <div class="mt-3 flex flex-col gap-3 lg:flex-row">
                        <input
                            id="quick_url_import_url"
                            name="url"
                            type="text"
                            required
                            value="{{ old('url') }}"
                            placeholder="{{ __('admin.materials.url_import_placeholder') }}"
                            class="block min-h-14 w-full rounded-md border-gray-300 px-5 text-base shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                        @foreach (['knowledge', 'keywords', 'titles'] as $output)
                            <input type="hidden" name="outputs[]" value="{{ $output }}">
                        @endforeach
                        <button type="submit" class="inline-flex min-h-14 shrink-0 items-center justify-center rounded-md border border-transparent bg-blue-600 px-7 text-base font-semibold text-white shadow-sm hover:bg-blue-700">
                            <i data-lucide="globe" class="mr-2 h-5 w-5"></i>
                            {{ __('admin.materials.url_import_start') }}
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-gray-500">{{ __('admin.url_import.help.url_optional_scheme') }}</p>
                    @error('url')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </form>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <a href="{{ route('admin.url-import') }}" class="inline-flex items-center text-sm font-medium text-blue-600 hover:text-blue-800">
                        <i data-lucide="settings" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.url_import.section.new_job') }}
                    </a>
                    <a href="{{ route('admin.url-import.history') }}" class="inline-flex items-center text-sm font-medium text-gray-600 hover:text-gray-800">
                        <i data-lucide="history" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.materials.url_import_history') }}
                    </a>
                </div>
            </div>
        </section>
    </div>
@endsection
