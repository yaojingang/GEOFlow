@extends('admin.layouts.app')

@php
    $riskMatches = is_array($publication->risk_result) ? ($publication->risk_result['matches'] ?? []) : [];
    $nextStatuses = \App\Models\ManualPublication::allowedNextStatuses((string) $publication->status);
    $statusClass = match((string) $publication->status) {
        'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        'in_progress' => 'bg-purple-50 text-purple-700 ring-purple-100',
        'ready' => 'bg-amber-50 text-amber-700 ring-amber-100',
        'failed', 'cancelled' => 'bg-red-50 text-red-700 ring-red-100',
        default => 'bg-gray-100 text-gray-700 ring-gray-200',
    };
@endphp

@section('content')
<div class="px-4 sm:px-0">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.manual_publications.detail_title', ['id' => $publication->id]) }}</h1>
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 {{ $statusClass }}">{{ __('admin.manual_publications.status.'.$publication->status) }}</span>
            </div>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.manual_publications.type.'.$publication->type) }} · {{ $publication->platformDisplayName() }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.manual-publications.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>{{ __('admin.manual_publications.button.back') }}
            </a>
            @if($canEdit)
                <a href="{{ route('admin.manual-publications.edit', ['manualPublicationId' => $publication->id]) }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    <i data-lucide="pencil" class="h-4 w-4"></i>{{ __('admin.manual_publications.button.edit') }}
                </a>
            @endif
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.section.publish_content') }}</h2>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.manual_publications.help.copy') }}</p>
                    </div>
                    <button type="button" data-copy-target="manual-publication-content" data-success-label="{{ __('admin.manual_publications.button.copied') }}" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700">
                        <i data-lucide="copy" class="h-4 w-4"></i><span>{{ __('admin.manual_publications.button.copy') }}</span>
                    </button>
                </div>
                <div class="p-6">
                    <textarea id="manual-publication-content" readonly rows="14" class="w-full rounded-lg border-gray-200 bg-gray-50 font-mono text-sm leading-6 text-gray-800">{{ $publication->content }}</textarea>
                    @if($publication->disclosure_snapshot)
                        <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-blue-700">{{ __('admin.manual_publications.field.disclosure') }}</div>
                            <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-blue-900">{{ $publication->disclosure_snapshot }}</p>
                        </div>
                    @endif
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.section.risk') }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.manual_publications.risk.'.$publication->risk_status) }}</p>
                    </div>
                    @if($publication->duplicate_warning_count > 0)
                        <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">{{ __('admin.manual_publications.duplicate_count', ['count' => $publication->duplicate_warning_count]) }}</span>
                    @endif
                </div>

                @if(empty($riskMatches))
                    <div class="mt-4 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ __('admin.manual_publications.risk_clean_help') }}</div>
                @else
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach($riskMatches as $match)
                            <div class="rounded-lg border {{ ($match['severity'] ?? '') === 'blocked' ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50' }} p-4">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-semibold text-gray-900">{{ $match['word'] ?? '' }}</span>
                                    <span class="text-xs text-gray-500">× {{ (int) ($match['count'] ?? 0) }}</span>
                                </div>
                                <p class="mt-2 break-words text-xs leading-5 text-gray-700">{{ $match['snippet'] ?? '' }}</p>
                                @if(!empty($match['suggestion']))
                                    <p class="mt-2 text-xs font-medium text-blue-700">{{ $match['suggestion'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($duplicates->isNotEmpty())
                    <div class="mt-5 border-t border-gray-200 pt-5">
                        <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.manual_publications.duplicate_title') }}</h3>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach($duplicates as $duplicate)
                                <a href="{{ route('admin.manual-publications.show', ['manualPublicationId' => $duplicate->id]) }}" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100">#{{ $duplicate->id }} · {{ __('admin.manual_publications.status.'.$duplicate->status) }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            @if($canTransition && $publication->status === \App\Models\ManualPublication::STATUS_IN_PROGRESS)
                <section class="rounded-xl border border-emerald-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.section.complete') }}</h2>
                    <form method="POST" action="{{ route('admin.manual-publications.transition', ['manualPublicationId' => $publication->id]) }}" class="mt-5 space-y-4">
                        @csrf
                        <input type="hidden" name="target_status" value="completed">
                        <input type="hidden" name="revision" value="{{ $publication->revision }}">
                        <div>
                            <label for="completion_url" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.completion_url') }} *</label>
                            <input id="completion_url" type="url" name="completion_url" required maxlength="1000" value="{{ old('completion_url', $publication->completion_url) }}" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="https://example.com/published">
                        </div>
                        <div>
                            <label for="result_note" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.result_note') }}</label>
                            <textarea id="result_note" name="result_note" rows="4" maxlength="5000" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('result_note', $publication->result_note) }}</textarea>
                        </div>
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            <i data-lucide="badge-check" class="h-4 w-4"></i>{{ __('admin.manual_publications.action.completed') }}
                        </button>
                    </form>
                </section>
            @endif
        </div>

        <div class="space-y-6">
            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.section.details') }}</h2>
                <dl class="mt-5 space-y-4 text-sm">
                    @foreach([
                        __('admin.manual_publications.field.article') => $publication->article?->title ?? __('admin.manual_publications.none'),
                        __('admin.manual_publications.field.persona') => $publication->personaDisplayName() ?? __('admin.manual_publications.none'),
                        __('admin.manual_publications.field.account') => $publication->accountDisplayName() ?? __('admin.manual_publications.none'),
                        __('admin.manual_publications.field.assignee') => $publication->assignee?->name ?? __('admin.manual_publications.unassigned'),
                        __('admin.manual_publications.field.scheduled_at') => $publication->scheduled_at?->format('Y-m-d H:i') ?? __('admin.manual_publications.unscheduled'),
                        __('admin.manual_publications.field.creator') => $publication->creator?->name ?? __('admin.manual_publications.none'),
                        __('admin.manual_publications.field.revision') => '#'.$publication->revision,
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</dt>
                            <dd class="mt-1 break-words font-medium text-gray-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                    @if($publication->target_url)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.manual_publications.field.target_url') }}</dt>
                            <dd class="mt-1 break-all"><a href="{{ $publication->target_url }}" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-600 hover:text-blue-800">{{ $publication->target_url }}</a></dd>
                        </div>
                    @endif
                    @if($publication->target_context)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.manual_publications.field.target_context') }}</dt>
                            <dd class="mt-1 whitespace-pre-wrap leading-6 text-gray-700">{{ $publication->target_context }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            @if($publication->completion_url || $publication->result_note)
                <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-6">
                    <h2 class="text-lg font-semibold text-emerald-900">{{ __('admin.manual_publications.section.result') }}</h2>
                    @if($publication->completion_url)
                        <a href="{{ $publication->completion_url }}" target="_blank" rel="noopener noreferrer" class="mt-3 block break-all text-sm font-semibold text-emerald-700 underline">{{ $publication->completion_url }}</a>
                    @endif
                    @if($publication->result_note)
                        <p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-emerald-900">{{ $publication->result_note }}</p>
                    @endif
                </section>
            @endif

            @if($publication->transitions->isNotEmpty())
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.section.history') }}</h2>
                    <div class="mt-5 space-y-4">
                        @foreach($publication->transitions->sortByDesc('id') as $transition)
                            <div class="border-l-2 border-blue-200 pl-4">
                                <div class="text-sm font-semibold text-gray-900">
                                    @if($transition->from_status)
                                        {{ __('admin.manual_publications.status.'.$transition->from_status) }} →
                                    @endif
                                    {{ __('admin.manual_publications.status.'.$transition->to_status) }}
                                </div>
                                <div class="mt-1 text-xs text-gray-500">
                                    {{ $transition->created_at?->format('Y-m-d H:i:s') }}
                                    @if($transition->actor)
                                        · {{ $transition->actor->name }}
                                    @endif
                                </div>
                                @if($transition->result_note)
                                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $transition->result_note }}</p>
                                @endif
                                @if($transition->completion_url)
                                    <a href="{{ $transition->completion_url }}" target="_blank" rel="noopener noreferrer" class="mt-2 block break-all text-sm font-medium text-blue-600 underline">{{ $transition->completion_url }}</a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($canTransition && !empty($nextStatuses))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.section.actions') }}</h2>
                    @if($publication->status === \App\Models\ManualPublication::STATUS_IN_PROGRESS)
                        <form method="POST" action="{{ route('admin.manual-publications.transition', ['manualPublicationId' => $publication->id]) }}" class="mt-4 space-y-3">
                            @csrf
                            <input type="hidden" name="revision" value="{{ $publication->revision }}">
                            <textarea name="result_note" rows="3" maxlength="5000" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.manual_publications.field.result_note') }}">{{ old('result_note') }}</textarea>
                            <div class="flex flex-wrap gap-2">
                                @foreach([\App\Models\ManualPublication::STATUS_FAILED, \App\Models\ManualPublication::STATUS_SKIPPED, \App\Models\ManualPublication::STATUS_CANCELLED] as $targetStatus)
                                    <button type="submit" name="target_status" value="{{ $targetStatus }}" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('admin.manual_publications.action.'.$targetStatus) }}</button>
                                @endforeach
                            </div>
                        </form>
                    @else
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($nextStatuses as $targetStatus)
                                @continue($targetStatus === \App\Models\ManualPublication::STATUS_COMPLETED)
                                @continue($publication->isReopenTransition($targetStatus) && !$canReopen)
                                <form method="POST" action="{{ route('admin.manual-publications.transition', ['manualPublicationId' => $publication->id]) }}">
                                    @csrf
                                    <input type="hidden" name="target_status" value="{{ $targetStatus }}">
                                    <input type="hidden" name="revision" value="{{ $publication->revision }}">
                                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('admin.manual_publications.action.'.$targetStatus) }}</button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </div>
</div>
@endsection
