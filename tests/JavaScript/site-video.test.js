import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

test('theme video receives its source only after a click', () => {
    let click;
    let played = false;
    const video = {
        dataset: { siteVideoSrc: '/themes/example/clip.mp4' },
        hidden: true,
        play() {
            played = true;
            return Promise.resolve();
        },
    };
    const button = {
        hidden: false,
        parentElement: { querySelector: () => video },
    };
    const context = {
        window: {},
        document: { addEventListener: (type, handler) => { if (type === 'click') click = handler; } },
    };

    runInNewContext(readFileSync(new URL('../../public/js/site-video.js', import.meta.url), 'utf8'), context);
    assert.equal(video.src, undefined);
    assert.equal(video.hidden, true);

    click({ target: { closest: () => button } });
    assert.equal(video.src, '/themes/example/clip.mp4');
    assert.equal(video.hidden, false);
    assert.equal(button.hidden, true);
    assert.equal(played, true);
});
