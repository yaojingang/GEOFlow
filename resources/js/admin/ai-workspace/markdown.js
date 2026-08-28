import DOMPurify from 'dompurify';
import { marked } from 'marked';

marked.use({
    gfm: true,
    breaks: true,
});

const allowedTags = ['p', 'br', 'strong', 'em', 's', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr'];
const shortListItemMaximumLength = 80;

export function normalizeAnswerMarkdown(markdown) {
    let fence = null;

    return String(markdown ?? '').split('\n').map((line) => {
        const fenceMatch = line.match(/^\s*(`{3,}|~{3,})/u);
        if (fenceMatch) {
            const marker = fenceMatch[1];
            if (fence === null) fence = { character: marker[0], length: marker.length };
            else if (marker[0] === fence.character && marker.length >= fence.length) fence = null;

            return line;
        }
        if (fence !== null) return line;

        const listItem = line.match(/^(\s*(?:[-+*]|\d+[.)])\s+)(\S.*)$/u);
        if (!listItem) return line;

        const content = listItem[2];
        const trimmed = content.trimEnd();
        if ([...trimmed].length > shortListItemMaximumLength) return line;

        const punctuationLength = trimmed.endsWith('。') || trimmed.endsWith('．')
            ? 1
            : trimmed.endsWith('.') && !trimmed.endsWith('...')
                ? 1
                : 0;
        if (punctuationLength === 0) return line;

        return `${listItem[1]}${trimmed.slice(0, -punctuationLength)}${content.slice(trimmed.length)}`;
    }).join('\n');
}

export function markdownBlockSources(markdown) {
    const blocks = [];

    marked.lexer(String(markdown ?? '')).forEach((token) => {
        const raw = String(token.raw ?? '');
        if (raw === '') return;
        if (token.type === 'space') {
            if (blocks.length > 0) blocks[blocks.length - 1] += raw;
            return;
        }
        blocks.push(raw);
    });

    return blocks;
}

function attachCodeCopyActions(target, labels) {
    target.querySelectorAll('pre').forEach((pre) => {
        if (pre.parentElement?.classList.contains('gf-ai-code-block')) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'gf-ai-code-block';
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'gf-ai-code-copy';
        const copyLabel = String(labels.copyCode ?? 'Copy code');
        const copiedLabel = String(labels.copied ?? 'Copied');
        const copyFailedLabel = String(labels.copyFailed ?? 'Copy failed');
        copy.textContent = copyLabel;
        copy.setAttribute('aria-label', copyLabel);
        copy.addEventListener('click', async () => {
            try {
                if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
                await navigator.clipboard.writeText(pre.textContent ?? '');
                copy.textContent = copiedLabel;
            } catch {
                copy.textContent = copyFailedLabel;
            }
            copy.disabled = true;
            window.setTimeout(() => {
                copy.textContent = copyLabel;
                copy.disabled = false;
            }, 1200);
        });
        pre.replaceWith(wrapper);
        wrapper.append(copy, pre);
    });
}

export function renderMarkdownInto(target, markdown, labels = {}) {
    if (!target) return;
    const unsafe = marked.parse(normalizeAnswerMarkdown(markdown), { async: false });
    target.innerHTML = DOMPurify.sanitize(unsafe, {
        ALLOWED_TAGS: allowedTags,
        ALLOWED_ATTR: [],
    });
    target.querySelectorAll('a[href]').forEach((link) => {
        link.replaceWith(document.createTextNode(link.textContent ?? ''));
    });
    attachCodeCopyActions(target, labels);
}

export function createStreamingMarkdownRenderer(target, labels = {}) {
    let sources = [];
    let nodes = [];

    const update = (markdown) => {
        const nextSources = markdownBlockSources(markdown);
        const nextNodes = [];

        nextSources.forEach((source, index) => {
            const isCompletedBlock = index < nextSources.length - 1;
            if (isCompletedBlock && sources[index] === source && nodes[index]) {
                nextNodes.push(nodes[index]);
                return;
            }

            const block = document.createElement('div');
            block.className = 'gf-ai-markdown-block';
            renderMarkdownInto(block, source, labels);
            nextNodes.push(block);
        });

        target.replaceChildren(...nextNodes);
        sources = nextSources;
        nodes = nextNodes;
    };

    return {
        update,
        finish(markdown) {
            renderMarkdownInto(target, markdown, labels);
            sources = [];
            nodes = [];
        },
        clear() {
            target.replaceChildren();
            sources = [];
            nodes = [];
        },
    };
}
