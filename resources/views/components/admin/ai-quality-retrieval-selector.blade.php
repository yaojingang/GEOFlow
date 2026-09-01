@props([
    'id' => 'ai-quality-retrieval-mode',
    'name' => 'ai_quality_retrieval_mode',
    'value' => null,
    'selectedKnowledgeBaseIds' => [],
    'readinessByKnowledgeBase' => [],
    'knowledgeInputSelector' => '',
    'allowInherit' => false,
    'inheritedMode' => null,
    'readonly' => false,
    'compact' => false,
    'persisted' => false,
    'sourceLabel' => null,
    'lastEffectiveMode' => null,
])

@php
    $modes = \App\Support\GeoFlow\AiQualityRetrievalMode::values();
    $selectedIds = collect($selectedKnowledgeBaseIds)->map(static fn ($item): string => (string) $item)->filter()->unique()->values();
    $states = [];
    foreach ($modes as $mode) {
        $blockers = [];
        if ($selectedIds->isEmpty()) {
            $blockers[] = __('ai_quality_retrieval.help');
        }
        foreach ($selectedIds as $knowledgeBaseId) {
            $row = $readinessByKnowledgeBase[$knowledgeBaseId] ?? null;
            $modeState = is_array($row) ? ($row['modes'][$mode] ?? null) : null;
            if (is_array($modeState) && ($modeState['available'] ?? false)) {
                continue;
            }
            $nameLabel = trim((string) ($row['name'] ?? ''));
            foreach ((array) ($modeState['blockers'] ?? [['message' => __('ai_quality_retrieval.unavailable')]]) as $blocker) {
                $message = trim((string) ($blocker['message'] ?? __('ai_quality_retrieval.unavailable')));
                $blockers[] = $nameLabel === '' ? $message : $nameLabel.'：'.$message;
            }
        }
        $states[$mode] = [
            'available' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
        ];
    }
    $selectedValue = is_string($value) ? $value : '';
    $selectedModeAvailable = isset($states[$selectedValue]) && (bool) $states[$selectedValue]['available'];
    if (! $allowInherit && (($selectedValue === '' && ! $persisted) || ($selectedValue !== '' && ! $selectedModeAvailable))) {
        $selectedValue = collect($modes)->first(static fn (string $mode): bool => $states[$mode]['available']) ?? '';
    }
    $rootClasses = $compact
        ? 'rounded-lg border border-gray-200 bg-gray-50 px-4 py-4'
        : 'rounded-lg border border-gray-200 bg-gray-50 px-4 py-5';
@endphp

<fieldset
    id="{{ $id }}"
    {{ $attributes->class($rootClasses) }}
    data-ai-quality-retrieval-selector
    data-knowledge-input-selector="{{ $knowledgeInputSelector }}"
    data-persisted="{{ $persisted ? 'true' : 'false' }}"
    data-readonly="{{ $readonly ? 'true' : 'false' }}"
    data-allow-inherit="{{ $allowInherit ? 'true' : 'false' }}"
    data-selected-knowledge-base-ids="{{ $selectedIds->implode(',') }}"
    data-empty-selection-label="{{ __('ai_quality_retrieval.select_knowledge_base') }}"
    data-unavailable-label="{{ __('ai_quality_retrieval.unavailable') }}"
    data-selection-unavailable-label="{{ __('ai_quality_retrieval.selection_unavailable') }}"
