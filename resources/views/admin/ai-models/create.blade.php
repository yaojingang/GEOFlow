@extends('admin.layouts.app')

@php
    $chatPresets = [
        ['key' => 'opencodex', 'label' => 'opencodex（免金鑰）', 'name' => 'opencodex / Gemini 3.8 Flash High', 'version' => '3.8', 'model_id' => 'cpamc/gemini-3.8-flash-high', 'api_url' => 'http://opencodex:8080/v1', 'model_type' => 'chat'],
        ['key' => 'minimax', 'label' => 'MiniMax', 'name' => 'MiniMax M3', 'version' => 'M3', 'model_id' => 'MiniMax-M3', 'api_url' => 'https://api.minimax.io', 'model_type' => 'chat'],
        ['key' => 'minimax_m27', 'label' => 'MiniMax M2.7', 'name' => 'MiniMax M2.7', 'version' => 'M2.7', 'model_id' => 'MiniMax-M2.7', 'api_url' => 'https://api.minimax.io', 'model_type' => 'chat'],
        ['key' => 'minimax_highspeed', 'label' => 'MiniMax Highspeed', 'name' => 'MiniMax M2.7 Highspeed', 'version' => 'M2.7', 'model_id' => 'MiniMax-M2.7-highspeed', 'api_url' => 'https://api.minimax.io', 'model_type' => 'chat'],
        ['key' => 'openai', 'label' => 'OpenAI', 'name' => 'GPT-5.6 Terra', 'version' => '5.6', 'model_id' => 'gpt-5.6-terra', 'api_url' => 'https://api.openai.com', 'model_type' => 'chat'],
        ['key' => 'gemini', 'label' => 'Gemini', 'name' => 'Gemini 3.6 Flash', 'version' => 'v1beta', 'model_id' => 'gemini-3.6-flash', 'api_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model_type' => 'chat'],
        ['key' => 'deepseek', 'label' => 'DeepSeek V4 Flash', 'name' => 'DeepSeek V4 Flash', 'version' => 'v4', 'model_id' => 'deepseek-v4-flash', 'api_url' => 'https://api.deepseek.com', 'model_type' => 'chat'],
        ['key' => 'deepseek_v4_pro', 'label' => 'DeepSeek V4 Pro', 'name' => 'DeepSeek V4 Pro', 'version' => 'v4', 'model_id' => 'deepseek-v4-pro', 'api_url' => 'https://api.deepseek.com', 'model_type' => 'chat'],
        ['key' => 'zhipu', 'label' => 'Zhipu GLM', 'name' => '智谱 GLM-5.2', 'version' => 'v4', 'model_id' => 'glm-5.2', 'api_url' => 'https://open.bigmodel.cn/api/paas/v4', 'model_type' => 'chat'],
        ['key' => 'volcengine_ark', 'label' => 'Volcengine Ark', 'name' => '火山方舟 Chat', 'version' => 'v3', 'model_id' => '', 'api_url' => 'https://ark.cn-beijing.volces.com/api/v3', 'model_type' => 'chat'],
    ];
    $embeddingPresets = [
        ['key' => 'openai_embedding', 'label' => 'OpenAI Embedding', 'name' => 'OpenAI Embedding 3 Small', 'version' => '', 'model_id' => 'text-embedding-3-small', 'api_url' => 'https://api.openai.com', 'model_type' => 'embedding'],
        ['key' => 'gemini_embedding', 'label' => 'Gemini Embedding', 'name' => 'Gemini Embedding 2', 'version' => 'v1beta', 'model_id' => 'gemini-embedding-2', 'api_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model_type' => 'embedding'],
        ['key' => 'volcengine_ark_embedding', 'label' => 'Doubao Embedding', 'name' => 'Doubao Embedding', 'version' => 'v3', 'model_id' => 'doubao-embedding-text-240515', 'api_url' => 'https://ark.cn-beijing.volces.com/api/v3', 'model_type' => 'embedding'],
        ['key' => 'zhipu_embedding', 'label' => 'Zhipu Embedding', 'name' => '智谱 Embedding-3', 'version' => 'v4', 'model_id' => 'embedding-3', 'api_url' => 'https://open.bigmodel.cn/api/paas/v4', 'model_type' => 'embedding'],
    ];
