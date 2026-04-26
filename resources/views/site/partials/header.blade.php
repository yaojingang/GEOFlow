@php
    $path = request()->path();
    $isHome = $path === '' || $path === '/';
@endphp
<header class="site-header bg-white border-b border-gray-100 sticky top-0 z-50">
    <div class="site-container px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <div class="flex items-center">
                <a href="{{ route('site.home') }}" class="flex items-center">
                    @if(!empty($siteLogo))
                        <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="h-9 w-auto max-w-48 object-contain">
                    @else
                        <span class="text-lg sm:text-xl font-bold text-gray-900">{{ $siteName }}</span>
                    @endif
                </a>
            </div>

            <nav class="hidden md:flex items-center space-x-6">
                <a href="{{ route('site.home') }}" class="flex items-center text-sm font-medium {{ $isHome ? 'text-gray-900' : 'text-gray-600 hover:text-gray-900' }}">
                    <i data-lucide="home" class="w-4 h-4 mr-1"></i>
                    {{ __('front.nav.home') }}
                </a>

                <div class="relative" id="categoryDropdown">
                    <button type="button" class="flex items-center text-gray-600 hover:text-gray-900 font-medium text-sm" onclick="toggleCategoryDropdown()">
                        <i data-lucide="folder" class="w-4 h-4 mr-1"></i>
                        {{ __('front.nav.categories') }}
                        <i data-lucide="chevron-down" class="w-4 h-4 ml-1"></i>
                    </button>

                    <div id="categoryDropdownMenu" class="absolute left-0 mt-2 w-56 bg-white rounded-lg shadow-lg border border-gray-100 py-2 hidden">
                        <a href="{{ route('site.home') }}" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-50 text-sm">
                            <i data-lucide="home" class="w-4 h-4 mr-3"></i>
                            {{ __('front.nav.all_articles') }}
                        </a>
                        @foreach($navCategories as $categoryItem)
                            <a href="{{ route('site.category', $categoryItem->slug) }}" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-50 text-sm">
                                <i data-lucide="folder" class="w-4 h-4 mr-3"></i>
                                {{ $categoryItem->name }}
                                <span class="ml-auto text-xs text-gray-400">({{ (int) ($categoryItem->published_count ?? 0) }})</span>
                            </a>
                        @endforeach
                    </div>
                </div>

            </nav>

            <button type="button" class="mobile-menu-toggle md:hidden flex items-center justify-center w-11 h-11 rounded-xl text-gray-600 hover:text-gray-900 hover:bg-gray-50" onclick="toggleMobileMenu()">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
        </div>

        <div id="mobileMenu" class="mobile-panel md:hidden hidden border-t border-gray-100 py-4">
            <nav class="flex flex-col space-y-4">
                <a href="{{ route('site.home') }}" class="mobile-nav-link flex items-center text-sm font-medium {{ $isHome ? 'text-gray-900' : 'text-gray-600 hover:text-gray-900' }}">
                    <i data-lucide="home" class="w-4 h-4 mr-3"></i>
                    {{ __('front.nav.home') }}
                </a>
                <button type="button" class="mobile-nav-link flex items-center justify-between text-gray-600 hover:text-gray-900 font-medium py-2 text-sm w-full" onclick="toggleMobileCategoryMenu()">
                    <span class="flex items-center">
                        <i data-lucide="folder" class="w-4 h-4 mr-3"></i>
                        {{ __('front.nav.categories') }}
                    </span>
                    <i data-lucide="chevron-down" class="w-4 h-4" id="mobileCategoryChevron"></i>
                </button>
                <div id="mobileCategoryMenu" class="hidden ml-4 space-y-2">
                    @foreach($navCategories as $categoryItem)
                        <a href="{{ route('site.category', $categoryItem->slug) }}" class="mobile-subnav-link flex items-center text-gray-600 hover:text-gray-900 py-1 text-sm">
                            <i data-lucide="folder" class="w-4 h-4 mr-3"></i>
                            {{ $categoryItem->name }}
                        </a>
                    @endforeach
                </div>
            </nav>
        </div>
    </div>
</header>

<script>
function toggleCategoryDropdown() {
    const menu = document.getElementById('categoryDropdownMenu');
    if (menu) {
        menu.classList.toggle('hidden');
    }
}

function toggleMobileMenu() {
    const menu = document.getElementById('mobileMenu');
    if (menu) {
        menu.classList.toggle('hidden');
    }
}

function toggleMobileCategoryMenu() {
    const menu = document.getElementById('mobileCategoryMenu');
    const chevron = document.getElementById('mobileCategoryChevron');
    if (menu) {
        menu.classList.toggle('hidden');
    }
    if (chevron) {
        chevron.classList.toggle('rotate-180');
    }
}

document.addEventListener('click', function (event) {
    const dropdown = document.getElementById('categoryDropdown');
    const menu = document.getElementById('categoryDropdownMenu');
    if (dropdown && menu && !dropdown.contains(event.target)) {
        menu.classList.add('hidden');
    }
});
</script>
