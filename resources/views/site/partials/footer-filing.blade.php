@if(($footerFilingInfo ?? '') !== '')
    <div class="mt-2 break-words">
        @if(($footerFilingUrl ?? '') !== '')
            <a href="{{ $footerFilingUrl }}"
               target="_blank"
               rel="nofollow noopener noreferrer"
               class="inline-flex min-h-10 max-w-full items-center px-1 underline decoration-current underline-offset-4 transition-opacity hover:opacity-75 focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current focus-visible:ring-offset-2">
                {{ $footerFilingInfo }}
            </a>
        @else
            <span class="inline-flex min-h-10 max-w-full items-center px-1">{{ $footerFilingInfo }}</span>
        @endif
    </div>
@endif
