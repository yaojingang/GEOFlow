@php
    $isCreate = ($mode ?? 'create') === 'create';
    $normalizeScalar = static function (mixed $value, string $fallback = ''): string {
        return is_string($value) || is_int($value) || is_float($value)
            ? (string) $value
            : $fallback;
    };
    $promptNameFallback = $isCreate ? '' : (string) $prompt->name;
    $promptContentFallback = $isCreate ? '' : (string) $prompt->content;
    $promptName = $normalizeScalar(old('name', $promptNameFallback), $promptNameFallback);
    $promptContent = $normalizeScalar(old('content', $promptContentFallback), $promptContentFallback);
    $promptTypeFallback = $isCreate ? 'content' : (string) $prompt->type;
    $promptType = $normalizeScalar(old('type', $promptTypeFallback), $promptTypeFallback);
    $isSystemPrompt = ! $isCreate && filled($prompt->system_key ?? null);
@endphp

<div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_19rem] lg:gap-8">
    <div class="min-w-0 space-y-6">
        @if ($isSystemPrompt)
            <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                系统内置质检方案 · v{{ $prompt->system_version ?: '1.0.0' }}。该方案保持只读，可复制内容创建自定义方案。
            </div>
        @endif
        @if ($isCreate)
            <div>
                <label for="prompt_type" class="block text-sm font-semibold text-gray-800">提示词类型</label>
                <select name="type" id="prompt_type" class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500">
                    <option value="content" @selected($promptType === 'content')>正文生成提示词</option>
                    <option value="quality_check" @selected($promptType === 'quality_check')>AI 质检方案</option>
                </select>
                <p class="mt-2 text-xs leading-5 text-gray-500">AI 质检方案可在任务管理中启用，并对每篇文章执行结构化检查和评分。</p>
            </div>
        @else
            <input type="hidden" name="type" value="{{ $promptType }}">
        @endif
        <div>
            <label for="prompt_name" class="block text-sm font-semibold text-gray-800">{{ __('admin.ai_prompts.field_name') }}</label>
            <input
                type="text"
                name="name"
                id="prompt_name"
                value="{{ $promptName }}"
                required
                autofocus
                @readonly($isSystemPrompt)
                @error('name') aria-invalid="true" aria-describedby="prompt_name_error" @enderror
                class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500"
                placeholder="{{ __('admin.ai_prompts.placeholder_name') }}"
            >
            @error('name')
                <p id="prompt_name_error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="prompt_content" class="block text-sm font-semibold text-gray-800">{{ __('admin.ai_prompts.field_content') }}</label>
            <textarea
                name="content"
                id="prompt_content"
                required
                rows="17"
                @readonly($isSystemPrompt)
                @error('content') aria-invalid="true" aria-describedby="prompt_content_error" @enderror
                class="mt-2 block min-h-96 w-full resize-y rounded-lg border-gray-300 px-3 py-3 text-sm leading-7 text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500"
                placeholder="{{ __('admin.ai_prompts.placeholder_content') }}"
            >{{ $promptContent }}</textarea>
            @error('content')
                <p id="prompt_content_error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <aside class="h-fit min-w-0 rounded-xl bg-blue-50 p-5 text-blue-900 ring-1 ring-inset ring-blue-200" aria-labelledby="prompt-variables-title">
        <div class="flex items-start gap-3">
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-blue-700 shadow-sm">
                <i data-lucide="braces" class="h-4 w-4"></i>
            </span>
            <div class="min-w-0">
                <h2 id="prompt-variables-title" class="text-sm font-semibold">{{ __('admin.ai_prompts.variable_title') }}</h2>
                <p class="mt-1 break-words text-xs leading-5 text-blue-700">{{ __('admin.ai_prompts.placeholder_content') }}</p>
            </div>
        </div>

        <div class="mt-5 space-y-3 break-words text-sm leading-6 text-blue-900">
            @if ($promptType === 'quality_check')
                <div><code>&#123;&#123;article_title&#125;&#125;</code>、<code>&#123;&#123;article_content&#125;&#125;</code>、<code>&#123;&#123;article_outline&#125;&#125;</code></div>
                <div><code>&#123;&#123;fact_candidates&#125;&#125;</code>、<code>&#123;&#123;knowledge&#125;&#125;</code>、<code>&#123;&#123;advertising_rules&#125;&#125;</code></div>
                <div><code>&#123;&#123;publication_context&#125;&#125;</code>、<code>&#123;&#123;segment_index&#125;&#125;</code>、<code>&#123;&#123;segment_count&#125;&#125;</code></div>
            @else
                <div>{!! __('admin.ai_prompts.variable_title_label') !!}</div>
                <div>{!! __('admin.ai_prompts.variable_keyword_label') !!}</div>
                <div>{!! __('admin.ai_prompts.variable_knowledge_label') !!}</div>
            @endif
        </div>

        <p class="mt-5 break-words border-t border-blue-200 pt-4 text-xs leading-6 text-blue-800">{!! __('admin.ai_prompts.variable_help') !!}</p>
    </aside>
</div>
