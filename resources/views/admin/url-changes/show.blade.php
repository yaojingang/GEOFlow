@extends('admin.layouts.app')

@php
    $summary = $change->summary ?? [];
    $isCategory = $change->operation === 'category';
    $isMove = $change->operation === 'article_category';
    $isReady = $change->status === 'ready' && $credential !== '' && $change->expires_at?->isFuture();
    $canRead = in_array($change->status, ['ready', 'applied', 'refreshing', 'completed'], true);
    $title = __('url_change.ui.'.($isCategory ? 'category_title' : ($isMove ? 'article_category_title' : 'rule_title')));
    $canCancel = in_array($change->status, ['checking', 'ready'], true);
    $targetIdentity = ($targetDetails ?? []) !== [] ? __('url_change.ui.'.$targetDetails['kind'].'_identity', ['name' => $targetDetails['name'], 'id' => $targetDetails['id']]) : null;
    $oldValueLabel = $isMove ? __('url_change.ui.category_identity', ['name' => $targetDetails['old_category_name'], 'id' => $change->old_value]) : $change->old_value;
    $newValueLabel = $isMove ? __('url_change.ui.category_identity', ['name' => $targetDetails['new_category_name'], 'id' => $change->new_value]) : $change->new_value;
    $parts = max(1, (int) ceil(((int) ($summary['changed_urls'] ?? 0) + (int) ($summary['potential_urls'] ?? 0)) / 100000));
    $riskSummary = __('url_change.ui.'.($isCategory ? 'category_summary' : 'rule_summary'), ['articles' => number_format((int) ($summary[$isCategory ? 'associated' : 'changed_articles'] ?? 0)), 'public' => number_format((int) ($summary['public_articles'] ?? 0)), 'urls' => number_format((int) ($summary['changed_urls'] ?? 0))]);
@endphp

