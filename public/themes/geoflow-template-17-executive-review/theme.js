(function () {
    function initLucideIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function initHotCarousels() {
        document.querySelectorAll('[data-hot-carousel]').forEach(function (carousel) {
            const slides = Array.from(carousel.querySelectorAll('[data-hot-slide]'));
            const dots = Array.from(carousel.querySelectorAll('[data-hot-dot]'));

            if (slides.length <= 1) {
                return;
            }

            let activeIndex = Math.max(0, slides.findIndex(function (slide) {
                return slide.classList.contains('is-active');
            }));

            const activate = function (index) {
                activeIndex = index;
                slides.forEach(function (slide, slideIndex) {
                    slide.classList.toggle('is-active', slideIndex === index);
                });
                dots.forEach(function (dot, dotIndex) {
                    dot.classList.toggle('is-active', dotIndex === index);
                });
            };

            dots.forEach(function (dot, index) {
                dot.addEventListener('click', function () {
                    activate(index);
                });
            });

            window.setInterval(function () {
                activate((activeIndex + 1) % slides.length);
            }, 4500);
        });
    }

    function initHomePosterCarousels() {
        document.querySelectorAll('[data-home-poster-carousel]').forEach(function (carousel) {
            const slides = Array.from(carousel.querySelectorAll('[data-home-poster-slide]'));
            const dots = Array.from(carousel.querySelectorAll('[data-home-poster-dot]'));

            if (slides.length <= 1) {
                return;
            }

            let activeIndex = Math.max(0, slides.findIndex(function (slide) {
                return slide.classList.contains('is-active');
            }));

            const activate = function (index) {
                activeIndex = index;
                slides.forEach(function (slide, slideIndex) {
                    slide.classList.toggle('is-active', slideIndex === index);
                });
                dots.forEach(function (dot, dotIndex) {
                    dot.classList.toggle('is-active', dotIndex === index);
                });
            };

            dots.forEach(function (dot, index) {
                dot.addEventListener('click', function () {
                    activate(index);
                });
            });

            window.setInterval(function () {
                activate((activeIndex + 1) % slides.length);
            }, 10000);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initLucideIcons();
        initHotCarousels();
        initHomePosterCarousels();
    });
})();
