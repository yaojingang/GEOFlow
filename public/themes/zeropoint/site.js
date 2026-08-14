const toggle = document.querySelector('[data-nav-toggle]');
const nav = document.querySelector('[data-site-nav]');

if (toggle && nav) {
    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        nav.classList.toggle('is-open', !expanded);
    });
}

if ('IntersectionObserver' in window) {
    const elements = [...document.querySelectorAll('.reveal')];
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });
    elements.forEach((element) => {
        if (element.getBoundingClientRect().top > window.innerHeight * 0.9) {
            element.classList.add('reveal-armed');
            observer.observe(element);
        } else {
            element.classList.add('is-visible');
        }
    });
} else {
    document.querySelectorAll('.reveal').forEach((element) => element.classList.add('is-visible'));
}