@endphp

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-0">
        <header class="mb-6 flex items-start gap-4 sm:mb-8">
            <a href="{{ route('admin.ai-models.index') }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 hover:bg-gray-50 hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-gray-900 text-balance">{{ __('admin.ai_models.create_page_title') }}</h1>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-600 text-pretty">{{ __('admin.ai_models.create_page_subtitle') }}</p>
            </div>
        </header>

        <form
            method="POST"
            action="{{ route('admin.ai-models.store') }}"
            class="space-y-6"
            data-ai-model-create-form
            data-supports-max-tokens="{{ ($supportsModelMaxTokens ?? false) ? 'true' : 'false' }}"
            data-show-key-label="{{ __('admin.ai_models.show_api_key') }}"
            data-hide-key-label="{{ __('admin.ai_models.hide_api_key') }}"
            data-submitting-label="{{ __('admin.ai_models.creating') }}"
        >
            @csrf

            <section class="overflow-hidden rounded-xl bg-white shadow">
                <div class="border-b border-gray-200 px-5 py-5 sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                            <i data-lucide="wand-sparkles" class="h-4.5 w-4.5"></i>
                        </span>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.ai_models.presets_title') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-gray-600 text-pretty">{{ __('admin.ai_models.presets_subtitle') }}</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 px-5 py-5 sm:px-6 lg:grid-cols-2 lg:gap-8">
                    @foreach ([['title' => __('admin.ai_models.quick_chat'), 'presets' => $chatPresets], ['title' => __('admin.ai_models.quick_embedding'), 'presets' => $embeddingPresets]] as $presetGroup)
                        <fieldset>
                            <legend class="text-sm font-semibold text-gray-800">{{ $presetGroup['title'] }}</legend>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($presetGroup['presets'] as $preset)
                                    <button
                                        type="button"
                                        class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 transition-[color,background-color,border-color,transform] duration-150 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                                        data-ai-model-preset="{{ $preset['key'] }}"
                                        data-preset-name="{{ $preset['name'] }}"
                                        data-preset-version="{{ $preset['version'] }}"
                                        data-preset-model-id="{{ $preset['model_id'] }}"
                                        data-preset-api-url="{{ $preset['api_url'] }}"
                                        data-preset-model-type="{{ $preset['model_type'] }}"
                                        aria-pressed="false"
                                    >
                                        {{ $preset['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>

                <div class="mx-5 mb-5 flex gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800 sm:mx-6">
                    <i data-lucide="info" class="mt-0.5 h-4 w-4 shrink-0"></i>
                    <p class="text-pretty">{{ __('admin.ai_models.gemini_embedding_notice') }}</p>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl bg-white shadow">
                <div class="border-b border-gray-200 px-5 py-5 sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                            <i data-lucide="plug-zap" class="h-4.5 w-4.5"></i>
                        </span>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.ai_models.details_title') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.ai_models.details_subtitle') }}</p>
                        </div>
                    </div>
                </div>

                <div class="px-5 py-6 sm:px-6">
                    @include('admin.ai-models._form-fields', ['mode' => 'create'])
                </div>

                <div class="flex flex-wrap items-center justify-start gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 sm:justify-end sm:px-6">
                    <a href="{{ route('admin.ai-models.index') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[color,background-color,transform] duration-150 hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        {{ __('admin.button.cancel') }}
                    </a>
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-70" data-model-submit>
                        <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                        <span data-model-submit-label>{{ __('admin.ai_models.create_submit') }}</span>
                    </button>
                </div>
            </section>
        </form>
    </div>
@endsection
