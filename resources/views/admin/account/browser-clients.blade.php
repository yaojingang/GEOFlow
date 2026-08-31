@extends('admin.layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('admin.account.show') }}" class="text-sm font-medium text-blue-700 hover:text-blue-900">{{ __('admin.browser_operations.clients.back') }}</a>
            <h1 class="mt-3 text-2xl font-bold text-gray-950">{{ __('admin.browser_operations.clients.heading') }}</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-gray-600">{{ __('admin.browser_operations.clients.description') }}</p>
        </div>
    </div>

    <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
        @if(empty($tokens))
            <div class="px-6 py-12 text-center text-sm text-gray-500">{{ __('admin.browser_operations.clients.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('admin.browser_operations.clients.name') }}</th>
                        @if($admin->isSuperAdmin())<th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('admin.browser_operations.clients.owner') }}</th>@endif
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('admin.browser_operations.clients.last_used') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('admin.browser_operations.clients.expires') }}</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('admin.common.actions') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                    @foreach($tokens as $token)
                        <tr>
                            <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $token['name'] }}</td>
                            @if($admin->isSuperAdmin())<td class="px-6 py-4 text-sm text-gray-600">{{ $token['created_by_username'] }}</td>@endif
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $token['last_used_at'] ?: __('admin.browser_operations.clients.never') }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $token['expires_at'] }}</td>
                            <td class="px-6 py-4 text-right">
                                <form method="POST" action="{{ route('admin.account.browser-clients.destroy', ['tokenId' => $token['id']]) }}" data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.browser_operations.clients.confirm') }}" data-admin-confirm-message="{{ __('admin.action_dialog.target', ['name' => $token['name']]) }}" data-admin-confirm-guidance="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.browser_operations.clients.disconnect') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="min-h-10 rounded-lg px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 active:scale-[.96]" data-admin-confirm-submit disabled aria-disabled="true">{{ __('admin.browser_operations.clients.disconnect') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
