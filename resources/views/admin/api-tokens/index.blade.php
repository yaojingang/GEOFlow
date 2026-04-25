@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.api_tokens.page_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.api_tokens.page_subtitle') }}</p>
            </div>
        </div>

        @if (session('new_api_token'))
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-4">
                <div class="text-sm font-medium text-amber-900">{{ __('admin.api_tokens.notice.one_time_visible') }}</div>
                <div class="mt-3 flex items-center gap-3">
                    <code id="new-api-token" class="flex-1 break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm text-amber-900">{{ session('new_api_token') }}</code>
                    <button type="button" id="copy-api-token-btn" class="inline-flex items-center rounded-md border border-amber-300 bg-white px-3 py-2 text-xs font-medium text-amber-800 hover:bg-amber-100">
                        <i data-lucide="copy" class="mr-1 h-3.5 w-3.5"></i>
                        {{ __('admin.api_tokens.button.copy') }}
                    </button>
                </div>
            </div>
        @endif

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.api_tokens.section.create') }}</h3>
            </div>
            <div class="px-6 py-5">
                <form action="{{ route('admin.api-tokens.store') }}" method="POST" class="space-y-6">
                    @csrf

                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">{{ __('admin.api_tokens.field.name') }}</label>
                        <input id="name" name="name" type="text" required value="{{ old('name') }}" placeholder="{{ __('admin.api_tokens.placeholder.name') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label for="expires_at" class="block text-sm font-medium text-gray-700">{{ __('admin.api_tokens.field.expires_at') }}</label>
                        <input id="expires_at" name="expires_at" type="datetime-local" value="{{ old('expires_at') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.api_tokens.help.expires_at') }}</p>
                    </div>

                    <div>
                        <div class="mb-3 block text-sm font-medium text-gray-700">Scopes *</div>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            @foreach ($availableScopes as $scope)
                                <label class="flex items-center gap-2 rounded border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                    <input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('scopes', []), true)) class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>{{ $scope }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.api_tokens.button.create') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.api_tokens.section.list') }}</h3>
            </div>

            @if (empty($tokens))
                <div class="px-6 py-8 text-center text-sm text-gray-500">{{ __('admin.api_tokens.empty.no_tokens') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.api_tokens.column.name') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Scopes</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.api_tokens.column.created_by') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.api_tokens.column.last_used') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.api_tokens.column.expires_at') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.api_tokens.column.status') }}</th>
                                <th class="whitespace-nowrap px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($tokens as $token)
                                <tr>
                                    <td class="px-6 py-4 text-sm text-gray-900">{{ $token['name'] ?? '' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ implode(', ', $token['scopes'] ?? []) }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $token['created_by_username'] !== '' ? $token['created_by_username'] : __('admin.api_tokens.value.system') }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $token['last_used_at'] ?? __('admin.api_tokens.value.never_used') }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $token['expires_at'] ?? __('admin.api_tokens.value.no_expiry') }}</td>
                                    <td class="px-6 py-4 text-sm">
                                        @if (($token['status'] ?? 'active') === 'active')
                                            <span class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800">active</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">revoked</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                                        @if (($token['status'] ?? 'active') === 'active')
                                            <form action="{{ route('admin.api-tokens.revoke', ['tokenId' => (int) ($token['id'] ?? 0)]) }}" method="POST" onsubmit="return confirm(@js(__('admin.api_tokens.confirm.revoke')));">
                                                @csrf
                                                <button type="submit" class="whitespace-nowrap text-red-600 hover:text-red-800">{{ __('admin.api_tokens.button.revoke') }}</button>
                                            </form>
                                        @else
                                            <span class="text-gray-400">{{ __('admin.api_tokens.status.revoked') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const copyButton = document.getElementById('copy-api-token-btn');
            const tokenElement = document.getElementById('new-api-token');
            if (!copyButton || !tokenElement) {
                return;
            }

            async function copyToken(text) {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                    return true;
                }

                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', 'readonly');
                textarea.style.position = 'fixed';
                textarea.style.top = '-9999px';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();

                let copied = false;
                try {
                    copied = document.execCommand('copy');
                } finally {
                    document.body.removeChild(textarea);
                }

                return copied;
            }

            copyButton.addEventListener('click', async function () {
                const tokenText = tokenElement.textContent ? tokenElement.textContent.trim() : '';
                if (tokenText === '') {
                    return;
                }

                try {
                    const copied = await copyToken(tokenText);
                    if (copied && window.AdminUtils && typeof window.AdminUtils.showToast === 'function') {
                        window.AdminUtils.showToast(@json(__('admin.message.copied')), 'success');
                    }
                    if (!copied) {
                        window.prompt('复制失败，请手动复制 Token：', tokenText);
                    }
                } catch (error) {
                    window.prompt('复制失败，请手动复制 Token：', tokenText);
                }
            });
        });
    </script>
@endpush
