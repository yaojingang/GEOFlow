import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/link-pool.js', import.meta.url), 'utf8');

function renderRecommendation() {
    const requests = [];
    const timers = new Map();
    let nextTimer = 1;
    let intersectionCallback;
    let rectangle = { top: 100, bottom: 140, left: 100, right: 700, width: 600, height: 40 };
    const link = { addEventListener(type, callback) { this[type] = callback; } };
    const pool = { dataset: { csrf: 'test-token' } };
    const item = {
        dataset: { placementId: '17' },
        closest() { return pool; },
        querySelector() { return link; },
        getBoundingClientRect() { return rectangle; },
    };
    const document = {
        visibilityState: 'visible',
        querySelectorAll() { return [item]; },
        addEventListener(type, callback) { this[type] = callback; },
    };
    class IntersectionObserver {
        constructor(callback) { intersectionCallback = callback; }
        observe() {}
        unobserve() {}
    }
    const window = { IntersectionObserver };
    vm.runInNewContext(source, {
        document, window, IntersectionObserver, crypto: { randomUUID: () => 'fixed-event-id' },
        fetch: (url, options) => { requests.push({ url, ...JSON.parse(options.body) }); return Promise.resolve(); },
        setTimeout: callback => { const id = nextTimer++; timers.set(id, callback); return id; },
        clearTimeout: id => timers.delete(id),
        innerHeight: 1000, innerWidth: 800,
    });

    return {
        requests, document, item, link,
        setRectangle(value) { rectangle = value; },
        intersect(ratio) { intersectionCallback([{ target: item, intersectionRatio: ratio, isIntersecting: ratio > 0 }]); },
        runTimers() { for (const [id, callback] of [...timers]) { timers.delete(id); callback(); } },
    };
}

test('viewable event requires at least half of the item still visible after one second', () => {
    const page = renderRecommendation();
    page.intersect(0.5);
    page.setRectangle({ top: 990, bottom: 1010, left: 100, right: 700, width: 600, height: 40 });
    page.runTimers();
    assert.equal(page.requests.length, 0);
});

test('leaving the view threshold cancels the pending viewable event', () => {
    const page = renderRecommendation();
    page.intersect(0.5);
    page.intersect(0.25);
    page.setRectangle({ top: 100, bottom: 140, left: 100, right: 700, width: 600, height: 40 });
    page.runTimers();
    assert.equal(page.requests.length, 0);
});

test('a sustained visible recommendation sends one viewable event and click remains a normal link action', () => {
    const page = renderRecommendation();
    page.intersect(0.5);
    page.runTimers();
    page.intersect(0.5);
    page.runTimers();
    assert.deepEqual(page.requests.map(request => request.event_type), ['viewable']);
    page.link.click();
    assert.deepEqual(page.requests.map(request => request.event_type), ['viewable', 'click']);
});
