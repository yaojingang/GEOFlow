@if (!empty($adminWelcomeModalPayload))
    <div id="admin-welcome-modal" class="hidden fixed inset-0 z-[70]">
        <div class="absolute inset-0 bg-slate-950/40 backdrop-blur-sm"></div>
        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6 lg:p-8">
            <div data-kami-document class="w-full max-w-4xl overflow-hidden rounded-2xl border border-[#e8e5da] bg-white shadow-[0_24px_80px_rgba(20,20,19,0.14)] ring-1 ring-[#e8e5da]">
                <div class="border-b border-[#e8e5da] bg-white px-6 py-4 sm:px-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div id="admin-welcome-badge" class="inline-flex rounded-full bg-[#EEF2F7] px-3 py-1 text-[12px] font-semibold text-[#1B365D]"></div>
                        </div>
                        <div class="flex items-center gap-2 self-start sm:self-auto">
                            <button type="button" data-welcome-switch class="rounded-full border border-[#d1cfc5] bg-white px-3.5 py-2 text-[13px] font-medium text-[#3d3d3a] hover:border-[#1B365D] hover:text-[#1B365D]"></button>
                            <button type="button" data-welcome-close class="rounded-full border border-[#d1cfc5] bg-white px-3.5 py-2 text-[13px] font-medium text-[#3d3d3a] hover:bg-[#f7f6f1]"></button>
                        </div>
                    </div>
                </div>

                <div class="max-h-[80vh] overflow-y-auto bg-white px-6 py-7 sm:px-8 sm:py-8">
                    <article class="mx-auto max-w-3xl">
                        <h2 id="admin-welcome-title" class="font-serif text-[28px] font-medium leading-tight text-[#141413]"></h2>
                        <p id="admin-welcome-subtitle" class="mt-3 border-l-[3px] border-[#1B365D] pl-4 text-[14px] leading-6 text-[#5e5d59]"></p>
                        <div id="admin-welcome-content" class="mt-7 space-y-5 text-[15px] leading-7 text-[#3d3d3a]"></div>
                    </article>

                    <div class="mx-auto mt-8 max-w-3xl border-t border-[#e8e5da] pt-5">
                        <p id="admin-welcome-links-label" class="text-[13px] leading-6 text-[#5e5d59]"></p>
                        <div class="mt-3 flex flex-wrap gap-2.5">
                            <a id="admin-welcome-link-x" class="inline-flex items-center rounded-full bg-[#EEF2F7] px-3.5 py-2 text-[13px] font-medium text-[#1B365D] ring-1 ring-[#d1cfc5] hover:bg-[#E4ECF5]" target="_blank" rel="noopener noreferrer"></a>
                            <a id="admin-welcome-link-github" class="inline-flex items-center rounded-full bg-[#EEF2F7] px-3.5 py-2 text-[13px] font-medium text-[#1B365D] ring-1 ring-[#d1cfc5] hover:bg-[#E4ECF5]" target="_blank" rel="noopener noreferrer"></a>
                            <a id="admin-welcome-link-changelog" class="inline-flex items-center rounded-full bg-[#EEF2F7] px-3.5 py-2 text-[13px] font-medium text-[#1B365D] ring-1 ring-[#d1cfc5] hover:bg-[#E4ECF5]" target="_blank" rel="noopener noreferrer"></a>
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
                    return `<h3 class="border-l-[3px] border-[#1B365D] pl-3 pt-0.5 font-serif text-[18px] font-medium leading-snug text-[#141413]">${block.content || ''}</h3>`;
                }

                if (block.type === 'list') {
                    const items = Array.isArray(block.items) ? block.items : [];
                    return `<ul class="space-y-2.5 pl-1 text-[#3d3d3a]">${items.map((item) => `<li class="flex gap-3"><span class="mt-[11px] h-1.5 w-1.5 shrink-0 rounded-full bg-[#1B365D]"></span><span>${item}</span></li>`).join('')}</ul>`;
                }

                return `<p class="text-[#3d3d3a]">${block.content || ''}</p>`;
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
