<footer class="tt-footer">
    <div class="tt-shell">
        @include('site.partials.friend-links')
        <div class="tt-footer-inner">
            {{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName.'. All rights reserved.' }}
            @include("site.partials.footer-filing")
        </div>
    </div>
</footer>
