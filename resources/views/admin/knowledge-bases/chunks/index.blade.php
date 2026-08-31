@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-7">
            <x-admin.v3.knowledge-base-subnav :knowledge-base="$knowledgeBase" active="chunks" />
        </div>

        <header class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex min-w-0 items-start gap-3 sm:gap-4">
                <a href="{{ route('admin.knowledge-bases.index') }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-slate-400 transition-[background-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-white [@media(hover:hover)]:hover:text-slate-700 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div class="min-w-0">
                    <h1 class="break-words text-2xl font-bold text-slate-950">{{ $knowledgeBase->name }}</h1>
                    <p class="mt-1 text-sm text-slate-600">{{ __('admin.knowledge_chunks.subtitle') }}</p>
                </div>
            </div>

            @unless ($systemReadOnly)
                <form method="POST" action="{{ route('admin.knowledge-bases.chunks.refresh', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}">
                    @csrf
                    <input type="hidden" name="redirect_to" value="{{ route('admin.knowledge-bases.chunks.index', ['knowledgeBaseId' => (int) $knowledgeBase->id], false) }}">
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-orange-200 bg-orange-50 px-4 text-sm font-semibold text-orange-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-orange-100 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600">
                        <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.knowledge_detail.resubmit_chunks') }}
                    </button>
                </form>
            @endunless
        </header>

        <section aria-label="{{ __('admin.knowledge_chunks.status_label') }}" class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="text-sm font-medium text-slate-500">{{ __('admin.knowledge_detail.chunk_count') }}</div>
                <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ number_format((int) ($chunkStats['chunk_count'] ?? 0)) }}</div>
            </div>
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="text-sm font-medium text-slate-500">{{ __('admin.knowledge_detail.vectorized_count') }}</div>
                <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ number_format((int) ($chunkStats['vectorized_count'] ?? 0)) }}</div>
            </div>
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="text-sm font-medium text-slate-500">{{ __('admin.knowledge_detail.updated_at') }}</div>
                <div class="mt-2 text-sm font-semibold tabular-nums text-slate-950">{{ optional($knowledgeBase->updated_at)->format('Y-m-d H:i:s') ?? '-' }}</div>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('admin.knowledge_chunks.list_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('admin.knowledge_chunks.list_desc') }}</p>
            </div>

            @if ($chunkRows->isEmpty())
                <div class="px-6 py-12 text-center">
                    <i data-lucide="blocks" class="mx-auto h-8 w-8 text-slate-300"></i>
                    <p class="mt-3 text-sm text-slate-500">{{ __('admin.knowledge_detail.chunk_preview_empty') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:px-6">{{ __('admin.knowledge_detail.chunk_index') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:px-6">{{ __('admin.knowledge_detail.chunk_status') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:px-6">{{ __('admin.knowledge_detail.chunk_length') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:px-6">{{ __('admin.knowledge_detail.chunk_tokens') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:px-6">{{ __('admin.knowledge_detail.chunk_embedding') }}</th>
                                <th class="min-w-[28rem] px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:px-6">{{ __('admin.knowledge_detail.chunk_preview_column') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($chunkRows as $chunkRow)
                                @php
                                    $isVectorized = $chunkRow['embedding_model_id'] !== null && (int) $chunkRow['embedding_dimensions'] > 0;
                                @endphp
                                <tr class="align-top">
                                    <td class="whitespace-nowrap px-5 py-4 text-sm font-semibold tabular-nums text-slate-950 sm:px-6">#{{ (int) $chunkRow['chunk_index'] }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm sm:px-6">
                                        <span class="inline-flex rounded-md px-2 py-1 text-xs font-semibold {{ $isVectorized ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                            {{ $isVectorized ? __('admin.knowledge_detail.chunk_status_vectorized') : __('admin.knowledge_detail.chunk_status_fallback') }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm tabular-nums text-slate-600 sm:px-6">{{ __('admin.knowledge_bases.text_unit', ['count' => (int) $chunkRow['content_length']]) }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm tabular-nums text-slate-600 sm:px-6">{{ number_format((int) $chunkRow['token_count']) }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-slate-600 sm:px-6">
                                        {{ $isVectorized
                                            ? __('admin.knowledge_detail.chunk_embedding_meta', ['model_id' => (int) $chunkRow['embedding_model_id'], 'dimensions' => (int) $chunkRow['embedding_dimensions']])
                                            : __('admin.knowledge_detail.chunk_embedding_none') }}
                                    </td>
                                    <td class="px-5 py-4 text-sm leading-6 text-slate-700 sm:px-6">
                                        <div class="mb-2 flex flex-wrap items-center gap-2">
                                            <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                                {{ __('admin.knowledge_detail.chunk_strategy_'.$chunkRow['chunk_strategy']) }}
                                            </span>
                                            @if ($chunkRow['chunk_title'] !== '')
                                                <span class="text-xs font-semibold text-slate-700">{{ $chunkRow['chunk_title'] }}</span>
                                            @endif
                                        </div>
                                        @if ($chunkRow['section_path'] !== '')
                                            <div class="mb-2 text-xs text-slate-500">{{ $chunkRow['section_path'] }}</div>
                                        @endif
                                        <div class="max-w-3xl break-words">{{ $chunkRow['content_preview'] }}</div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div class="mt-5">{{ $chunkRows->links() }}</div>
    </div>
@endsection
