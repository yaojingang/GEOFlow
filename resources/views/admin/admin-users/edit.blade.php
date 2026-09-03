@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-0">
        <header class="mb-6 flex items-start gap-4 sm:mb-8">
            <a href="{{ route('admin.admin-users.index') }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 hover:bg-gray-50 hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-balance text-2xl font-bold text-gray-900">{{ __('admin.admin_users.modal_edit') }}</h1>
                <p class="mt-1 max-w-3xl text-pretty text-sm leading-6 text-gray-600">{{ __('admin.admin_users.edit_help') }}</p>
            </div>
        </header>

        <form method="POST" action="{{ route('admin.admin-users.update', ['adminId' => $targetAdmin->id]) }}" class="overflow-hidden rounded-xl bg-white shadow">
            @csrf

            <div class="border-b border-gray-200 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                        <i data-lucide="user-round-pen" class="h-4.5 w-4.5"></i>
                    </span>
                    <div class="min-w-0">
                        <h2 class="break-words text-lg font-semibold text-gray-900">{{ $targetAdmin->display_name ?: $targetAdmin->username }}</h2>
                        <p class="mt-1 break-all text-sm leading-6 text-gray-600">{{ $targetAdmin->username }}</p>
                    </div>
                </div>
            </div>

            <div class="px-5 py-6 sm:px-6">
                <x-admin.admin-user-form-fields
                    mode="edit"
                    :target-admin="$targetAdmin"
                    :is-self="$isSelf"
                    :shared-provider="$sharedProvider"
                    :switch-shared-provider="$switchSharedProvider"
                    :sharing-impact="$sharingImpact"
                />
            </div>

            <div class="flex flex-wrap items-center justify-start gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 sm:justify-end sm:px-6">
                <a href="{{ route('admin.admin-users.index') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[color,background-color,transform] duration-150 hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    {{ __('admin.button.cancel') }}
                </a>
                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.admin_users.update_admin_submit') }}
                </button>
            </div>
        </form>
    </div>
@endsection
