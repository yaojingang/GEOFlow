<footer class="bg-white border-t border-gray-100 mt-16">
    <div class="site-container px-4 sm:px-6 lg:px-8 py-8">
        <div class="text-center">
            <p class="text-gray-500 text-sm">{{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName }}</p>
            <a href="{{ route('site.about') }}" class="inline-block mt-3 text-sm text-gray-500 hover:text-gray-900">关于 GEOFlow</a>
            @include("site.partials.footer-filing")
        </div>
    </div>
</footer>