>
    <input type="hidden" name="{{ $name }}_touched" value="{{ $persisted ? '1' : '0' }}" data-retrieval-mode-touched>
    <legend class="px-1 text-sm font-semibold text-gray-900">{{ __('ai_quality_retrieval.title') }}</legend>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <p class="max-w-3xl text-xs leading-5 text-gray-600">{{ __('ai_quality_retrieval.help') }}</p>
        @if($sourceLabel)
            <span class="shrink-0 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-200">{{ $sourceLabel }}</span>
        @endif
    </div>

    <div @class(['mt-4 grid grid-cols-1 gap-3', 'lg:grid-cols-3' => ! $allowInherit, 'lg:grid-cols-4' => $allowInherit])>
        @if($allowInherit)
            @php
                $inheritInputId = $id.'-inherit';
                $inheritTitleId = $inheritInputId.'-title';
                $inheritStatusId = $inheritInputId.'-status';
                $inheritHelpId = $inheritInputId.'-help';
            @endphp
            <div data-retrieval-mode-card data-mode="" class="relative min-h-20 rounded-lg border border-gray-200 bg-white transition-[background-color,border-color] duration-150 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 motion-reduce:transition-none">
                <label for="{{ $inheritInputId }}" data-retrieval-mode-choice class="flex min-h-20 gap-3 px-4 py-3 pr-12 {{ $readonly ? 'cursor-default' : 'cursor-pointer active:scale-[.99]' }}">
                    <input id="{{ $inheritInputId }}" type="radio" name="{{ $name }}" value="" @checked($selectedValue === '') @disabled($readonly) aria-describedby="{{ $inheritStatusId }}" class="mt-0.5 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed" data-retrieval-mode-input>
                    <span class="min-w-0">
                        <span id="{{ $inheritTitleId }}" class="block text-sm font-semibold text-gray-900">{{ __('ai_quality_retrieval.inherit') }}</span>
                        <span id="{{ $inheritStatusId }}" class="mt-2 block text-xs font-medium text-blue-700">
                            {{ $inheritedMode ? __('ai_quality_retrieval.modes.'.$inheritedMode.'.label') : __('ai_quality_retrieval.available') }}
                        </span>
                    </span>
                </label>
                <div class="absolute right-3 top-3" data-retrieval-mode-help>
                    <button
                        type="button"
                        class="flex h-6 w-6 cursor-help items-center justify-center text-gray-500 transition-[color,transform] duration-150 hover:text-blue-700 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 active:scale-95 motion-reduce:transition-none"
                        aria-label="{{ __('ai_quality_retrieval.details', ['mode' => __('ai_quality_retrieval.inherit')]) }}"
                        aria-controls="{{ $inheritHelpId }}"
                        aria-expanded="false"
                        data-retrieval-mode-help-trigger
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5" />
                            <path d="M7.9 7.4a2.2 2.2 0 0 1 4.2.9c0 1.7-2.1 1.8-2.1 3.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                            <circle cx="10" cy="14" r=".8" fill="currentColor" />
                        </svg>
                    </button>
                    <div id="{{ $inheritHelpId }}" class="absolute right-0 top-8 z-30 w-72 max-w-[calc(100vw-3rem)] rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg" role="region" aria-labelledby="{{ $inheritTitleId }}" data-retrieval-mode-help-panel hidden>
                        <p class="text-xs leading-5 text-gray-700">{{ __('ai_quality_retrieval.inherit_help') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @foreach($modes as $mode)
            @php
                $available = (bool) $states[$mode]['available'];
                $blockerText = implode('；', $states[$mode]['blockers']);
                $disabled = $readonly || ! $available;
                $inputId = $id.'-'.$mode;
                $titleId = $inputId.'-title';
                $statusId = $inputId.'-status';
                $helpId = $inputId.'-help';
            @endphp
            <div
                data-retrieval-mode-card
                data-mode="{{ $mode }}"
                @class([
                    'relative min-h-20 rounded-lg border transition-[background-color,border-color] duration-150 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 motion-reduce:transition-none',
                    'border-gray-200 bg-white hover:border-blue-300' => $available,
                    'border-gray-200 bg-gray-50' => ! $available,
                ])
            >
                <label
                    for="{{ $inputId }}"
                    data-retrieval-mode-choice
                    @class([
                        'flex min-h-20 gap-3 px-4 py-3 pr-12',
                        'cursor-pointer active:scale-[.99]' => ! $disabled,
                        'cursor-default' => $readonly && $available,
                        'cursor-not-allowed' => ! $available,
                    ])
                >
                    <input
                        id="{{ $inputId }}"
                        type="radio"
                        name="{{ $name }}"
                        value="{{ $mode }}"
                        @checked($selectedValue === $mode)
                        @disabled($disabled)
                        aria-describedby="{{ $statusId }}"
                        class="mt-0.5 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed"
                        data-retrieval-mode-input
                    >
                    <span class="min-w-0">
                        <span id="{{ $titleId }}" class="block text-sm font-semibold {{ $available ? 'text-gray-900' : 'text-gray-600' }}" data-retrieval-mode-title>{{ __('ai_quality_retrieval.modes.'.$mode.'.label') }}</span>
                        <span
                            id="{{ $statusId }}"
                            class="mt-2 block text-xs font-medium {{ $available ? 'text-emerald-700' : 'text-gray-500' }}"
                            data-retrieval-mode-status
                            data-available-label="{{ __('ai_quality_retrieval.available') }}"
                            data-unavailable-label="{{ __('ai_quality_retrieval.unavailable') }}"
                        >{{ $available ? __('ai_quality_retrieval.available') : __('ai_quality_retrieval.unavailable') }}</span>
                    </span>
                </label>
                <div class="absolute right-3 top-3" data-retrieval-mode-help>
                    <button
                        type="button"
                        class="flex h-6 w-6 cursor-help items-center justify-center text-gray-500 transition-[color,transform] duration-150 hover:text-blue-700 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 active:scale-95 motion-reduce:transition-none"
                        aria-label="{{ __('ai_quality_retrieval.details', ['mode' => __('ai_quality_retrieval.modes.'.$mode.'.label')]) }}"
                        aria-controls="{{ $helpId }}"
                        aria-expanded="false"
                        data-retrieval-mode-help-trigger
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5" />
                            <path d="M7.9 7.4a2.2 2.2 0 0 1 4.2.9c0 1.7-2.1 1.8-2.1 3.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                            <circle cx="10" cy="14" r=".8" fill="currentColor" />
                        </svg>
                    </button>
                    <div id="{{ $helpId }}" class="absolute right-0 top-8 z-30 w-72 max-w-[calc(100vw-3rem)] rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg" role="region" aria-labelledby="{{ $titleId }}" data-retrieval-mode-help-panel hidden>
                        <span class="inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700">{{ __('ai_quality_retrieval.modes.'.$mode.'.badge') }}</span>
                        <p class="mt-2 text-xs leading-5 text-gray-700">{{ __('ai_quality_retrieval.modes.'.$mode.'.description') }}</p>
                        <div class="mt-3 border-t border-gray-100 pt-3" data-retrieval-mode-blockers-wrapper @if($available) hidden @endif>
                            <p class="text-[11px] font-semibold text-gray-700">{{ __('ai_quality_retrieval.unavailable_reasons') }}</p>
                            <p class="mt-1 max-h-40 overflow-y-auto break-words text-xs leading-5 text-gray-600" data-retrieval-mode-blockers>{{ $blockerText }}</p>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @error($name)
        <p class="mt-3 text-sm text-red-600" role="alert">{{ $message }}</p>
    @enderror
    <p class="mt-3 text-xs text-gray-600" aria-live="polite" data-retrieval-mode-live></p>
    @if($lastEffectiveMode)
        <p class="mt-2 text-xs font-medium text-gray-700">
            {{ __('ai_quality_retrieval.current_execution', ['mode' => __('ai_quality_retrieval.modes.'.$lastEffectiveMode.'.label')]) }}
        </p>
    @endif

    <script type="application/json" data-retrieval-readiness-map>@json($readinessByKnowledgeBase)</script>
</fieldset>
