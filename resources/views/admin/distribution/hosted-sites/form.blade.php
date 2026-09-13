@extends('admin.layouts.app')

@php
    $isEdit = $channel !== null;
    $settings = $isEdit && is_array($channel->site_settings) ? $channel->site_settings : [];
    $leadFormSlugs = old('lead_form_slugs', $settings['lead_form_slugs'] ?? []);
    $leadFormSlugs = is_array($leadFormSlugs) ? array_values($leadFormSlugs) : [];
    if (count($leadFormSlugs) < 20) {
        $leadFormSlugs[] = '';
    }
@endphp

@section('content')
    <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-0">
        <div>
            <a href="{{ $isEdit ? route('admin.distribution.hosted-sites.show', $channel) : route('admin.distribution.hosted-sites.index') }}" class="inline-flex min-h-10 items-center text-sm font-medium text-gray-500 hover:text-gray-800 focus-visible:ring-2 focus-visible:ring-blue-500">返回托管站点</a>
            <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? '编辑托管站点' : '创建托管站点' }}</h1>
            <p class="mt-1 text-sm leading-6 text-gray-600">新站点会以暂停、维护、禁止索引和待质量检查状态保存。</p>
        </div>

        <form method="POST" action="{{ $isEdit ? route('admin.distribution.hosted-sites.update', $channel) : route('admin.distribution.hosted-sites.store') }}" class="space-y-8">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <section class="bg-white px-5 py-6 shadow-sm sm:px-7">
                <h2 class="text-base font-semibold text-gray-900">站点身份</h2>
                <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">站点名称</label>
                        <input id="name" name="name" required value="{{ old('name', $channel?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="hostname" class="block text-sm font-medium text-gray-700">完整域名</label>
                        <input id="hostname" name="hostname" required value="{{ old('hostname', $profile?->hostname) }}" placeholder="alpha.sites.example.com" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="topic" class="block text-sm font-medium text-gray-700">内容主题</label>
                        <input id="topic" name="topic" required value="{{ old('topic', $profile?->topic) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="template_key" class="block text-sm font-medium text-gray-700">主题模板</label>
                        <select id="template_key" name="template_key" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach ($availableThemes as $theme)
                                <option value="{{ $theme['id'] }}" @selected(old('template_key', $channel?->template_key) === $theme['id'])>{{ $theme['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="locale" class="block text-sm font-medium text-gray-700">语言</label>
                        <input id="locale" name="locale" required value="{{ old('locale', $profile?->locale ?? 'zh_CN') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="timezone" class="block text-sm font-medium text-gray-700">时区</label>
                        <input id="timezone" name="timezone" required value="{{ old('timezone', $profile?->timezone ?? config('app.timezone')) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                </div>
            </section>

            <section class="bg-white px-5 py-6 shadow-sm sm:px-7">
                <h2 class="text-base font-semibold text-gray-900">容量与质量门槛</h2>
                <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div><label for="daily_publish_limit" class="block text-sm font-medium text-gray-700">每日发布上限</label><input id="daily_publish_limit" name="daily_publish_limit" type="number" min="1" required value="{{ old('daily_publish_limit', $profile?->daily_publish_limit ?? config('geoflow.hosted_sites.default_daily_publish_limit', 3)) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                    <div><label for="publish_weight" class="block text-sm font-medium text-gray-700">发布权重</label><input id="publish_weight" name="publish_weight" type="number" min="1" value="{{ old('publish_weight', $profile?->publish_weight ?? 100) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                    <div><label for="min_publish_interval_minutes" class="block text-sm font-medium text-gray-700">最小间隔，分钟</label><input id="min_publish_interval_minutes" name="min_publish_interval_minutes" type="number" min="0" required value="{{ old('min_publish_interval_minutes', $profile?->min_publish_interval_minutes ?? config('geoflow.hosted_sites.default_min_publish_interval_minutes', 360)) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                    <div><label for="min_articles_before_index" class="block text-sm font-medium text-gray-700">开放索引文章数</label><input id="min_articles_before_index" name="min_articles_before_index" type="number" min="1" required value="{{ old('min_articles_before_index', $profile?->min_articles_before_index ?? config('geoflow.hosted_sites.default_min_articles_before_index', 10)) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                </div>
            </section>

            <section class="bg-white px-5 py-6 shadow-sm sm:px-7">
                <h2 class="text-base font-semibold text-gray-900">公开内容</h2>
                <div class="mt-5 space-y-5">
                    <div><label for="site_description" class="block text-sm font-medium text-gray-700">站点描述</label><textarea id="site_description" name="site_description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">{{ old('site_description', $settings['site_description'] ?? '') }}</textarea></div>
                    <div><label for="site_keywords" class="block text-sm font-medium text-gray-700">SEO 关键词</label><input id="site_keywords" name="site_keywords" value="{{ old('site_keywords', $settings['site_keywords'] ?? '') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div><label for="about_title" class="block text-sm font-medium text-gray-700">关于页标题</label><input id="about_title" name="about_title" value="{{ old('about_title', $settings['about_title'] ?? '') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                        <fieldset>
                            <legend class="block text-sm font-medium text-gray-700">表单白名单</legend>
                            <div class="mt-1 space-y-2">
                                @foreach ($leadFormSlugs as $index => $slug)
                                    <input name="lead_form_slugs[]" value="{{ $slug }}" placeholder="contact" aria-label="表单白名单 {{ $index + 1 }}" class="block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                @endforeach
                            </div>
                            <p class="mt-1 text-xs text-gray-500">每行一个表单标识；保存后只公开列表中的表单。</p>
                        </fieldset>
                    </div>
                    <div><label for="about_content" class="block text-sm font-medium text-gray-700">关于页内容</label><textarea id="about_content" name="about_content" rows="6" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">{{ old('about_content', $settings['about_content'] ?? '') }}</textarea></div>
                    <div><label for="contact_email" class="block text-sm font-medium text-gray-700">公开联系邮箱</label><input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email', $settings['contact_email'] ?? '') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"><p class="mt-1 text-xs text-gray-500">开放索引前必须配置联系邮箱，或至少启用一个联系表单。</p></div>
                </div>
            </section>

            <div class="flex flex-wrap justify-end gap-3">
                <a href="{{ $isEdit ? route('admin.distribution.hosted-sites.show', $channel) : route('admin.distribution.hosted-sites.index') }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-transform active:scale-[.96] hover:bg-gray-50">取消</a>
                <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-blue-600 px-5 py-2 text-sm font-medium text-white transition-transform active:scale-[.96] hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">保存站点</button>
            </div>
        </form>

        @if ($isEdit)
            <section class="bg-white px-5 py-6 shadow-sm sm:px-7" aria-labelledby="hosted-permalink-title">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 id="hosted-permalink-title" class="text-base font-semibold text-gray-900">{{ __('article_permalink.title') }}</h2>
                            <span class="rounded-full bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700">revision {{ $articlePermalinkPolicy->revision }}</span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('article_permalink.hosted_scope', ['hostname' => $profile->hostname, 'pattern' => $articlePermalinkPolicy->currentPattern]) }}</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.distribution.hosted-sites.article-permalink.preview', $channel) }}" class="mt-5 space-y-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
                    @csrf
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($articlePermalinkPresets as $preset)
                            <label class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 bg-white p-3 hover:border-violet-300">
                                <input type="radio" value="{{ $preset['pattern'] }}" class="mt-1 border-gray-300 text-violet-600 focus:ring-violet-500" data-hosted-permalink-preset @checked(($articlePermalinkPreview['pattern'] ?? $articlePermalinkPolicy->currentPattern) === $preset['pattern'])>
                                <span><span class="block text-sm font-medium text-gray-900">{{ __($preset['name']) }}</span><code class="mt-1 block text-xs text-gray-600">{{ $preset['pattern'] }}</code></span>
                            </label>
                        @endforeach
                    </div>
                    <div>
                        <label for="hosted-permalink-pattern" class="block text-sm font-medium text-gray-700">{{ __('article_permalink.custom_template') }}</label>
                        <input id="hosted-permalink-pattern" name="pattern" required maxlength="160" value="{{ old('pattern', $articlePermalinkPreview['pattern'] ?? $articlePermalinkPolicy->currentPattern) }}" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500">
                        @error('pattern')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 active:scale-[.98]">{{ __('article_permalink.preview_action') }}</button>
                    </div>
                </form>

                @if (is_array($articlePermalinkPreview ?? null))
                    <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-emerald-900">{{ __('article_permalink.hosted_preview_summary', ['articles' => $articlePermalinkPreview['affected_articles']]) }}</p>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('admin.distribution.hosted-sites.article-permalink.migration-map', ['hostedSite' => $channel, 'preview_credential' => $articlePermalinkPreview['credential']]) }}" class="inline-flex min-h-10 items-center rounded-md border border-emerald-300 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 active:scale-[.98]">{{ __('article_permalink.download_migration_map') }}</a>
                                <form method="POST" action="{{ route('admin.distribution.hosted-sites.article-permalink.activate', $channel) }}">
                                    @csrf
                                    <input type="hidden" name="preview_credential" value="{{ $articlePermalinkPreview['credential'] }}">
                                    <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 active:scale-[.98]">{{ __('article_permalink.activate_action') }}</button>
                                </form>
                            </div>
                        </div>
                        @foreach ($articlePermalinkPreview['examples'] ?? [] as $example)
                            <div class="mt-3 grid grid-cols-1 gap-1 rounded-md bg-white p-3 text-xs md:grid-cols-2 md:gap-3">
                                <code class="break-all text-gray-500">{{ $example['current_path'] }}</code>
                                <code class="break-all font-semibold text-emerald-800">{{ $example['preview_path'] }}</code>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const pattern = document.getElementById('hosted-permalink-pattern');
            document.querySelectorAll('[data-hosted-permalink-preset]').forEach(function (preset) {
                preset.addEventListener('change', function () {
                    if (pattern && preset.checked) pattern.value = preset.value;
                });
            });
        });
    </script>
@endpush
