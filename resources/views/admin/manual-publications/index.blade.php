@extends('admin.layouts.app')

@section('content')
<div class="px-4 sm:px-0">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.manual_publications.page_title') }}</h1>
            <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.manual_publications.page_subtitle') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.manual-publications.export', request()->query()) }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                <i data-lucide="download" class="h-4 w-4"></i>
                {{ __('admin.manual_publications.button.export') }}
            </a>
            @if($canCreate)
                <a href="{{ route('admin.manual-publications.settings.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    <i data-lucide="users-round" class="h-4 w-4"></i>
                    {{ __('admin.manual_publications.button.settings') }}
                </a>
                <a href="{{ route('admin.manual-publications.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    <i data-lucide="plus" class="h-4 w-4"></i>
                    {{ __('admin.manual_publications.button.create') }}
                </a>
            @endif
        </div>
    </div>

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach([
            ['key' => 'total', 'icon' => 'clipboard-list', 'class' => 'text-blue-600'],
            ['key' => 'ready', 'icon' => 'circle-check-big', 'class' => 'text-amber-600'],
            ['key' => 'in_progress', 'icon' => 'loader-circle', 'class' => 'text-purple-600'],
            ['key' => 'completed', 'icon' => 'badge-check', 'class' => 'text-emerald-600'],
        ] as $card)
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.manual_publications.stats.'.$card['key']) }}</p>
                        <p class="mt-2 text-2xl font-bold text-gray-900">{{ (int) ($stats[$card['key']] ?? 0) }}</p>
                    </div>
                    <i data-lucide="{{ $card['icon'] }}" class="h-6 w-6 {{ $card['class'] }}"></i>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <form method="GET" action="{{ route('admin.manual-publications.index') }}" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-7">
            <div>
                <label for="status" class="block text-xs font-medium text-gray-600">{{ __('admin.manual_publications.filter.status') }}</label>
                <select id="status" name="status" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.manual_publications.filter.all') }}</option>
                    @foreach(\App\Models\ManualPublication::STATUSES as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('admin.manual_publications.status.'.$status) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type" class="block text-xs font-medium text-gray-600">{{ __('admin.manual_publications.filter.type') }}</label>
                <select id="type" name="type" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.manual_publications.filter.all') }}</option>
                    @foreach(\App\Models\ManualPublication::TYPES as $type)
                        <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ __('admin.manual_publications.type.'.$type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="platform" class="block text-xs font-medium text-gray-600">{{ __('admin.manual_publications.filter.platform') }}</label>
                <select id="platform" name="platform" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.manual_publications.filter.all') }}</option>
                    @foreach($platforms as $platform)
                        <option value="{{ $platform }}" @selected(($filters['platform'] ?? '') === $platform)>{{ __('admin.manual_publications.platform.'.$platform) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="assigned_admin_id" class="block text-xs font-medium text-gray-600">{{ __('admin.manual_publications.filter.assignee') }}</label>
                <select id="assigned_admin_id" name="assigned_admin_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.manual_publications.filter.all') }}</option>
                    @foreach($admins as $admin)
                        <option value="{{ $admin->id }}" @selected((string) ($filters['assigned_admin_id'] ?? '') === (string) $admin->id)>{{ $admin->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="scheduled_from" class="block text-xs font-medium text-gray-600">{{ __('admin.manual_publications.filter.scheduled_from') }}</label>
                <input id="scheduled_from" type="date" name="scheduled_from" value="{{ $filters['scheduled_from'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label for="scheduled_to" class="block text-xs font-medium text-gray-600">{{ __('admin.manual_publications.filter.scheduled_to') }}</label>
                <input id="scheduled_to" type="date" name="scheduled_to" value="{{ $filters['scheduled_to'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label for="search" class="block text-xs font-medium text-gray-600">{{ __('admin.manual_publications.filter.search') }}</label>
                <div class="mt-1 flex gap-2">
                    <input id="search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" class="min-w-0 flex-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.manual_publications.filter.search_placeholder') }}">
                    <button type="submit" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700">{{ __('admin.manual_publications.filter.apply') }}</button>
                </div>
            </div>
        </form>
    </div>

    <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        @foreach(['work_order', 'source', 'platform', 'assignee', 'schedule', 'status', 'action'] as $column)
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.manual_publications.column.'.$column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($publications as $publication)
                        @php
                            $statusClass = match((string) $publication->status) {
                                'completed' => 'bg-emerald-50 text-emerald-700',
                                'in_progress' => 'bg-purple-50 text-purple-700',
                                'ready' => 'bg-amber-50 text-amber-700',
                                'failed', 'cancelled' => 'bg-red-50 text-red-700',
                                'skipped' => 'bg-gray-100 text-gray-700',
                                default => 'bg-blue-50 text-blue-700',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-4 text-sm">
                                <a href="{{ route('admin.manual-publications.show', ['manualPublicationId' => $publication->id]) }}" class="font-semibold text-blue-600 hover:text-blue-800">#{{ $publication->id }}</a>
                                <div class="mt-1 text-xs text-gray-500">{{ __('admin.manual_publications.type.'.$publication->type) }}</div>
                            </td>
                            <td class="max-w-xs px-5 py-4 text-sm text-gray-700">
                                <div class="truncate font-medium text-gray-900">{{ $publication->article?->title ?? \Illuminate\Support\Str::limit((string) $publication->target_context, 40) }}</div>
                                <div class="mt-1 truncate text-xs text-gray-500">{{ \Illuminate\Support\Str::limit((string) $publication->content, 64) }}</div>
                                @if($publication->duplicate_warning_count > 0)
                                    <div class="mt-1 text-xs font-medium text-amber-600">{{ __('admin.manual_publications.duplicate_count', ['count' => $publication->duplicate_warning_count]) }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-700">
                                <div>{{ $publication->platformDisplayName() }}</div>
                                <div class="mt-1 text-xs text-gray-500">{{ $publication->accountDisplayName() ?? __('admin.manual_publications.none') }}</div>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-700">{{ $publication->assignee?->name ?? __('admin.manual_publications.unassigned') }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">{{ $publication->scheduled_at?->format('Y-m-d H:i') ?? __('admin.manual_publications.unscheduled') }}</td>
                            <td class="px-5 py-4 text-sm">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ __('admin.manual_publications.status.'.$publication->status) }}</span>
                                @if($publication->risk_status !== 'clean')
                                    <div class="mt-1 text-xs font-medium text-red-600">{{ __('admin.manual_publications.risk.'.$publication->risk_status) }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm">
                                <a href="{{ route('admin.manual-publications.show', ['manualPublicationId' => $publication->id]) }}" class="font-semibold text-blue-600 hover:text-blue-800">{{ __('admin.manual_publications.button.view') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">{{ __('admin.manual_publications.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-200 px-5 py-4">{{ $publications->links() }}</div>
    </div>
</div>
@endsection
