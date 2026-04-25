@if (!empty($adminWelcomeModalPayload))
    <div id="admin-welcome-modal" class="hidden fixed inset-0 z-[70]">
        <div class="absolute inset-0 bg-slate-950/45 backdrop-blur-sm"></div>
        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6 lg:p-8">
            <div class="w-full max-w-5xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl">
                <div class="border-b border-slate-200 bg-white px-6 py-4 sm:px-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div id="admin-welcome-badge" class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600"></div>
                        </div>
                        <div class="flex items-center gap-2 self-start sm:self-auto">
                            <button type="button" data-welcome-switch class="rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700"></button>
                            <button type="button" data-welcome-close class="rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:border-slate-300 hover:bg-slate-100"></button>
                        </div>
                    </div>
                </div>

                <div class="max-h-[80vh] overflow-y-auto bg-white px-6 py-8 sm:px-8 sm:py-10">
                    <article class="mx-auto max-w-3xl">
                        <h2 id="admin-welcome-title" class="text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl"></h2>
                        <p id="admin-welcome-subtitle" class="mt-4 text-lg leading-8 text-slate-600 sm:text-xl"></p>
                        <div id="admin-welcome-content" class="mt-8 space-y-6 text-[17px] leading-8 text-slate-700"></div>
                    </article>

                    <div class="mx-auto mt-10 max-w-3xl border-t border-slate-200 pt-6">
                        <p id="admin-welcome-links-label" class="text-sm text-slate-600"></p>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <a id="admin-welcome-link-x" class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-medium text-blue-700 shadow-sm ring-1 ring-slate-200 hover:bg-blue-50" target="_blank" rel="noopener noreferrer"></a>
                            <a id="admin-welcome-link-github" class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-medium text-blue-700 shadow-sm ring-1 ring-slate-200 hover:bg-blue-50" target="_blank" rel="noopener noreferrer"></a>
                            <a id="admin-welcome-link-changelog" class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-medium text-blue-700 shadow-sm ring-1 ring-slate-200 hover:bg-blue-50" target="_blank" rel="noopener noreferrer"></a>
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

            function blockHtml(block) {
                if (!block || !block.type) {
                    return '';
                }

                if (block.type === 'heading') {
                    return `<h3 class="pt-2 text-2xl font-semibold tracking-tight text-slate-900">${block.content || ''}</h3>`;
                }

                if (block.type === 'list') {
                    const items = Array.isArray(block.items) ? block.items : [];
                    return `<ul class="space-y-3 pl-1 text-slate-700">${items.map((item) => `<li class="flex gap-3"><span class="mt-[13px] h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span><span>${item}</span></li>`).join('')}</ul>`;
                }

                return `<p>${block.content || ''}</p>`;
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
                contentNode.innerHTML = blocks.map((block) => blockHtml(block)).join('');
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

            function openModal() {
                render('zh-CN');
                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
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
                await persistDismissIfNeeded();
            }

            switchButton.addEventListener('click', function () {
                render(locale === 'zh-CN' ? 'en' : 'zh-CN');
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });

            document.querySelectorAll('[data-open-admin-welcome]').forEach((trigger) => {
                trigger.addEventListener('click', function (event) {
                    event.preventDefault();
                    openModal();
                });
            });

            if (state.shouldAutoOpen) {
                openModal();
            }
        })();
    </script>
    @endverbatim
@endif
