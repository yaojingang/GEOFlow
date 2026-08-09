@extends('admin.layouts.app')

@php
    $isEdit = $publication instanceof \App\Models\ManualPublication;
    $selectedArticleId = (string) old('article_id', $publication?->article_id ?? $selectedArticle?->id ?? '');
    $selectedType = (string) old('type', $publication?->type ?? ($selectedArticle ? 'post' : 'comment'));
    $selectedPersonaId = (string) old('persona_id', $publication?->persona_id ?? '');
    $selectedAccountId = (string) old('account_id', $publication?->account_id ?? '');
    $selectedAssigneeId = (string) old('assigned_admin_id', $publication?->assigned_admin_id ?? '');
    $selectedPlatform = (string) old('platform', $publication?->platform ?? 'zhihu');
    $content = (string) old('content', $publication?->content ?? $prefilledContent);
    $articleSearchAction = $isEdit
        ? route('admin.manual-publications.edit', ['manualPublicationId' => $publication->id])
        : route('admin.manual-publications.create');
    $articleSearchReset = $isEdit
        ? route('admin.manual-publications.edit', ['manualPublicationId' => $publication->id])
        : route('admin.manual-publications.create', array_filter(['article_id' => $selectedArticleId]));
@endphp

@section('content')
<div class="px-4 sm:px-0">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.manual_publications.edit_title', ['id' => $publication->id]) : __('admin.manual_publications.create_title') }}</h1>
            <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.manual_publications.form_subtitle') }}</p>
        </div>
        <a href="{{ $isEdit ? route('admin.manual-publications.show', ['manualPublicationId' => $publication->id]) : route('admin.manual-publications.index') }}" class="inline-flex w-fit items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>
            {{ __('admin.manual_publications.button.back') }}
        </a>
    </div>

    <form method="GET" action="{{ $articleSearchAction }}" class="mt-6 flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-end">
        @unless($isEdit)
            @if($selectedArticleId !== '')
                <input type="hidden" name="article_id" value="{{ $selectedArticleId }}">
            @endif
        @endunless
        <div class="min-w-0 flex-1">
            <label for="article_search" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.article') }}</label>
            <input id="article_search" type="search" name="article_search" value="{{ $articleSearch }}" maxlength="200" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.manual_publications.filter.search_placeholder') }}">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">{{ __('admin.button.search') }}</button>
            @if($articleSearch !== '')
                <a href="{{ $articleSearchReset }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('admin.button.reset') }}</a>
            @endif
        </div>
    </form>

    @if($articles->hasPages())
        <div class="mt-3">
            {{ $articles->onEachSide(1)->links() }}
        </div>
    @endif

    <form method="POST" action="{{ $isEdit ? route('admin.manual-publications.update', ['manualPublicationId' => $publication->id]) : route('admin.manual-publications.store') }}" class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        @csrf
        @if($isEdit)
            @method('PUT')
            <input type="hidden" name="revision" value="{{ $publication->revision }}">
        @endif

        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.section.content') }}</h2>
                <div class="mt-5 grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.type') }} *</label>
                        <select id="type" name="type" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach(\App\Models\ManualPublication::TYPES as $type)
                                <option value="{{ $type }}" @selected($selectedType === $type)>{{ __('admin.manual_publications.type.'.$type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="article_id" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.article') }}</label>
                        <select id="article_id" name="article_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">{{ __('admin.manual_publications.option.select_article') }}</option>
                            @foreach($articles as $article)
                                <option value="{{ $article->id }}" @selected($selectedArticleId === (string) $article->id)>{{ $article->title }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.manual_publications.help.article') }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label for="content" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.content') }} *</label>
                        <textarea id="content" name="content" rows="12" maxlength="{{ \App\Models\ManualPublication::MAX_CONTENT_CHARACTERS }}" required class="mt-1 w-full rounded-md border-gray-300 font-mono text-sm leading-6 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ $content }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.manual_publications.help.content', ['count' => \App\Models\ManualPublication::MAX_CONTENT_CHARACTERS]) }}</p>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.section.target') }}</h2>
                <div class="mt-5 grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="target_url" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.target_url') }}</label>
                        <input id="target_url" type="url" name="target_url" value="{{ old('target_url', $publication?->target_url) }}" maxlength="1000" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="https://example.com/discussion">
                    </div>
                    <div class="md:col-span-2">
                        <label for="target_context" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.target_context') }}</label>
                        <textarea id="target_context" name="target_context" rows="5" maxlength="5000" class="mt-1 w-full rounded-md border-gray-300 text-sm leading-6 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('target_context', $publication?->target_context) }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.manual_publications.help.target_context') }}</p>
                    </div>
                </div>
            </section>
        </div>

        <div class="space-y-6">
            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.manual_publications.section.assignment') }}</h2>
                <div class="mt-5 space-y-5">
                    <div>
                        <label for="persona_id" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.persona') }} *</label>
                        <select id="persona_id" name="persona_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">{{ __('admin.manual_publications.option.select_persona') }}</option>
                            @foreach($personas as $persona)
                                <option value="{{ $persona->id }}" @selected($selectedPersonaId === (string) $persona->id)>{{ $persona->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="platform" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.platform') }} *</label>
                        <select id="platform" name="platform" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach($platforms as $platform)
                                <option value="{{ $platform }}" @selected($selectedPlatform === $platform)>{{ __('admin.manual_publications.platform.'.$platform) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="custom_platform" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.custom_platform') }}</label>
                        <input id="custom_platform" type="text" name="custom_platform" value="{{ old('custom_platform', $publication?->custom_platform) }}" maxlength="120" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="account_id" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.account') }}</label>
                        <select id="account_id" name="account_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">{{ __('admin.manual_publications.option.no_account') }}</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" @selected($selectedAccountId === (string) $account->id)>{{ $account->account_name }} · {{ __('admin.manual_publications.platform.'.$account->platform) }} · {{ $account->persona?->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="assigned_admin_id" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.assignee') }}</label>
                        <select id="assigned_admin_id" name="assigned_admin_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">{{ __('admin.manual_publications.unassigned') }}</option>
                            @foreach($admins as $admin)
                                <option value="{{ $admin->id }}" @selected($selectedAssigneeId === (string) $admin->id)>{{ $admin->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="scheduled_at" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.scheduled_at') }}</label>
                        <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $publication?->scheduled_at?->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    @unless($isEdit)
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">{{ __('admin.manual_publications.field.initial_status') }}</label>
                            <select id="status" name="status" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="draft" @selected(old('status', 'draft') === 'draft')>{{ __('admin.manual_publications.status.draft') }}</option>
                                <option value="ready" @selected(old('status') === 'ready')>{{ __('admin.manual_publications.status.ready') }}</option>
                            </select>
                        </div>
                    @endunless
                </div>
            </section>

            <div class="rounded-xl border border-blue-100 bg-blue-50 p-5 text-sm leading-6 text-blue-800">
                {{ __('admin.manual_publications.disclosure_notice') }}
            </div>

            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                <i data-lucide="save" class="h-4 w-4"></i>
                {{ $isEdit ? __('admin.manual_publications.button.save') : __('admin.manual_publications.button.create') }}
            </button>
        </div>
    </form>
</div>
@endsection
