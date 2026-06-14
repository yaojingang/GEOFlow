@php
    $fieldName = $fieldName ?? 'content';
    $rows = (int) ($rows ?? 5);
    $placeholder = (string) ($placeholder ?? '');
    $value = (string) old($fieldName, $value ?? '');
    $minHeight = max(130, $rows * 34);
@endphp

<div
    data-crm-markdown-editor
    class="overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm transition focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500"
>
    <div class="flex flex-col gap-2 border-b border-gray-200 bg-gray-50 px-3 py-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="inline-flex rounded-md border border-gray-200 bg-white p-0.5 shadow-sm">
                <button type="button" data-crm-mode="write" class="inline-flex h-8 items-center rounded px-3 text-xs font-semibold transition">
                    编辑
                </button>
                <button type="button" data-crm-mode="preview" class="inline-flex h-8 items-center rounded px-3 text-xs font-semibold transition">
                    预览
                </button>
                <button type="button" data-crm-mode="source" class="inline-flex h-8 items-center rounded px-3 text-xs font-semibold transition">
                    源码
                </button>
            </div>
            <span class="text-xs text-gray-500">支持 Markdown，用于记录已发生的沟通结果</span>
        </div>

        <div data-crm-toolbar class="flex flex-wrap items-center gap-1">
            <button type="button" data-crm-action="heading" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" title="标题">
                <i data-lucide="heading" class="h-4 w-4"></i>
                <span class="sr-only">标题</span>
            </button>
            <button type="button" data-crm-action="bold" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" title="加粗">
                <i data-lucide="bold" class="h-4 w-4"></i>
                <span class="sr-only">加粗</span>
            </button>
            <button type="button" data-crm-action="italic" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" title="斜体">
                <i data-lucide="italic" class="h-4 w-4"></i>
                <span class="sr-only">斜体</span>
            </button>
            <button type="button" data-crm-action="quote" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" title="引用">
                <i data-lucide="quote" class="h-4 w-4"></i>
                <span class="sr-only">引用</span>
            </button>
            <button type="button" data-crm-action="list" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" title="无序列表">
                <i data-lucide="list" class="h-4 w-4"></i>
                <span class="sr-only">无序列表</span>
            </button>
            <button type="button" data-crm-action="ordered-list" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" title="有序列表">
                <i data-lucide="list-ordered" class="h-4 w-4"></i>
                <span class="sr-only">有序列表</span>
            </button>
            <button type="button" data-crm-action="link" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" title="链接">
                <i data-lucide="link" class="h-4 w-4"></i>
                <span class="sr-only">链接</span>
            </button>
            <button type="button" data-crm-action="code" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" title="行内代码">
                <i data-lucide="code-2" class="h-4 w-4"></i>
                <span class="sr-only">行内代码</span>
            </button>
            <button type="button" data-crm-action="divider" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" title="分隔线">
                <i data-lucide="minus" class="h-4 w-4"></i>
                <span class="sr-only">分隔线</span>
            </button>
        </div>
    </div>

    <div data-crm-panel="write">
        <textarea
            data-crm-textarea
            name="{{ $fieldName }}"
            rows="{{ $rows }}"
            placeholder="{{ $placeholder }}"
            class="block w-full resize-y border-0 px-4 py-3 text-sm leading-6 text-gray-800 outline-none placeholder:text-gray-400 focus:ring-0"
            style="min-height: {{ $minHeight }}px;"
        >{{ $value }}</textarea>
    </div>

    <div
        data-crm-panel="source"
        hidden
        style="min-height: {{ $minHeight }}px;"
        class="whitespace-pre-wrap bg-slate-950 px-4 py-3 font-mono text-xs leading-6 text-slate-100"
    ></div>

    <div
        data-crm-panel="preview"
        hidden
        style="min-height: {{ $minHeight }}px;"
        class="prose prose-sm max-w-none bg-white px-4 py-3 text-sm leading-6 text-gray-800"
    ></div>
</div>