@section('content')
<div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-0" data-url-change-report data-status="{{ $change->status }}" data-status-url="{{ route('admin.url-changes.status', $change) }}" data-poll-error="{{ __('url_change.ui.poll_failed') }}">
    <header>
        @if ($canCancel)
            <form method="POST" action="{{ route('admin.url-changes.cancel', $change) }}" data-no-unsaved>
                @csrf<input type="hidden" name="return_to_editor" value="1">
                <button class="inline-flex min-h-10 items-center gap-2 text-left text-sm font-medium text-gray-600 hover:text-blue-700 focus-visible:outline-2 focus-visible:outline-blue-600"><i data-lucide="arrow-left" class="size-4 shrink-0" aria-hidden="true"></i>{{ __('url_change.ui.cancel_and_edit') }}</button>
            </form>
        @else
            <a href="{{ $editorUrl }}" class="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-gray-600 hover:text-blue-700 focus-visible:outline-2 focus-visible:outline-blue-600"><i data-lucide="arrow-left" class="size-4" aria-hidden="true"></i>{{ __('url_change.ui.back') }}</a>
        @endif
        <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ __('url_change.title') }}</h1>
        <p class="mt-2 break-all text-xs text-gray-500">{{ __('url_change.ui.request_id') }}: {{ $change->id }}</p>
        <p class="mt-2 text-xs leading-6 text-gray-500">{{ __('url_change.ui.report_created', ['time' => $change->created_at->format('Y-m-d H:i:s T')]) }}</p>
        <details class="mt-2 text-xs text-gray-600"><summary class="cursor-pointer leading-6">{{ __('url_change.ui.report_versions') }}</summary><dl class="mt-2 space-y-1">@foreach ($change->versions as $scope => $version)<div class="flex flex-wrap gap-x-3"><dt class="break-all font-mono">{{ $scope }}</dt><dd>{{ $version }}</dd></div>@endforeach</dl></details>
    </header>

    <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6" aria-labelledby="url-change-status-heading">
        <h2 id="url-change-status-heading" class="flex items-center gap-2 text-lg font-semibold text-gray-900"><i data-lucide="{{ in_array($change->status, ['failed', 'stale'], true) ? 'circle-alert' : 'clipboard-check' }}" class="size-5 text-amber-700" aria-hidden="true"></i>{{ __('url_change.ui.status.'.$change->status) }}</h2>
        <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('url_change.ui.status_copy.'.$change->status) }}</p>
        <p class="mt-3 rounded-md bg-red-50 p-3 text-sm leading-6 text-red-800" data-url-job-error role="alert" @if(! $change->error) hidden @endif>{{ $change->error }}</p>
        @if (($summary['refresh_skipped'] ?? []) !== [])
            <div class="mt-3 rounded-md bg-amber-50 p-3 text-sm leading-6 text-amber-900" role="status">
                <p>{{ __('url_change.ui.refresh_skipped') }}</p>
                @foreach ($summary['refresh_skipped'] as $skipped)<p class="break-all">{{ $skipped['label'] }}</p>@endforeach
            </div>
        @endif
        @if ($errors->any())<div class="mt-3 rounded-md bg-red-50 p-3 text-sm leading-6 text-red-800" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <p class="mt-3 text-sm text-amber-900" data-url-poll-error role="status" hidden></p>
        <button type="button" class="mt-2 min-h-10 text-sm font-medium text-blue-700 focus-visible:outline-2 focus-visible:outline-blue-600" data-url-poll-retry hidden>{{ __('url_change.ui.retry_status') }}</button>
        @if ($targetIdentity)<p class="mt-4 break-words text-sm font-semibold text-gray-900" data-url-target>{{ $targetIdentity }}</p>@endif
        <dl class="mt-5 grid gap-4 border-t border-gray-200 pt-4 sm:grid-cols-2">
            <div><dt class="text-xs font-medium text-gray-500">{{ __('url_change.ui.old_value') }}</dt><dd class="mt-1 break-all font-mono text-sm text-gray-800">{{ $oldValueLabel }}</dd></div>
            <div><dt class="text-xs font-medium text-gray-500">{{ __('url_change.ui.new_value') }}</dt><dd class="mt-1 break-all font-mono text-sm font-semibold text-gray-900">{{ $newValueLabel }}</dd></div>
        </dl>
        <p class="mt-4 text-sm leading-6 text-gray-600">{{ __('url_change.ui.stable') }}</p>
        @foreach ($change->sites as $site)
            @php($historyCount = count($site['policy']['history'] ?? []))
            <p class="mt-3 break-words text-sm leading-6 text-gray-600">{{ __('url_change.ui.rule_history_count', ['site' => $site['label'], 'count' => $historyCount]) }}</p>
            @if ($historyCount >= 10)<p class="mt-2 rounded-md bg-amber-50 p-3 text-sm leading-6 text-amber-900">{{ __('url_change.ui.rule_history_warning') }}</p>@endif
        @endforeach
        <p class="mt-2 text-xs leading-6 text-gray-600">{{ __('url_change.ui.duration_hint') }}</p>
    </section>

    <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6" aria-labelledby="url-impact-heading">
        <h2 id="url-impact-heading" class="text-lg font-semibold text-gray-900">{{ __('url_change.ui.scope') }}</h2>
        @if ($change->status === 'checking')<p class="mt-2 text-sm leading-6 text-gray-600">{{ __('url_change.ui.checked_so_far') }}</p>@endif
        <dl class="mt-4 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['associated', 'public_articles', 'changed_articles', 'changed_urls', 'public_urls', 'potential_urls', 'trashed', 'non_public'] as $metric)
                <div class="border-b border-gray-100 pb-3"><dt class="text-sm leading-6 text-gray-600">{{ __('url_change.ui.'.($metric === 'potential_urls' ? 'potential' : $metric)) }}</dt><dd class="mt-1 text-xl font-semibold tabular-nums text-gray-900" data-url-metric="{{ $metric }}">{{ number_format((int) ($summary[$metric] ?? 0)) }}</dd></div>
            @endforeach
        </dl>
        <p class="mt-4 text-xs leading-6 text-gray-600">{{ __('url_change.ui.count_note') }}</p>
        <div class="mt-5 overflow-x-auto">
            <table class="w-full text-left text-sm"><thead class="border-b border-gray-200 text-xs text-gray-600"><tr><th class="py-3 pr-4 font-medium">{{ __('url_change.ui.site') }}</th><th class="px-2 py-3 text-right font-medium">{{ __('url_change.ui.site_public') }}</th><th class="py-3 pl-2 text-right font-medium">{{ __('url_change.ui.site_changed') }}</th></tr></thead><tbody class="divide-y divide-gray-100">
            @foreach ($summary['sites'] ?? [] as $site)
                <tr><th scope="row" class="break-all py-3 pr-4 font-medium text-gray-800">{{ $site['label'] }}</th><td class="px-2 py-3 text-right tabular-nums">{{ number_format((int) ($site['public'] ?? 0)) }}</td><td class="py-3 pl-2 text-right tabular-nums">{{ number_format((int) ($site['changed'] ?? 0)) }}</td></tr>
            @endforeach
            </tbody></table>
        </div>
    </section>

    @if (($summary['category_pages'] ?? []) !== [])
        <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6"><h2 class="text-lg font-semibold text-gray-900">{{ __('url_change.ui.category_pages') }}</h2>
            @foreach ($summary['category_pages'] as $site)
                <div class="mt-4 border-t border-gray-100 pt-4"><h3 class="text-sm font-medium text-gray-700">{{ $site['label'] }} <span class="ml-2 font-normal text-gray-500">{{ __('url_change.ui.'.(!empty($site['public']) ? 'public_label' : 'potential_label')) }}</span></h3><dl class="mt-2 grid gap-2 text-xs sm:grid-cols-2"><div><dt class="text-gray-500">{{ __('url_change.ui.before') }}</dt><dd class="mt-1 break-all font-mono text-gray-700">{{ $site['old_url'] }}</dd></div><div><dt class="text-gray-500">{{ __('url_change.ui.after') }}</dt><dd class="mt-1 break-all font-mono font-medium text-gray-900">{{ $site['new_url'] }}</dd></div></dl></div>
            @endforeach
        </section>
    @endif

    @if (($summary['examples'] ?? []) !== [])
        <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6"><h2 class="text-lg font-semibold text-gray-900">{{ __('url_change.ui.examples') }}</h2>
            @foreach ($summary['examples'] as $example)
                <div class="mt-4 border-t border-gray-100 pt-4"><h3 class="break-words text-sm font-medium text-gray-800">{{ $example['title'] }} <span class="font-normal text-gray-500">{{ $example['label'] ?? '' }}</span></h3><dl class="mt-2 grid gap-2 text-xs sm:grid-cols-2"><div><dt class="text-gray-500">{{ __('url_change.ui.before') }}</dt><dd class="mt-1 break-all font-mono text-gray-700">{{ $example['old_url'] }}</dd></div><div><dt class="text-gray-500">{{ __('url_change.ui.after') }}</dt><dd class="mt-1 break-all font-mono text-gray-900">{{ $example['new_url'] }}</dd></div></dl></div>
            @endforeach
        </section>
    @endif

    <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6"><h2 class="text-lg font-semibold text-gray-900">{{ __('url_change.ui.channels') }}</h2><p class="mt-2 text-sm leading-6 text-gray-600">{{ __('url_change.ui.remote_notice') }}</p>
        @foreach ($summary['channels'] ?? [] as $channel)
            <p class="mt-3 flex flex-wrap justify-between gap-2 text-sm text-gray-700"><span>{{ $channel['name'] }} · {{ $channel['type'] }}</span><span class="tabular-nums">{{ __('url_change.ui.channel_count') }}: {{ number_format((int) $channel['count']) }}</span></p>
        @endforeach
        <h3 class="mt-5 text-sm font-semibold text-gray-900">{{ __('url_change.ui.structured') }}: <span class="tabular-nums">{{ number_format((int) ($summary['structured_settings'] ?? 0)) }}</span></h3><p class="mt-1 text-sm leading-6 text-gray-600">{{ __('url_change.ui.structured_hint') }}</p>
    </section>

    @if ($canRead)
        <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6" data-url-details data-url="{{ route('admin.url-changes.articles', $change) }}" data-before="{{ __('url_change.ui.before') }}" data-after="{{ __('url_change.ui.after') }}" data-public="{{ __('url_change.ui.public_label') }}" data-potential="{{ __('url_change.ui.potential_label') }}" data-empty="{{ __('url_change.ui.empty_rows') }}" data-error="{{ __('url_change.ui.load_failed') }}" data-loading="{{ __('url_change.ui.loading') }}" data-load="{{ __('url_change.ui.load_more') }}">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('url_change.ui.details') }}</h2><p class="mt-2 text-sm leading-6 text-gray-600">{{ __('url_change.ui.details_hint') }}</p>
            <label for="url-change-site-filter" class="mt-4 block text-sm font-medium text-gray-700">{{ __('url_change.ui.site') }}</label><select id="url-change-site-filter" class="mt-1 min-h-10 max-w-full rounded-md border-gray-300 text-sm" data-url-site><option value="">{{ __('url_change.ui.all_sites') }}</option>@foreach ($summary['sites'] ?? [] as $key => $site)<option value="{{ $key }}">{{ $site['label'] }}</option>@endforeach</select>
            <div class="mt-4 space-y-4" data-url-rows></div><p class="mt-3 text-sm text-red-700" data-url-details-error role="status" hidden></p><div class="mt-4 flex flex-wrap gap-3"><button type="button" class="min-h-10 rounded-md border border-gray-300 px-4 text-sm font-medium text-gray-700 active:scale-[.96] disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-blue-600" data-url-load>{{ __('url_change.ui.load_more') }}</button><button type="button" class="min-h-10 px-2 text-sm font-medium text-blue-700 focus-visible:outline-2 focus-visible:outline-blue-600" data-url-first hidden>{{ __('url_change.ui.first_page') }}</button></div>
            <div class="mt-5 flex flex-wrap gap-3 border-t border-gray-200 pt-4">@for ($part = 1; $part <= $parts; $part++)<a href="{{ route('admin.url-changes.download', ['urlChange' => $change, 'part' => $part]) }}" class="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-blue-700 focus-visible:outline-2 focus-visible:outline-blue-600"><i data-lucide="download" class="size-4" aria-hidden="true"></i>{{ $parts === 1 ? __('url_change.ui.download') : __('url_change.ui.download_part', ['part' => $part]) }}</a>@endfor</div><p class="mt-2 text-xs leading-5 text-gray-600">{{ __('url_change.ui.download_hint') }}</p>
        </section>
    @endif

    <div class="flex flex-wrap items-start gap-3 pb-6">
        @if ($isReady)
            <button type="button" class="min-h-11 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white active:scale-[.96] disabled:cursor-not-allowed disabled:bg-gray-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600" data-url-risk-open disabled>{{ __('url_change.ui.review') }}</button>
            <p class="w-full text-sm leading-6 text-amber-900" data-url-no-js role="status">{{ __('url_change.ui.no_js') }}</p>
            <a href="{{ route('admin.url-changes.show', $change) }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-blue-700 focus-visible:outline-2 focus-visible:outline-blue-600" data-url-expiry-refresh hidden>{{ __('url_change.ui.refresh_report') }}</a>
        @endif
        @if (in_array($change->status, ['checking', 'ready'], true))<form method="POST" action="{{ route('admin.url-changes.cancel', $change) }}">@csrf<button class="min-h-11 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 active:scale-[.96]">{{ __('url_change.ui.cancel_check') }}</button></form>@endif
        @if (in_array($change->status, ['failed', 'stale', 'cancelled'], true) || ($change->status === 'ready' && ! $isReady))<form method="POST" action="{{ route('admin.url-changes.store') }}">@csrf<input type="hidden" name="operation" value="{{ $change->operation }}"><input type="hidden" name="target_id" value="{{ $change->target_id }}"><input type="hidden" name="value" value="{{ $change->new_value }}"><button class="min-h-11 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white active:scale-[.96]">{{ __('url_change.ui.recheck') }}</button></form>@endif
    </div>

    @if ($isReady)
        <dialog class="admin-action-dialog url-risk-dialog" data-url-risk-dialog data-tone="warning" role="dialog" aria-modal="true" aria-labelledby="url-risk-title" aria-describedby="url-risk-description" data-phrase="{{ $phrase }}" data-expires="{{ $change->expires_at->toIso8601String() }}" data-expired="{{ __('url_change.ui.expired') }}" data-uncertain="{{ __('url_change.ui.uncertain') }}" data-failed="{{ __('url_change.ui.submit_failed') }}" data-applying="{{ __('url_change.ui.applying') }}">
            <form method="POST" action="{{ route('admin.url-changes.confirm', $change) }}" class="admin-action-dialog__surface" data-url-confirm-form data-no-unsaved>
                @csrf<input type="hidden" name="credential" value="{{ $credential }}">
                <div class="admin-action-dialog__content">
                    <div class="url-risk-dialog__heading"><span class="admin-action-dialog__icon" aria-hidden="true"><i data-lucide="triangle-alert"></i></span><h2 id="url-risk-title" class="admin-action-dialog__title" tabindex="-1">{{ $title }}</h2></div>
                    <p id="url-risk-description" class="url-risk-dialog__section">{{ __('url_change.ui.risk') }}</p>
                    @if ($targetIdentity)<p class="url-risk-dialog__section"><strong>{{ $targetIdentity }}</strong></p>@endif
                    <dl class="url-risk-dialog__section url-risk-dialog__values"><div><dt>{{ __('url_change.ui.old_value') }}</dt><dd>{{ $oldValueLabel }}</dd></div><div><dt>{{ __('url_change.ui.new_value') }}</dt><dd>{{ $newValueLabel }}</dd></div></dl>
                    <div class="url-risk-dialog__section"><p>{{ __('url_change.ui.site_count', ['count' => number_format(count($summary['sites'] ?? []))]) }}</p><ul>@foreach (array_slice($summary['sites'] ?? [], 0, 3) as $site)<li>{{ $site['label'] }}</li>@endforeach</ul></div>
                    <div class="url-risk-dialog__section"><p>{{ $riskSummary }}</p>
                        @if ((int) ($summary['associated'] ?? 0) === 0)<p>{{ __('url_change.ui.'.($isCategory ? 'empty_category' : 'empty_site')) }}</p>@elseif ($isCategory && (int) ($summary['changed_urls'] ?? 0) === 0)<p>{{ __('url_change.ui.category_no_article_changes') }}</p>@endif
                        @foreach (array_slice($summary['category_pages'] ?? [], 0, 3) as $site)<p>{{ $site['label'] }} · {{ __('url_change.ui.'.(!empty($site['public']) ? 'public_label' : 'potential_label')) }}<br>{{ __('url_change.ui.before') }}: <code>{{ $site['old_url'] }}</code><br>{{ __('url_change.ui.after') }}: <code>{{ $site['new_url'] }}</code></p>@endforeach
                        @foreach (array_slice($summary['examples'] ?? [], 0, 1) as $example)<p>{{ __('url_change.ui.examples') }}<br><code>{{ $example['old_url'] }}</code><br>→ <code>{{ $example['new_url'] }}</code></p>@endforeach
                    </div>
                    <div class="url-risk-dialog__section"><p>{{ __('url_change.ui.risk_description') }}</p><p>{{ __('url_change.ui.risk_advice') }}</p><a href="{{ route('admin.url-changes.download', $change) }}" class="inline-flex min-h-10 items-center font-medium text-blue-700 focus-visible:outline-2 focus-visible:outline-blue-600">{{ __('url_change.ui.download') }}{{ $parts > 1 ? ' · '.__('url_change.ui.download_part', ['part' => 1]) : '' }}</a></div>
                    <div class="url-risk-dialog__section"><label for="url-risk-phrase">{{ __('url_change.ui.type_phrase') }}<strong class="url-risk-dialog__phrase">{{ $phrase }}</strong></label><input id="url-risk-phrase" class="url-risk-dialog__input" name="confirmation" type="text" autocomplete="off" spellcheck="false" required maxlength="100" aria-describedby="url-risk-phrase-help url-risk-expiry" data-url-phrase><p id="url-risk-phrase-help" class="url-risk-dialog__help">{{ __('url_change.ui.phrase_help') }}</p><p id="url-risk-expiry" class="url-risk-dialog__help">{{ __('url_change.ui.expires', ['time' => $change->expires_at->format('Y-m-d H:i:s T')]) }}</p></div>
                    <p class="url-risk-dialog__section url-risk-dialog__error" data-url-confirm-error role="alert" hidden></p>
                </div>
                <div class="admin-action-dialog__actions"><p class="url-risk-dialog__processing" data-url-confirm-status role="status" hidden></p><button type="button" class="admin-action-button admin-action-button--secondary" data-url-risk-close>{{ __('url_change.ui.cancel_modal') }}</button><button type="submit" class="admin-action-button admin-action-button--primary" data-url-confirm disabled>{{ __('url_change.ui.'.($isCategory ? 'apply_category' : ($isMove ? 'apply_article_category' : 'apply'))) }}</button></div>
            </form>
        </dialog>
    @endif
</div>
@endsection
