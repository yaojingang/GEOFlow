(() => {
    'use strict';
    const messageType = 'geoflow-theme-preview:navigate';
    const shell = document.querySelector('[data-theme-preview-shell]');
    const bridge = document.querySelector('[data-theme-preview-bridge]');
    const marker = shell || bridge;
    if (!marker) return;
    const base = new URL(marker.dataset.frameBase, location.href);
    const prefix = base.pathname.replace(/\/$/, '');
    const mapUrl = (value) => {
        if (typeof value !== 'string' || /%(?:2f|5c|2e)|\\/i.test(value.split(/[?#]/)[0])) return null;
        let url;
        try { url = new URL(value, location.href); } catch { return null; }
        if (url.origin !== base.origin || url.username || url.password) return null;
        let path = url.pathname;
        if (path === prefix) path = '/';
        else if (path.startsWith(`${prefix}/`)) path = path.slice(prefix.length);
        if (!/^\/(?:about|archive(?:\/\d{4}\/\d{2})?|(?:category|article)\/[^/]+)?$/.test(path)) return null;
        if (/%(?:2f|5c|2e)|\\/i.test(path)) return null;
        return `${base.origin}${prefix}${path}${url.search}${url.hash}`;
    };
    if (shell) {
        const frame = shell.querySelector('[data-theme-preview-frame]');
        window.addEventListener('message', (event) => {
            if (!frame || event.source !== frame.contentWindow || event.data?.type !== messageType || typeof event.data.url !== 'string') return;
            const url = mapUrl(event.data.url);
            if (url) frame.src = url;
        });
        return;
    }
    const prepareAnchor = (anchor) => {
        if (!anchor.matches?.('a[href]')) return;
        const raw = anchor.getAttribute('href');
        if (raw.startsWith('#')) return;
        const mapped = mapUrl(anchor.href);
        if (mapped && anchor.href !== mapped) anchor.href = mapped;
    };
    document.querySelectorAll('a[href]').forEach(prepareAnchor);
    new MutationObserver((records) => {
        records.forEach((record) => {
            if (record.type === 'attributes') prepareAnchor(record.target);
            record.addedNodes?.forEach((node) => {
                if (node.matches?.('a[href]')) prepareAnchor(node);
                node.querySelectorAll?.('a[href]').forEach(prepareAnchor);
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
    const navigate = (url) => {
        const target = mapUrl(url);
        if (target) window.parent.postMessage({ type: messageType, url: target }, base.origin);
    };
    const search = (form, submitter) => {
        const method = submitter?.getAttribute?.('formmethod') || form.method || 'get';
        if (method.toLowerCase() !== 'get' || (form.reportValidity && !form.reportValidity())) return;
        const action = submitter?.getAttribute?.('formaction') || form.action || location.href;
        const mapped = mapUrl(action);
        if (!mapped) return;
        const target = new URL(mapped);
        target.search = new URLSearchParams(new FormData(form, submitter)).toString();
        navigate(target.href);
    };
    document.addEventListener('click', (event) => {
        const control = event.target.closest?.('button, input');
        if (control?.form && ['submit', 'image'].includes(control.type) && event.button === 0) {
            event.preventDefault();
            search(control.form, control);
            return;
        }
        const anchor = event.target.closest?.('a[href]');
        if (!anchor || event.button !== 0) return;
        const raw = anchor.getAttribute('href');
        if (raw.startsWith('#')) return;
        let url;
        try { url = new URL(anchor.href, location.href); } catch { event.preventDefault(); return; }
        if (url.origin !== base.origin && ['https:', 'http:', 'tel:', 'mailto:'].includes(url.protocol)) {
            anchor.target = '_blank';
            anchor.rel = 'noopener noreferrer';
            return;
        }
        event.preventDefault();
        navigate(url.href);
    }, true);
    // Sandbox blocks native form submission before the submit event is dispatched.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.isComposing || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        const input = event.target;
        if (input.tagName !== 'INPUT' || !input.form || !['text', 'search', 'email', 'url', 'tel', 'number', 'password'].includes(input.type)) return;
        event.preventDefault();
        const submitter = Array.from(document.querySelectorAll('button, input'))
            .find((control) => control.form === input.form && ['submit', 'image'].includes(control.type));
        if (!submitter?.disabled) search(input.form, submitter);
    }, true);
    document.addEventListener('submit', (event) => {
        event.preventDefault();
        search(event.target, event.submitter);
    }, true);
})();