@once
    <script>
        (function () {
            const modeButtonClass = 'inline-flex h-8 items-center rounded px-3 text-xs font-semibold transition';
            const activeModeClass = modeButtonClass + ' bg-blue-50 text-blue-700 shadow-sm';
            const inactiveModeClass = modeButtonClass + ' text-gray-600 hover:bg-gray-50 hover:text-gray-900';

            const escapeHtml = (value) => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const safeHref = (value) => {
                const href = String(value || '').trim();
                return /^(https?:\/\/|mailto:|tel:|\/|#)/i.test(href) ? href : '#';
            };

            const renderMarkdown = (content) => {
                const source = String(content || '').trim();
                if (!source) {
                    return '<span class="text-gray-400">暂无内容</span>';
                }

                let html = escapeHtml(source);
                html = html.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre class="my-3 overflow-x-auto rounded-md bg-gray-100 p-3 text-xs leading-5 text-gray-800"><code>$2</code></pre>');
                html = html.replace(/`([^`]+)`/g, '<code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-rose-700">$1</code>');
                html = html.replace(/^### (.+)$/gm, '<h4 class="mt-3 mb-1 text-sm font-semibold text-gray-900">$1</h4>');
                html = html.replace(/^## (.+)$/gm, '<h3 class="mt-3 mb-1 text-base font-semibold text-gray-900">$1</h3>');
                html = html.replace(/^# (.+)$/gm, '<h3 class="mt-3 mb-2 text-base font-bold text-gray-900">$1</h3>');
                html = html.replace(/^&gt; (.+)$/gm, '<blockquote class="my-2 border-l-4 border-blue-200 bg-blue-50 px-3 py-2 text-gray-700">$1</blockquote>');
                html = html.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
                html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
                html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, label, href) => '<a href="' + safeHref(href) + '" class="text-blue-600 underline underline-offset-2" target="_blank" rel="noopener noreferrer">' + label + '</a>');
                html = html.replace(/^- (.+)$/gm, '<li class="ml-5 list-disc">$1</li>');
                html = html.replace(/^\d+\. (.+)$/gm, '<li class="ml-5 list-decimal">$1</li>');
                html = html.replace(/^---$/gm, '<hr class="my-3 border-gray-200">');
                html = html.replace(/\n\n/g, '</p><p class="mb-2">');
                html = html.replace(/\n/g, '<br>');

                return '<p class="mb-2">' + html + '</p>';
            };

            const replacementFor = (syntax, selected) => {
                const hasSelection = selected.trim() !== '';

                switch (syntax) {
                    case 'bold':
                        return '**' + (hasSelection ? selected : '重点内容') + '**';
                    case 'italic':
                        return '*' + (hasSelection ? selected : '补充说明') + '*';
                    case 'link':
                        return '[' + (hasSelection ? selected : '链接文本') + '](https://)';
                    case 'code':
                        return '`' + (hasSelection ? selected : 'code') + '`';
                    case 'heading':
                        return '## ' + (hasSelection ? selected : '沟通结论');
                    case 'quote':
                        return '> ' + (hasSelection ? selected : '客户原话或重要反馈');
                    case 'list':
                        return hasSelection
                            ? selected.split('\n').map((line) => '- ' + line.replace(/^[-\d. ]+/, '')).join('\n')
                            : '- 客户反馈\n- 已确认事项\n- 后续注意点';
                    case 'ordered-list':
                        return hasSelection
                            ? selected.split('\n').map((line, index) => (index + 1) + '. ' + line.replace(/^[-\d. ]+/, '')).join('\n')
                            : '1. 已完成沟通\n2. 待客户确认\n3. 下次跟进重点';
                    case 'divider':
                        return '\n---\n';
                    default:
                        return selected;
                }
            };

            window.initCrmMarkdownEditors = function (root = document) {
                root.querySelectorAll('[data-crm-markdown-editor]:not([data-crm-editor-ready])').forEach((editor) => {
                    editor.dataset.crmEditorReady = 'true';

                    const textarea = editor.querySelector('[data-crm-textarea]');
                    const toolbar = editor.querySelector('[data-crm-toolbar]');
                    const modeButtons = Array.from(editor.querySelectorAll('[data-crm-mode]'));
                    const panels = Array.from(editor.querySelectorAll('[data-crm-panel]'));
                    let mode = 'write';

                    const syncPanels = () => {
                        const content = textarea.value;

                        panels.forEach((panel) => {
                            const panelMode = panel.dataset.crmPanel;
                            panel.hidden = panelMode !== mode;

                            if (panelMode === 'source') {
                                panel.textContent = content || '暂无内容';
                            }

                            if (panelMode === 'preview') {
                                panel.innerHTML = renderMarkdown(content);
                            }
                        });

                        if (toolbar) {
                            toolbar.classList.toggle('hidden', mode === 'preview');
                        }

                        modeButtons.forEach((button) => {
                            button.className = button.dataset.crmMode === mode ? activeModeClass : inactiveModeClass;
                        });
                    };

                    const setMode = (nextMode) => {
                        mode = nextMode;
                        syncPanels();
                    };

                    modeButtons.forEach((button) => {
                        button.addEventListener('click', () => setMode(button.dataset.crmMode));
                    });

                    editor.querySelectorAll('[data-crm-action]').forEach((button) => {
                        button.addEventListener('click', () => {
                            if (mode === 'preview') {
                                setMode('write');
                            }

                            const start = textarea.selectionStart || 0;
                            const end = textarea.selectionEnd || 0;
                            const selected = textarea.value.substring(start, end);
                            const replacement = replacementFor(button.dataset.crmAction, selected);

                            textarea.value = textarea.value.substring(0, start) + replacement + textarea.value.substring(end);
                            textarea.dispatchEvent(new Event('input', { bubbles: true }));

                            textarea.focus();
                            const cursor = start + replacement.length;
                            textarea.setSelectionRange(cursor, cursor);
                            syncPanels();
                        });
                    });

                    textarea.addEventListener('input', syncPanels);
                    syncPanels();
                });

                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => window.initCrmMarkdownEditors());
            } else {
                window.initCrmMarkdownEditors();
            }
        })();
    </script>
@endonce
