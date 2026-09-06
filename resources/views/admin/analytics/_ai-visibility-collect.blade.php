@php
    $aiVisibilityLibraries = $keywordLibraries ?? collect();
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
                    <div class="rounded-md border border-gray-200">
                        <div class="flex items-center justify-between px-4 py-2">
                            <span class="text-sm font-semibold text-gray-800">{{ $library['name'] }}（{{ count($library['keywords']) }}）</span>
                            <button type="button" class="text-xs font-medium text-violet-600 hover:text-violet-700" data-ai-visibility-select-all="ai-visibility-library-{{ $library['id'] }}">{{ __('admin.analytics.ai_visibility.collect.select_all') }}</button>
                        </div>
                        <div id="ai-visibility-library-{{ $library['id'] }}" class="flex flex-wrap gap-2 border-t border-gray-100 px-4 py-3">
                            @foreach ($library['keywords'] as $item)
                                <label class="inline-flex min-h-8 cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-2 text-sm text-gray-700 has-[:checked]:border-violet-500 has-[:checked]:bg-violet-50 has-[:checked]:text-violet-700">
                                    <input type="checkbox" name="keyword_ids[]" value="{{ $item['id'] }}" class="h-4 w-4 rounded border-gray-300 text-violet-600">
                                    {{ $item['keyword'] }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-end">
                <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white transition duration-[120ms] hover:bg-violet-700 active:scale-[.98] motion-reduce:transition-none motion-reduce:active:scale-100">
                    <i data-lucide="radar" class="mr-2 h-4 w-4"></i>{{ __('admin.analytics.ai_visibility.collect.submit') }}
                </button>
            </div>
        </form>
    @endif
</section>

<script>
    document.querySelectorAll('[data-ai-visibility-select-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            var container = document.getElementById(button.getAttribute('data-ai-visibility-select-all'));
            if (! container) { return; }
            var boxes = container.querySelectorAll('input[type="checkbox"]');
            var allChecked = Array.prototype.every.call(boxes, function (box) { return box.checked; });
            boxes.forEach(function (box) { box.checked = ! allChecked; });
        });
    });
</script>
