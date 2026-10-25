import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const blade = fs.readFileSync(new URL('../../resources/views/admin/tasks/create.blade.php', import.meta.url), 'utf8');
const source = blade.match(/\(\(\)=>\{const form=document.querySelector\('\[data-task-form\]'\);if\(!form\)return;const key='geoflow:task:new:article';[^\n]+/)[0];

function restore(hasErrors) {
    const input = { name: 'name', value: 'Corrected validation input' };
    const events = {};
    const cache = new Map([['geoflow:task:new:article', JSON.stringify([['name', 'Earlier unsaved input']])]]);
    const form = { elements: [input], addEventListener: (event, handler) => { events[event] = handler; } };
    const chooser = { addEventListener: (event, handler) => { events.chooser = handler; } };
    vm.runInNewContext(source.replace('@json($errors->any())', JSON.stringify(hasErrors)), {
        document: { querySelector: selector => selector === '[data-task-form]' ? form : chooser },
        sessionStorage: { getItem: key => cache.get(key), setItem: (key, value) => cache.set(key, value), removeItem: key => cache.delete(key) },
        FormData: function () { return { entries: () => [['name', input.value]] }; },
    });
    return { input, events, cache };
}

test('article task switch restores same-type input when no validation errors exist', () => {
    const state = restore(false);
    assert.equal(state.input.value, 'Earlier unsaved input');
    state.input.value = 'New typed name';
    state.events.chooser();
    assert.deepEqual(JSON.parse(state.cache.get('geoflow:task:new:article')), [['name', 'New typed name']]);
});

test('article task validation input wins over older session draft', () => {
    assert.equal(restore(true).input.value, 'Corrected validation input');
});

test('article task submit clears the switch cache', () => {
    const state = restore(false);
    state.events.submit();
    assert.equal(state.cache.has('geoflow:task:new:article'), false);
});


test('topic task cache preserves hidden false values when a restored checkbox is unchecked', () => {
 const blade=fs.readFileSync(new URL('../../resources/views/admin/tasks/topic.blade.php',import.meta.url),'utf8');
 const source=blade.match(/<script>(\(\(\)=>\{const f=document.querySelector\('\[data-topic-task\]'\)[\s\S]*?)<\/script>/)[1];
 const hidden={name:'topic_settings[protect_manual]',type:'hidden',value:'0'},checkbox={name:hidden.name,type:'checkbox',value:'1',checked:true};
 const form={dataset:{storageKey:'key',hasErrors:'false'},elements:[hidden,checkbox],querySelectorAll:s=>s==='[type=checkbox]'?[checkbox]:[],querySelector:()=>({value:'1',selectedOptions:[{textContent:'option'}]}),addEventListener:()=>{}};
 vm.runInNewContext(source,{document:{querySelector:s=>s==='[data-topic-task]'?form:{addEventListener:()=>{}},querySelectorAll:()=>[]},sessionStorage:{getItem:()=>JSON.stringify([[hidden.name,'0'],[hidden.name,'1']]),removeItem:()=>{}},FormData:function(){}});
 checkbox.checked=false;assert.equal(hidden.value,'0');
});


test('task status copy uses topic and article limits consistently', () => {
    const indexBlade = fs.readFileSync(new URL('../../resources/views/admin/tasks/index.blade.php', import.meta.url), 'utf8');
    const source = indexBlade.match(/function updateBatchStatus\(task\) \{[\s\S]*?\n\}/)[0];
    const status = { innerHTML: '' };
    const context = {
        document: { getElementById: () => status },
        TASK_I18N: { topicLimitReached: 'Topic limit reached', limitReached: 'Article limit reached' },
        escapeHtml: value => value,
        normalizeRuntimeError: value => value,
    };
    vm.runInNewContext(source, context);
    context.updateBatchStatus({ id: 1, content_type: 'topic', batch_status: 'limit_reached' });
    assert.match(status.innerHTML, /Topic limit reached/);
    assert.doesNotMatch(status.innerHTML, /Article limit reached/);
    context.updateBatchStatus({ id: 2, content_type: 'article', batch_status: 'limit_reached' });
    assert.match(status.innerHTML, /Article limit reached/);
    assert.doesNotMatch(status.innerHTML, /Topic limit reached/);
});
