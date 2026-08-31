@if (!empty($adminWelcomeModalPayload))
    <style>
        #admin-welcome-modal .admin-welcome-document {
            --admin-welcome-title-size: 24px;
            --admin-welcome-section-size: 16px;
            --admin-welcome-body-size: 14px;
            --admin-welcome-body-leading: 1.52;
            --admin-welcome-ink: #141413;
            --admin-welcome-body: #3d3d3a;
            --admin-welcome-muted: #5e5d59;
            --admin-welcome-brand: #1B365D;
            --admin-welcome-border: #e8e5da;
            max-width: 860px;
            background: #ffffff;
            color: var(--admin-welcome-body);
            font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
        }

        #admin-welcome-modal .admin-welcome-reader {
            max-width: 640px;
        }

        #admin-welcome-title {
            font-family: ui-serif, "Songti SC", "Noto Serif CJK SC", "Source Han Serif SC", Georgia, serif;
            font-size: var(--admin-welcome-title-size);
            font-weight: 500;
            line-height: 1.22;
            letter-spacing: 0;
            color: var(--admin-welcome-ink);
        }

        #admin-welcome-subtitle {
            margin-top: 12px;
            border-left: 3px solid #1B365D;
            padding-left: 14px;
            color: var(--admin-welcome-muted);
            font-size: var(--admin-welcome-body-size);
            font-weight: 400;
            line-height: var(--admin-welcome-body-leading);
            letter-spacing: 0;
        }

        #admin-welcome-content {
            margin-top: 24px;
            color: var(--admin-welcome-body);
            font-size: var(--admin-welcome-body-size);
            font-weight: 400;
            line-height: var(--admin-welcome-body-leading);
            letter-spacing: 0;
        }

        #admin-welcome-content .admin-welcome-paragraph {
            margin: 0;
        }

        #admin-welcome-content .admin-welcome-section-title {
            margin: 18px 0 8px;
            border-left: 3px solid #1B365D;
            padding-left: 10px;
            font-family: ui-serif, "Songti SC", "Noto Serif CJK SC", "Source Han Serif SC", Georgia, serif;
            font-size: var(--admin-welcome-section-size);
            font-weight: 500;
            line-height: 1.28;
            color: var(--admin-welcome-ink);
            letter-spacing: 0;
        }

        #admin-welcome-content .admin-welcome-list {
            display: grid;
            gap: 7px;
            margin: 0;
            padding: 0;
        }

        #admin-welcome-content .admin-welcome-list-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        #admin-welcome-content .admin-welcome-bullet {
            width: 4px;
            height: 4px;
            margin-top: 8px;
            flex-shrink: 0;
            border-radius: 9999px;
            background: var(--admin-welcome-brand);
        }

        #admin-welcome-modal .admin-welcome-meta-text {
            font-size: 12px;
            line-height: 1.45;
        }
    </style>

    <div id="admin-welcome-modal" class="hidden fixed inset-0 z-[70]" role="dialog" aria-modal="true" aria-labelledby="admin-welcome-title" aria-describedby="admin-welcome-subtitle">
        <div class="absolute inset-0 bg-[rgba(15,23,42,0.48)]" data-welcome-backdrop></div>
        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6 lg:p-8">
            <div data-kami-document data-welcome-dialog tabindex="-1" class="admin-welcome-document w-full overflow-hidden rounded-2xl border border-[#e8e5da] bg-white shadow-[0_24px_72px_rgba(15,23,42,0.28)] ring-1 ring-[#e8e5da]">
                <div class="border-b border-[#e8e5da] bg-white px-5 py-3.5 sm:px-7">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div id="admin-welcome-badge" class="admin-welcome-meta-text inline-flex rounded-full bg-[#EEF2F7] px-2.5 py-1 font-semibold text-[#1B365D]"></div>
                        </div>
                        <div class="flex items-center gap-2 self-start sm:self-auto">
                            <button type="button" data-welcome-switch class="admin-welcome-meta-text min-h-10 rounded-full border border-[#d1cfc5] bg-white px-3 py-1.5 font-medium text-[#3d3d3a] hover:border-[#1B365D] hover:text-[#1B365D]"></button>
                            <button type="button" data-welcome-close class="admin-welcome-meta-text min-h-10 rounded-full border border-[#d1cfc5] bg-white px-3 py-1.5 font-medium text-[#3d3d3a] hover:bg-[#f7f6f1]"></button>
                        </div>
                    </div>
                </div>

                <div class="max-h-[80vh] overflow-y-auto bg-white px-5 py-7 sm:px-7 sm:py-8">
                    <article class="admin-welcome-reader mx-auto">
                        <h2 id="admin-welcome-title"></h2>
                        <p id="admin-welcome-subtitle"></p>
                        <div id="admin-welcome-content" class="admin-welcome-document-body space-y-4"></div>
                    </article>

                    <div class="admin-welcome-reader mx-auto mt-7 border-t border-[#e8e5da] pt-4">
                        <p id="admin-welcome-links-label" class="admin-welcome-meta-text text-[#5e5d59]"></p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a id="admin-welcome-link-x" class="admin-welcome-meta-text inline-flex items-center rounded-full bg-[#EEF2F7] px-3 py-1.5 font-medium text-[#1B365D] ring-1 ring-[#d1cfc5] hover:bg-[#E4ECF5]" target="_blank" rel="noopener noreferrer"></a>
                            <a id="admin-welcome-link-github" class="admin-welcome-meta-text inline-flex items-center rounded-full bg-[#EEF2F7] px-3 py-1.5 font-medium text-[#1B365D] ring-1 ring-[#d1cfc5] hover:bg-[#E4ECF5]" target="_blank" rel="noopener noreferrer"></a>
                            <a id="admin-welcome-link-changelog" class="admin-welcome-meta-text inline-flex items-center rounded-full bg-[#EEF2F7] px-3 py-1.5 font-medium text-[#1B365D] ring-1 ring-[#d1cfc5] hover:bg-[#E4ECF5]" target="_blank" rel="noopener noreferrer"></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="admin-welcome-payload">@json($adminWelcomeModalPayload)</script>
    @verbatim
    <script>
        (function () {
            const modal = document.getElementById('admin-welcome-modal');
            const payloadNode = document.getElementById('admin-welcome-payload');
            if (!modal || !payloadNode) {
                return;
            }

            const payload = JSON.parse(payloadNode.textContent || '{}');
            const copy = payload.copy || {};
            const state = payload.state || {};
            const localeCycle = ['zh-CN', 'en'];
            let locale = 'zh-CN';
            let dismissedPersisted = !state.shouldAutoOpen;

            const badgeNode = document.getElementById('admin-welcome-badge');
            const titleNode = document.getElementById('admin-welcome-title');
            const subtitleNode = document.getElementById('admin-welcome-subtitle');
            const contentNode = document.getElementById('admin-welcome-content');
            const linksLabelNode = document.getElementById('admin-welcome-links-label');
            const linkXNode = document.getElementById('admin-welcome-link-x');
            const linkGithubNode = document.getElementById('admin-welcome-link-github');
            const linkChangelogNode = document.getElementById('admin-welcome-link-changelog');
            const switchButton = modal.querySelector('[data-welcome-switch]');
            const closeButtons = modal.querySelectorAll('[data-welcome-close]');
            const dialog = modal.querySelector('[data-welcome-dialog]');
            const backdrop = modal.querySelector('[data-welcome-backdrop]');
            let opener = null;

            function blockNode(block) {
                if (!block || !block.type) {
                    return null;
                }

                if (block.type === 'heading') {
                    const heading = document.createElement('h3');
                    heading.className = 'admin-welcome-section-title';
                    heading.textContent = block.content || '';
                    return heading;
                }

                if (block.type === 'list') {
                    const items = Array.isArray(block.items) ? block.items : [];
                    const list = document.createElement('ul');
                    list.className = 'admin-welcome-list';
                    items.forEach((item) => {
                        const listItem = document.createElement('li');
                        const bullet = document.createElement('span');
                        const text = document.createElement('span');
                        listItem.className = 'admin-welcome-list-item';
                        bullet.className = 'admin-welcome-bullet';
                        text.textContent = item;
                        listItem.append(bullet, text);
                        list.append(listItem);
                    });
                    return list;
                }

                const paragraph = document.createElement('p');
                paragraph.className = 'admin-welcome-paragraph';
                paragraph.textContent = block.content || '';
                return paragraph;
            }

            function render(nextLocale) {
                locale = localeCycle.includes(nextLocale) ? nextLocale : 'zh-CN';
                const localeCopy = copy[locale] || copy['zh-CN'] || {};
                const meta = localeCopy.meta || {};
                const letter = localeCopy.letter || {};
                const blocks = letter.blocks || [];

                badgeNode.textContent = meta.badge || '';
                titleNode.textContent = letter.title || '';
                subtitleNode.textContent = letter.subtitle || '';
                contentNode.replaceChildren(...blocks.map((block) => blockNode(block)).filter(Boolean));
                linksLabelNode.textContent = meta.links_label || '';
                linkXNode.textContent = meta.author_link || '';
                linkXNode.href = state.links?.x || '#';
                linkGithubNode.textContent = meta.github_link || '';
                linkGithubNode.href = state.links?.github || '#';
                linkChangelogNode.textContent = meta.changelog_link || '';
                linkChangelogNode.href = state.links?.changelog?.[locale] || state.links?.changelog?.['zh-CN'] || '#';
                switchButton.textContent = meta.switch_label || (locale === 'zh-CN' ? 'English' : '中文');
                closeButtons.forEach((button) => {
                    button.textContent = meta.close || 'Close';
                });
            }

            function openModal(nextOpener = document.activeElement) {
                opener = nextOpener;
                render('zh-CN');
                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                window.requestAnimationFrame(() => switchButton?.focus({ preventScroll: true }));
            }

            async function persistDismissIfNeeded() {
                if (dismissedPersisted || !state.dismissUrl || !state.csrfToken) {
                    return;
                }

                try {
                    const response = await fetch(state.dismissUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        body: new URLSearchParams({
                            _token: state.csrfToken,
                        }),
                    });

                    if (response.ok) {
                        dismissedPersisted = true;
                    }
                } catch (error) {
                    console.error('Failed to persist welcome dismissal', error);
                }
            }

            async function closeModal() {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                opener?.focus?.({ preventScroll: true });
                opener = null;
                await persistDismissIfNeeded();
            }

            switchButton.addEventListener('click', function () {
                render(locale === 'zh-CN' ? 'en' : 'zh-CN');
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            backdrop?.addEventListener('click', closeModal);
            dialog?.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    void closeModal();
                    return;
                }
                if (event.key !== 'Tab') return;
                const focusable = Array.from(dialog.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'));
                if (focusable.length === 0) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });

            document.querySelectorAll('[data-open-admin-welcome]').forEach((trigger) => {
                trigger.addEventListener('click', function (event) {
                    event.preventDefault();
                    openModal(trigger);
                });
            });

            if (state.shouldAutoOpen) {
                openModal();
            }
        })();
    </script>
    @endverbatim
@endif
