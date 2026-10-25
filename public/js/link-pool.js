(() => {
    const endpoint = '/link-pool/events';
    const uuid = () => crypto.randomUUID();
    const send = (item, type) => {
        const pool = item.closest('[data-link-pool]');
        if (!pool || !item.dataset.placementId) return;
        fetch(endpoint, {
            method: 'POST', credentials: 'same-origin', keepalive: true,
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': pool.dataset.csrf},
            body: JSON.stringify({placement_id: Number(item.dataset.placementId), event_uuid: uuid(), event_type: type}),
        }).catch(() => {});
    };
    const items = [...document.querySelectorAll('[data-link-pool] [data-placement-id]')];
    items.forEach(item => item.querySelector('a')?.addEventListener('click', () => send(item, 'click')));
    if ('IntersectionObserver' in window) {
        const seen = new WeakSet();
        const timers = new Map();
        const visibleRatio = item => {
            const rect = item.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) return 0;
            const visibleWidth = Math.max(0, Math.min(rect.right, innerWidth) - Math.max(rect.left, 0));
            const visibleHeight = Math.max(0, Math.min(rect.bottom, innerHeight) - Math.max(rect.top, 0));
            return visibleWidth * visibleHeight / (rect.width * rect.height);
        };
        const observer = new IntersectionObserver(entries => entries.forEach(entry => {
            const item = entry.target;
            if (seen.has(item)) return;
            if (entry.intersectionRatio < .5 || document.visibilityState !== 'visible') {
                clearTimeout(timers.get(item));
                timers.delete(item);
                return;
            }
            if (timers.has(item)) return;
            timers.set(item, setTimeout(() => {
                timers.delete(item);
                if (document.visibilityState !== 'visible' || visibleRatio(item) < .5) return;
                seen.add(item);
                send(item, 'viewable');
                observer.unobserve(item);
            }, 1000));
        }), {threshold: .5});
        items.forEach(item => observer.observe(item));
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'visible') {
                timers.forEach(timer => clearTimeout(timer));
                timers.clear();
                return;
            }
            items.filter(item => !seen.has(item)).forEach(item => {
                observer.unobserve(item);
                observer.observe(item);
            });
        });
    }
})();
