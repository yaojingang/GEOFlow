(() => {
    const form = document.getElementById('friend-links-form');
    if (!form) return;

    const rows = form.querySelector('[data-friend-rows]');
    const add = form.querySelector('[data-friend-add]');
    const undo = form.querySelector('[data-friend-undo]');
    const removed = [];
    let dirty = form.dataset.hasErrors === '1';
    let leaving = false;
    let saving = false;
    const submit = form.querySelector('[type="submit"]');
    const submitLabel = submit.textContent;
    const reloadRequired = form.dataset.reloadRequired === '1';
    const resetSubmission = () => {
        saving = false;
        leaving = false;
        submit.disabled = reloadRequired;
        submit.textContent = submitLabel;
    };
    const signature = () => JSON.stringify([...new FormData(form).entries()]);
    const initial = signature();
    const refresh = () => {
        [...rows.children].forEach((row, index) => {
            row.querySelector('[data-row-number]').textContent = String(index + 1);
            const rowTitle = row.querySelector('[data-friend-row-title]');
            if (rowTitle) {
                rowTitle.id = `friend-link-row-${index}`;
                row.setAttribute('aria-labelledby', rowTitle.id);
            }
            row.querySelectorAll('[data-field]').forEach(input => {
                input.name = `friend_links[links][${index}][${input.dataset.field}]`;
            });
            row.querySelectorAll('[data-friend-control]').forEach(input => {
                const id = `friend-link-${index}-${input.dataset.field}`;
                input.id = id;
                row.querySelector(`[data-friend-label="${input.dataset.field}"]`)?.setAttribute('for', id);
            });
        });
        if (!reloadRequired) form.querySelector('[data-link-count]').value = String(rows.children.length);
        add.disabled = rows.children.length >= 50;
        undo.hidden = removed.length === 0;
        undo.disabled = rows.children.length >= 50;
        form.querySelector('[data-friend-empty]').hidden = rows.children.length !== 0;
        dirty = form.dataset.hasErrors === '1' || signature() !== initial;
        form.querySelector('[data-friend-dirty]').hidden = !dirty;
    };
    add.addEventListener('click', () => {
        if (rows.children.length >= 50) return;
        const row = document.getElementById('friend-link-row-template').content.firstElementChild.cloneNode(true);
        rows.append(row);
        refresh();
        row.querySelector('[data-field="name"]').focus();
    });
    rows.addEventListener('click', event => {
        const button = event.target.closest('[data-friend-remove]');
        if (!button) return;
        const row = button.closest('[data-friend-row]');
        removed.push({row, index: [...rows.children].indexOf(row)});
        row.remove();
        refresh();
        undo.focus();
    });
    undo.addEventListener('click', () => {
        if (rows.children.length >= 50 || !removed.length) return;
        const {row, index} = removed.pop();
        rows.insertBefore(row, rows.children[index] || null);
        refresh();
        row.querySelector('[data-field="name"]').focus();
    });
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    window.addEventListener('beforeunload', event => {
        // Another settings form may request a native leave warning. If navigation
        // is cancelled, this page stays alive and the pending button must recover.
        setTimeout(() => { if (event.defaultPrevented) resetSubmission(); }, 0);
        if (!dirty || leaving) return;
        event.preventDefault();
        event.returnValue = '';
    });
    document.addEventListener('submit', event => {
        if (event.target !== form) {
            if (dirty && !window.confirm(form.dataset.leaveMessage)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            } else {
                leaving = true;
            }
            return;
        }
        if (saving || reloadRequired) {
            event.preventDefault();
            return;
        }
        refresh();
        saving = true;
        leaving = true;
        submit.disabled = true;
        submit.textContent = form.dataset.savingLabel;
    }, true);
    form.querySelector('[data-friend-reload]')?.addEventListener('click', event => {
        event.preventDefault();
        if (!window.confirm(form.dataset.reloadMessage)) return;
        leaving = true;
        window.location.reload();
    });
    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        resetSubmission();
        refresh();
    });
    refresh();
    if (form.dataset.hasErrors === '1') {
        const focusError = () => {
            const invalidField = form.querySelector('[aria-invalid="true"]');
            if (invalidField) {
                invalidField.focus({preventScroll: true});
                invalidField.scrollIntoView({block: 'center'});
            } else {
                document.getElementById('friend-links-errors')?.focus();
            }
        };
        // The browser's initial fragment navigation can replace an earlier focus.
        if (document.readyState === 'complete') {
            focusError();
        } else {
            window.addEventListener('load', focusError, {once: true});
        }
    }
})();
