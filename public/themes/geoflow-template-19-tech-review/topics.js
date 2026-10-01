document.addEventListener('DOMContentLoaded', () => {
    const auxiliaryQuery = window.matchMedia('(max-width:900px)');
    const readingQuery = window.matchMedia('(max-width:700px)');
    const canToggleAuxiliary = element => {
        const summary = element.querySelector('summary');
        if (!summary) return false;
        const style = window.getComputedStyle(summary);
        return style.display !== 'none' && style.visibility !== 'hidden';
    };
    const updateAuxiliary = () => {
        document.querySelectorAll('[data-topic-auxiliary]').forEach(element => { element.open = !auxiliaryQuery.matches || !canToggleAuxiliary(element); });
    };
    const updateReadingIndex = () => {
        document.querySelectorAll('[data-topic-reading-index]').forEach(element => { element.open = !readingQuery.matches; });
    };
    updateAuxiliary();
    updateReadingIndex();
    auxiliaryQuery.addEventListener('change', updateAuxiliary);
    readingQuery.addEventListener('change', updateReadingIndex);
    window.addEventListener('resize', () => {
        document.querySelectorAll('[data-topic-auxiliary]').forEach(element => {
            if (!canToggleAuxiliary(element)) element.open = true;
        });
    });
    document.querySelectorAll('[data-topic-copy]').forEach(button => {
        button.addEventListener('click', async () => {
            const status = button.parentElement.querySelector('[data-topic-copy-status]');
            try {
                await navigator.clipboard.writeText(button.dataset.url);
                if (status) status.textContent = '链接已复制';
            } catch {
                if (status) status.textContent = '复制未完成，可以复制浏览器地址栏中的链接。';
            }
        });
    });
    const indexLinks = [...document.querySelectorAll('.topic-page-index a')];
    const markCurrent = () => {
        const hash = window.location.hash;
        indexLinks.forEach(link => {
            if (hash && link.hash === hash) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
    };
    markCurrent();
    window.addEventListener('hashchange', markCurrent);
});
