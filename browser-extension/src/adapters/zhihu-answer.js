export function runZhihuAnswerAdapter(payload, expectedProfileUrl, replaceExisting = false) {
    const normalizeProfile = (value) => {
        try {
            const url = new URL(value, window.location.origin);
            url.search = '';
            url.hash = '';
            return url.toString().replace(/\/$/, '').toLowerCase();
        } catch {
            return '';
        }
    };

    const host = window.location.hostname.toLowerCase();
    if (host !== 'zhihu.com' && ! host.endsWith('.zhihu.com')) {
        return { ok: false, code: 'wrong_platform' };
    }
    if (! /^\/question\/\d+/.test(window.location.pathname)) {
        return { ok: false, code: 'wrong_target_page' };
    }
    if (document.querySelector('iframe[src*="captcha"], [class*="Captcha"], [class*="captcha"]')) {
        return { ok: false, code: 'human_verification_required' };
    }

    const profileSelectors = [
        'a.AppHeader-profileEntry[href*="/people/"]',
        '.AppHeader-profile a[href*="/people/"]',
    ];
    let profileLink = null;
    for (const selector of profileSelectors) {
        profileLink = document.querySelector(selector);
        if (profileLink) break;
    }
    if (! profileLink?.href) {
        return { ok: false, code: 'login_required' };
    }

    const observedProfileUrl = normalizeProfile(profileLink.href);
    if (! observedProfileUrl || observedProfileUrl !== normalizeProfile(expectedProfileUrl)) {
        return { ok: false, code: 'account_mismatch', observedProfileUrl };
    }

    const editorSelectors = [
        '.AnswerForm-editor .RichText[contenteditable="true"]',
        '.AnswerForm-editor .DraftEditor-root [contenteditable="true"]',
        '[contenteditable="true"][role="textbox"]',
        '.RichText[contenteditable="true"]',
    ];
    let editor = null;
    for (const selector of editorSelectors) {
        editor = document.querySelector(selector);
        if (editor) break;
    }
    if (! editor) {
        return { ok: false, code: 'editor_not_found' };
    }

    const body = String(payload?.body_plain ?? '').trim();
    if (! body) {
        return { ok: false, code: 'empty_content' };
    }
    if (String(editor.textContent ?? '').trim() && ! replaceExisting) {
        return { ok: false, code: 'editor_not_empty' };
    }

    editor.focus();
    if (window.getSelection && document.createRange) {
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(editor);
        selection.removeAllRanges();
        selection.addRange(range);
    }
    const inserted = typeof document.execCommand === 'function'
        ? document.execCommand('insertText', false, body)
        : false;
    if (! inserted) {
        editor.textContent = body;
        const InputEventConstructor = window.InputEvent ?? window.Event;
        editor.dispatchEvent(new InputEventConstructor('input', { bubbles: true, inputType: 'insertText', data: body }));
    }

    return { ok: true, code: 'draft_filled', observedProfileUrl, characterCount: body.length };
}

export function observeZhihuAnswerResult() {
    const url = window.location.href;
    const parsed = new URL(url);
    const completed = /^\/question\/\d+\/answer\/\d+/.test(parsed.pathname);

    return completed
        ? { outcome: 'completed', completionUrl: url }
        : { outcome: 'outcome_unknown', completionUrl: null };
}
