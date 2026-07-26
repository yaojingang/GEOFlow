<footer class="ne-footer">
    <div class="ne-shell">
        <div class="ne-footer-inner">
            {{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName.'. All rights reserved.' }}
            @include("site.partials.footer-filing")
        </div>
    </div>
</footer>
