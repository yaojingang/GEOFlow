@php
    $aiVisibilityLibraries = $keywordLibraries ?? collect();
    $collectSelectionCap = 50;
@endphp

<section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.ai_visibility.collect.panel_title') }}</h2>
    <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.collect.panel_desc') }}</p>

    @if ($aiVisibilityLibraries->isEmpty())
        <p class="mt-3 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.collect.empty_library') }}</p>
    @else
        <form method="POST" action="{{ route('admin.analytics.ai-visibility.collect') }}" class="mt-4 space-y-4">
            @csrf
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                @foreach ($aiVisibilityLibraries as $library)
                    @php
                        $libraryContainerId = 'ai-visibility-library-'.$library['id'];
                        $libraryKeywordCount = count($library['keywords']);
                        $libraryHasMoreThanCap = $libraryKeywordCount > $collectSelectionCap;
                    @endphp
                    <div class="rounded-md border border-gray-200">
                        <div class="flex items-center justify-between gap-2 px-4 py-2">
                            <span class="text-sm font-semibold text-gray-800">{{ $library['name'] }}（{{ $libraryKeywordCount }}）</span>
                            <div class="flex items-center gap-3">
                                <span class="text-xs text-gray-500" data-ai-visibility-counter="{{ $libraryContainerId }}">{{ __('admin.analytics.ai_visibility.collect.selection_counter', ['count' => 0, 'cap' => $collectSelectionCap]) }}</span>
                                <button type="button" class="text-xs font-medium text-violet-600 hover:text-violet-700"
                                    data-ai-visibility-select-all="{{ $libraryContainerId }}"
                                    data-ai-visibility-select-all-cap="{{ $collectSelectionCap }}"
                                    @if ($libraryHasMoreThanCap) title="{{ __('admin.analytics.ai_visibility.collect.select_all_hint_overflow', ['cap' => $collectSelectionCap]) }}" @endif
                                >{{ __('admin.analytics.ai_visibility.collect.select_all') }}</button>
                            </div>
                        </div>
                        <div id="{{ $libraryContainerId }}" class="flex flex-wrap gap-2 border-t border-gray-100 px-4 py-3">
                            @foreach ($library['keywords'] as $item)
                                @php $isSampled = ! empty($item['recently_sampled']); @endphp
                                <label class="inline-flex min-h-8 cursor-pointer items-center gap-2 rounded-md border px-2 text-sm has-[:checked]:border-violet-500 has-[:checked]:bg-violet-50 has-[:checked]:text-violet-700 {{ $isSampled ? 'border-emerald-200 bg-emerald-50/60 text-emerald-800' : 'border-gray-200 text-gray-700' }}"
                                    @if ($isSampled) data-ai-visibility-sampled="1" title="{{ __('admin.analytics.ai_visibility.collect.recently_sampled_hint') }}" @endif
                                >
                                    <input type="checkbox" name="keyword_ids[]" value="{{ $item['id'] }}" class="h-4 w-4 rounded border-gray-300 text-violet-600">
                                    <span>{{ $item['keyword'] }}</span>
                                    @if ($isSampled)
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-1.5 text-[10px] font-medium text-emerald-700">{{ __('admin.analytics.ai_visibility.collect.recently_sampled') }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3">
                <span class="text-xs text-gray-500" data-ai-visibility-global-counter data-ai-visibility-global-cap="{{ $collectSelectionCap }}">{{ __('admin.analytics.ai_visibility.collect.selection_counter', ['count' => 0, 'cap' => $collectSelectionCap]) }}</span>
                <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white transition duration-[120ms] hover:bg-violet-700 active:scale-[.98] motion-reduce:transition-none motion-reduce:active:scale-100">
                    <i data-lucide="radar" class="mr-2 h-4 w-4"></i>{{ __('admin.analytics.ai_visibility.collect.submit') }}
                </button>
            </div>
        </form>
    @endif
</section>

<script>
    (function () {
        var cap = {{ $collectSelectionCap }};
        var counterTemplate = @json(__('admin.analytics.ai_visibility.collect.selection_counter'));

        function updateCounter(scope) {
            var checked = scope.querySelectorAll('input[type="checkbox"]:checked').length;
            scope.querySelectorAll('[data-ai-visibility-counter]').forEach(function (el) {
                el.textContent = counterTemplate.replace(':count', String(checked)).replace(':cap', String(cap));
            });
        }

        function syncAllCounters() {
            document.querySelectorAll('[data-ai-visibility-counter]').forEach(function (el) {
                var containerId = el.getAttribute('data-ai-visibility-counter');
                var scope = document.getElementById(containerId);
                if (scope) { updateCounter(scope); }
            });
            var globalCounter = document.querySelector('[data-ai-visibility-global-counter]');
            if (globalCounter) {
                var total = document.querySelectorAll('input[name="keyword_ids[]"]:checked').length;
                globalCounter.textContent = counterTemplate.replace(':count', String(total)).replace(':cap', String(cap));
            }
        }

        document.querySelectorAll('[data-ai-visibility-select-all]').forEach(function (button) {
            button.addEventListener('click', function () {
                var container = document.getElementById(button.getAttribute('data-ai-visibility-select-all'));
                if (! container) { return; }
                var boxes = Array.from(container.querySelectorAll('input[type="checkbox"]'));
                var limit = Math.min(cap, boxes.length);
                boxes.forEach(function (box, index) { box.checked = index < limit; });
                syncAllCounters();
            });
        });

        document.querySelectorAll('input[name="keyword_ids[]"]').forEach(function (box) {
            box.addEventListener('change', syncAllCounters);
        });
    })();
</script>