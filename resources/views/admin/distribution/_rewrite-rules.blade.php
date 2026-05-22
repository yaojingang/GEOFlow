@php
    $rewriteBasePath = \App\Services\GeoFlow\DistributionRewriteRuleGenerator::basePathForEndpoint((string) $channel->endpoint_url);
    $apacheRewriteRule = \App\Services\GeoFlow\DistributionRewriteRuleGenerator::apacheHtaccess();
    $nginxRewriteRule = \App\Services\GeoFlow\DistributionRewriteRuleGenerator::nginxRewrite($channel);
    $btRewriteRule = \App\Services\GeoFlow\DistributionRewriteRuleGenerator::baotaRewriteOnly($channel);
    $rewriteBlockId = 'distribution-rewrite-'.$channel->id;
@endphp

<div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.rewrite.title') }}</h2>
            <p class="mt-1 text-sm leading-6 text-gray-600">
                {{ __('admin.distribution.rewrite.desc') }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
                {{ __('admin.distribution.rewrite.base_path', ['path' => $rewriteBasePath !== '' ? $rewriteBasePath : '/']) }}
            </p>
        </div>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-2 2xl:grid-cols-3">
        <div class="rounded-md border border-gray-200 bg-gray-50">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <div class="text-sm font-semibold text-gray-900">Apache .htaccess</div>
                <button type="button" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50" data-distribution-copy="#{{ $rewriteBlockId }}-apache" data-copy-label="{{ __('admin.distribution.rewrite.copy_apache') }}" data-copied-label="{{ __('admin.distribution.rewrite.copied') }}">
                    <i data-lucide="copy" class="mr-1.5 h-3.5 w-3.5"></i>
                    <span>{{ __('admin.distribution.rewrite.copy_apache') }}</span>
                </button>
            </div>
            <pre id="{{ $rewriteBlockId }}-apache" class="max-h-64 overflow-x-auto overflow-y-auto whitespace-pre px-4 py-3 text-xs leading-6 text-gray-800"><code>{{ $apacheRewriteRule }}</code></pre>
        </div>

        <div class="rounded-md border border-gray-200 bg-gray-50">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <div class="text-sm font-semibold text-gray-900">Nginx server 配置</div>
                <button type="button" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50" data-distribution-copy="#{{ $rewriteBlockId }}-nginx" data-copy-label="{{ __('admin.distribution.rewrite.copy_nginx') }}" data-copied-label="{{ __('admin.distribution.rewrite.copied') }}">
                    <i data-lucide="copy" class="mr-1.5 h-3.5 w-3.5"></i>
                    <span>{{ __('admin.distribution.rewrite.copy_nginx') }}</span>
                </button>
            </div>
            <pre id="{{ $rewriteBlockId }}-nginx" class="max-h-64 overflow-x-auto overflow-y-auto whitespace-pre px-4 py-3 text-xs leading-6 text-gray-800"><code>{{ $nginxRewriteRule }}</code></pre>
        </div>

        <div class="rounded-md border border-amber-200 bg-amber-50/70">
            <div class="flex items-center justify-between border-b border-amber-200 px-4 py-3">
                <div class="text-sm font-semibold text-gray-900">宝塔纯 rewrite</div>
                <button type="button" class="inline-flex items-center rounded-md border border-amber-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-amber-50" data-distribution-copy="#{{ $rewriteBlockId }}-bt" data-copy-label="{{ __('admin.distribution.rewrite.copy_bt') }}" data-copied-label="{{ __('admin.distribution.rewrite.copied') }}">
                    <i data-lucide="copy" class="mr-1.5 h-3.5 w-3.5"></i>
                    <span>{{ __('admin.distribution.rewrite.copy_bt') }}</span>
                </button>
            </div>
            <pre id="{{ $rewriteBlockId }}-bt" class="max-h-64 overflow-x-auto overflow-y-auto whitespace-pre px-4 py-3 text-xs leading-6 text-gray-800"><code>{{ $btRewriteRule }}</code></pre>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('click', function (event) {
                const button = event.target.closest('[data-distribution-copy]');
                if (!button) {
                    return;
                }

                const target = document.querySelector(button.dataset.distributionCopy || '');
                const text = target ? target.textContent.trim() : '';
                if (!text) {
                    return;
                }

                const label = button.querySelector('span');
                const original = button.dataset.copyLabel || (label ? label.textContent : '');
                const copied = button.dataset.copiedLabel || original;
                const markCopied = function () {
                    if (label) {
                        label.textContent = copied;
                        window.setTimeout(function () {
                            label.textContent = original;
                        }, 1600);
                    }
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(markCopied);
                    return;
                }

                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', 'readonly');
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                textarea.remove();
                markCopied();
            });
        </script>
    @endpush
@endonce
