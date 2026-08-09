(function () {
    'use strict';

    let toastTimer;
    let sidebarTrigger;
    let modalTrigger;
    let popoverTrigger;

    function closeSidebar(restoreFocus = false) {
        document.body.classList.remove('gf-sidebar-open');
        document.querySelector('[data-sidebar-open]')?.setAttribute('aria-expanded', 'false');
        const overlay = document.querySelector('[data-sidebar-overlay]');
        overlay?.setAttribute('aria-hidden', 'true');
        overlay?.setAttribute('tabindex', '-1');
        if (restoreFocus) sidebarTrigger?.focus();
    }

    function closePopovers(except = '') {
        document.querySelectorAll('[data-popover]').forEach((popover) => {
            if (popover.dataset.popover !== except) {
                popover.classList.remove('is-open');
                popover.setAttribute('aria-hidden', 'true');
            }
        });
        document.querySelectorAll('[data-popover-button]').forEach((button) => {
            if (button.dataset.popoverButton !== except) button.setAttribute('aria-expanded', 'false');
        });
    }

    function closeModal(restoreFocus = true) {
        const modal = document.querySelector('[data-modal]');
        if (!modal?.classList.contains('is-open')) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gf-modal-open');
        if (restoreFocus) modalTrigger?.focus();
    }

    function showToast(message) {
        const toast = document.querySelector('[data-toast]');
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 2200);
    }

    function showModal(message) {
        const modal = document.querySelector('[data-modal]');
        const content = document.querySelector('[data-modal-message]');
        if (!modal || !content) {
            showToast(message);
            return;
        }
        content.textContent = message;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gf-modal-open');
        modal.querySelector('[data-modal-close]')?.focus();
    }

    function trapModalFocus(event) {
        const modal = document.querySelector('[data-modal].is-open');
        if (!modal || event.key !== 'Tab') return;
        const focusable = [...modal.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
            .filter((element) => element.getClientRects().length > 0);
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function bindShell() {
        document.querySelector('[data-sidebar-collapse]')?.addEventListener('click', (event) => {
            const collapsed = document.body.classList.toggle('gf-sidebar-collapsed');
            event.currentTarget.setAttribute('aria-expanded', String(!collapsed));
            event.currentTarget.setAttribute('aria-label', collapsed ? '展开主菜单' : '收起主菜单');
        });
        document.querySelector('[data-sidebar-open]')?.addEventListener('click', (event) => {
            sidebarTrigger = event.currentTarget;
            document.body.classList.add('gf-sidebar-open');
            event.currentTarget.setAttribute('aria-expanded', 'true');
            const overlay = document.querySelector('[data-sidebar-overlay]');
            overlay?.setAttribute('aria-hidden', 'false');
            overlay?.setAttribute('tabindex', '0');
            document.querySelector('[data-sidebar-close]')?.focus();
        });
        document.querySelector('[data-sidebar-close]')?.addEventListener('click', () => closeSidebar(true));
        document.querySelector('[data-sidebar-overlay]')?.addEventListener('click', () => closeSidebar(true));

        document.querySelectorAll('[data-popover-button]').forEach((button) => {
            button.addEventListener('click', () => {
                const name = button.dataset.popoverButton;
                const popover = document.querySelector(`[data-popover="${name}"]`);
                const willOpen = !popover.classList.contains('is-open');
                closePopovers(name);
                popover.classList.toggle('is-open', willOpen);
                popover.setAttribute('aria-hidden', String(!willOpen));
                button.setAttribute('aria-expanded', String(willOpen));
                popoverTrigger = willOpen ? button : undefined;
            });
        });

        document.querySelectorAll('[data-demo-action]').forEach((element) => {
            element.addEventListener('click', (event) => {
                if (element.tagName === 'A') event.preventDefault();
                modalTrigger = element;
                closePopovers();
                showModal(element.dataset.demoAction || '当前操作只用于原型演示');
            });
        });

        document.querySelectorAll('[data-modal-close]').forEach((button) => button.addEventListener('click', () => closeModal(true)));
        document.querySelector('[data-modal]')?.addEventListener('click', (event) => {
            if (event.target === event.currentTarget) closeModal(true);
        });

        document.querySelectorAll('[data-demo-form]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                showToast(form.dataset.successMessage || '演示表单已通过本地校验');
            });
        });
    }

    function bindAi() {
        const landing = document.querySelector('[data-ai-landing]');
        const conversation = document.querySelector('[data-ai-conversation]');
        const landingPrompt = document.querySelector('[data-ai-input="landing"]');
        const followupPrompt = document.querySelector('[data-ai-input="followup"]');
        const reasoning = document.querySelector('[data-ai-reasoning]');
        const reasonStatus = document.querySelector('[data-ai-reason-status]');
        const reasonStatusText = document.querySelector('[data-ai-reason-status-text]');
        const reasonSummary = document.querySelector('[data-ai-reason-summary]');
        const userMessage = document.querySelector('[data-ai-user-message]');
        const result = document.querySelector('[data-ai-result]');
        const chatStatus = document.querySelector('[data-ai-chat-status]');
        const chatStatusText = chatStatus?.querySelector('span');
        const reasonSteps = [...document.querySelectorAll('[data-ai-reason-step]')];
        const runbar = document.querySelector('[data-ai-runbar]');
        const runbarLabel = document.querySelector('[data-ai-runbar-label]');
        const runbarTime = document.querySelector('[data-ai-runbar-time]');
        const runbarCount = document.querySelector('[data-ai-runbar-count]');
        const stopButton = document.querySelector('[data-ai-stop]');
        const followupSend = document.querySelector('[data-ai-send="followup"]');
        const autoConfirm = document.querySelector('[data-ai-auto-confirm]');
        const approvalNote = document.querySelector('[data-ai-approval-note]');
        const confirmation = document.querySelector('[data-ai-confirmation]');
        const confirmButton = document.querySelector('[data-ai-confirm]');
        const confirmButtonText = confirmButton?.querySelector('span');
        const resultBadge = result?.querySelector('.gf-ai-result-title .gf-badge');
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        let aiRunTimers = [];
        let aiRunClock = null;
        let aiStartedAt = 0;

        const clearAiRun = () => {
            aiRunTimers.forEach((timer) => window.clearTimeout(timer));
            aiRunTimers = [];
            if (aiRunClock) window.clearInterval(aiRunClock);
            aiRunClock = null;
        };

        const scheduleAiStep = (callback, delay) => {
            const timer = window.setTimeout(callback, delay);
            aiRunTimers.push(timer);
        };

        const setRunControls = (running) => {
            if (stopButton) stopButton.hidden = !running;
            if (followupSend) followupSend.hidden = running;
            if (followupPrompt) followupPrompt.disabled = running;
        };

        const formatElapsed = (seconds) => `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;

        const updateRunbar = ({ label, index = 0, state = 'running' }) => {
            runbar?.classList.toggle('is-complete', state === 'complete');
            runbar?.classList.toggle('is-paused', state === 'paused');
            runbar?.classList.toggle('is-confirmed', state === 'confirmed');
            if (runbarLabel) runbarLabel.textContent = label;
            if (runbarCount) runbarCount.textContent = `${index}/${reasonSteps.length}`;
        };

        const startAiClock = () => {
            aiStartedAt = Date.now();
            const renderElapsed = () => {
                const seconds = Math.max(0, Math.floor((Date.now() - aiStartedAt) / 1000));
                if (runbarTime) {
                    runbarTime.textContent = formatElapsed(seconds);
                    runbarTime.dateTime = `PT${seconds}S`;
                }
            };
            renderElapsed();
            aiRunClock = window.setInterval(renderElapsed, 1000);
        };

        const setReasonStepState = (step, state) => {
            const waiting = state === 'waiting';
            const wasHidden = step.hidden;
            step.hidden = waiting;
            if (waiting) {
                step.classList.remove('is-visible');
            } else if (wasHidden && !reduceMotion.matches) {
                window.requestAnimationFrame(() => {
                    if (!step.hidden) step.classList.add('is-visible');
                });
            } else {
                step.classList.add('is-visible');
            }
            step.classList.toggle('is-waiting', state === 'waiting');
            step.classList.toggle('is-active', state === 'active');
            step.classList.toggle('is-complete', state === 'complete');
            const accessibleState = step.querySelector('.gf-sr-only');
            if (accessibleState) accessibleState.textContent = state === 'complete' ? '处理完成' : state === 'active' ? '正在处理' : '等待处理';
        };

        const revealAiResult = (scrollToResult) => {
            if (!result) return;
            result.hidden = false;
            window.requestAnimationFrame(() => result.classList.add('is-visible'));
            chatStatus?.classList.add('is-complete');
            if (chatStatusText) chatStatusText.textContent = '计划已生成';
            updateRunbar({ label: '工作过程已完成，等待确认任务安排', index: reasonSteps.length, state: 'complete' });
            setRunControls(false);
            if (scrollToResult) {
                scheduleAiStep(() => result.scrollIntoView({ behavior: reduceMotion.matches ? 'auto' : 'smooth', block: 'start' }), 180);
            }
        };

        const runAiConversation = ({ taskText = '', scrollResult = false, announce = false } = {}) => {
            if (!conversation || !reasoning || !reasonSteps.length) return;
            clearAiRun();
            if (taskText && userMessage) userMessage.textContent = taskText;
            reasonSteps.forEach((step) => setReasonStepState(step, 'waiting'));
            reasonSummary?.classList.remove('is-visible');
            reasonStatus?.classList.remove('is-complete');
            reasonStatus?.classList.remove('is-paused');
            if (reasonStatusText) reasonStatusText.textContent = '正在理解任务';
            chatStatus?.classList.remove('is-complete');
            chatStatus?.classList.remove('is-paused');
            if (chatStatusText) chatStatusText.textContent = '正在工作';
            reasoning.setAttribute('aria-busy', 'true');
            result?.classList.remove('is-visible');
            if (result) result.hidden = true;
            confirmation?.classList.remove('is-visible');
            if (confirmation) confirmation.hidden = true;
            if (confirmButton) confirmButton.disabled = false;
            if (confirmButtonText) confirmButtonText.textContent = '一键确认 4 个任务';
            if (resultBadge) resultBadge.textContent = '等待确认';
            if (runbarTime) {
                runbarTime.textContent = '00:00';
                runbarTime.dateTime = 'PT0S';
            }
            updateRunbar({ label: '正在准备任务上下文', index: 0 });
            setRunControls(true);
            startAiClock();
            if (announce) showToast('GEOFlow AI 正在理解并拆解任务');

            if (reduceMotion.matches) {
                reasonSteps.forEach((step) => setReasonStepState(step, 'complete'));
                reasonSummary?.classList.add('is-visible');
                reasonStatus?.classList.add('is-complete');
                if (reasonStatusText) reasonStatusText.textContent = '任务理解完成';
                reasoning.setAttribute('aria-busy', 'false');
                if (aiRunClock) window.clearInterval(aiRunClock);
                aiRunClock = null;
                revealAiResult(scrollResult);
                return;
            }

            reasonSteps.forEach((step, index) => {
                scheduleAiStep(() => {
                    if (index > 0) setReasonStepState(reasonSteps[index - 1], 'complete');
                    setReasonStepState(step, 'active');
                    if (reasonStatusText) reasonStatusText.textContent = `正在执行 ${index + 1}/${reasonSteps.length}`;
                    updateRunbar({ label: step.dataset.aiStepLabel || '正在处理任务', index: index + 1 });
                }, 280 + index * 820);
            });

            scheduleAiStep(() => {
                setReasonStepState(reasonSteps.at(-1), 'complete');
                reasonSummary?.classList.add('is-visible');
                reasonStatus?.classList.add('is-complete');
                if (reasonStatusText) reasonStatusText.textContent = '任务理解完成';
                reasoning.setAttribute('aria-busy', 'false');
                if (aiRunClock) window.clearInterval(aiRunClock);
                aiRunClock = null;
                revealAiResult(scrollResult);
                if (announce) showToast('执行计划已生成，等待你的确认');
            }, 280 + reasonSteps.length * 820);
        };

        const enterAiConversation = (taskText, announce = false) => {
            if (!landing || !conversation || !taskText) return;
            landing.hidden = true;
            conversation.hidden = false;
            document.body.classList.add('gf-ai-conversation-mode');
            window.requestAnimationFrame(() => conversation.classList.add('is-visible'));
            window.scrollTo({ top: 0, behavior: 'auto' });
            if (followupPrompt) followupPrompt.value = '';
            runAiConversation({ taskText, scrollResult: true, announce });
        };

        const exitAiConversation = () => {
            clearAiRun();
            document.body.classList.remove('gf-ai-conversation-mode');
            conversation?.classList.remove('is-visible');
            if (conversation) conversation.hidden = true;
            if (landing) landing.hidden = false;
            reasoning?.setAttribute('aria-busy', 'false');
            result?.classList.remove('is-visible');
            if (result) result.hidden = true;
            setRunControls(false);
            window.scrollTo({ top: 0, behavior: 'auto' });
            window.requestAnimationFrame(() => landingPrompt?.focus());
        };

        document.querySelectorAll('[data-ai-mode]').forEach((mode) => {
            mode.addEventListener('click', () => {
                document.querySelectorAll('[data-ai-mode]').forEach((item) => {
                    item.classList.toggle('is-active', item === mode);
                    item.setAttribute('aria-pressed', String(item === mode));
                });
            });
        });
        document.querySelectorAll('[data-ai-chip]').forEach((chip) => {
            chip.addEventListener('click', () => {
                document.querySelectorAll('[data-ai-chip]').forEach((item) => item.classList.remove('is-active'));
                chip.classList.add('is-active');
                if (landingPrompt) landingPrompt.value = `${chip.dataset.aiChip}：分析当前 GEOFlow 数据，给出优先级建议并生成可审核的执行计划`;
                landingPrompt?.focus();
            });
        });
        document.querySelector('[data-ai-send="landing"]')?.addEventListener('click', () => {
            const taskText = landingPrompt?.value.trim();
            if (!taskText) {
                showToast('请先输入需要 GEOFlow 完成的任务');
                landingPrompt?.focus();
                return;
            }
            enterAiConversation(taskText);
        });
        document.querySelector('[data-ai-send="followup"]')?.addEventListener('click', () => {
            const taskText = followupPrompt?.value.trim();
            if (!taskText) {
                showToast('请输入补充要求');
                followupPrompt?.focus();
                return;
            }
            runAiConversation({ taskText, scrollResult: true, announce: false });
            if (followupPrompt) followupPrompt.value = '';
        });
        stopButton?.addEventListener('click', () => {
            clearAiRun();
            reasoning?.setAttribute('aria-busy', 'false');
            reasonStatus?.classList.add('is-paused');
            if (reasonStatusText) reasonStatusText.textContent = '任务已暂停';
            chatStatus?.classList.add('is-paused');
            if (chatStatusText) chatStatusText.textContent = '已暂停';
            const activeIndex = reasonSteps.findIndex((step) => step.classList.contains('is-active'));
            updateRunbar({ label: 'Agent 已暂停，可补充要求后重新运行', index: Math.max(0, activeIndex + 1), state: 'paused' });
            setRunControls(false);
            followupPrompt?.focus();
        });
        autoConfirm?.addEventListener('change', () => {
            if (approvalNote) approvalNote.textContent = autoConfirm.checked
                ? '系统会自动通过低风险步骤，高风险动作仍会暂停。'
                : '低风险步骤仍等待你的最终确认。';
        });
        confirmButton?.addEventListener('click', () => {
            confirmButton.disabled = true;
            if (confirmButtonText) confirmButtonText.textContent = '已确认 4 个任务';
            if (resultBadge) resultBadge.textContent = '已确认';
            if (confirmation) {
                confirmation.hidden = false;
                window.requestAnimationFrame(() => confirmation.classList.add('is-visible'));
            }
            if (chatStatusText) chatStatusText.textContent = '已确认';
            updateRunbar({ label: '任务已确认，等待进入执行队列', index: reasonSteps.length, state: 'confirmed' });
        });
        document.querySelector('[data-ai-replay]')?.addEventListener('click', () => runAiConversation({ taskText: userMessage?.textContent.trim(), scrollResult: true, announce: false }));
        document.querySelector('[data-ai-new-chat]')?.addEventListener('click', exitAiConversation);
        [landingPrompt, followupPrompt].forEach((input) => input?.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || event.shiftKey) return;
            event.preventDefault();
            const target = input.dataset.aiInput;
            document.querySelector(`[data-ai-send="${target}"]`)?.click();
        }));
    }

    function bindTabs() {
        document.querySelectorAll('[data-tabs]').forEach((tablist) => {
            const tabs = [...tablist.querySelectorAll('[role="tab"]')];
            const activate = (tab, focus = false) => {
                tabs.forEach((item) => {
                    const selected = item === tab;
                    item.classList.toggle('is-active', selected);
                    item.setAttribute('aria-selected', String(selected));
                    item.tabIndex = selected ? 0 : -1;
                    const panel = document.getElementById(item.dataset.tabTarget);
                    if (panel) panel.hidden = !selected;
                });
                if (focus) tab.focus();
            };
            tabs.forEach((tab, index) => {
                tab.addEventListener('click', () => activate(tab));
                tab.addEventListener('keydown', (event) => {
                    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    const nextIndex = event.key === 'Home'
                        ? 0
                        : event.key === 'End'
                            ? tabs.length - 1
                            : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                    activate(tabs[nextIndex], true);
                });
            });
        });

        document.querySelectorAll('[data-visual-tab]').forEach((tab) => {
            tab.addEventListener('click', () => {
                const group = tab.closest('.gf-tabs');
                group?.querySelectorAll('[data-visual-tab]').forEach((item) => {
                    item.classList.toggle('is-active', item === tab);
                    item.setAttribute('aria-pressed', String(item === tab));
                });
                showToast(`已切换到“${tab.textContent.trim()}”演示配置`);
            });
        });
    }

    function bindPagination() {
        document.querySelectorAll('.gf-pagination').forEach((pagination) => {
            const pages = [...pagination.querySelectorAll('[data-page-number]')];
            const selectPage = (page) => {
                pages.forEach((item) => {
                    const current = item === page;
                    item.classList.toggle('is-current', current);
                    if (current) item.setAttribute('aria-current', 'page');
                    else item.removeAttribute('aria-current');
                });
                showToast(`已切换到第 ${page.dataset.pageNumber} 页演示数据`);
            };
            pages.forEach((page) => page.addEventListener('click', () => selectPage(page)));
            pagination.querySelector('[data-page-step="previous"]')?.addEventListener('click', () => {
                const current = pages.findIndex((page) => page.classList.contains('is-current'));
                selectPage(pages[Math.max(0, current - 1)]);
            });
            pagination.querySelector('[data-page-step="next"]')?.addEventListener('click', () => {
                const current = pages.findIndex((page) => page.classList.contains('is-current'));
                selectPage(pages[Math.min(pages.length - 1, current + 1)]);
            });
        });
    }

    function bindGlobal() {
        document.addEventListener('click', (event) => {
            const inside = event.target.closest?.('[data-popover-wrap], .gf-popover-wrap');
            if (!inside) closePopovers();
        });
        document.addEventListener('keydown', (event) => {
            trapModalFocus(event);
            if (event.key === 'Escape') {
                const modalOpen = Boolean(document.querySelector('[data-modal].is-open'));
                const popoverOpen = Boolean(document.querySelector('[data-popover].is-open'));
                const sidebarOpen = document.body.classList.contains('gf-sidebar-open');
                closeModal(modalOpen);
                closePopovers();
                if (!modalOpen && popoverOpen) popoverTrigger?.focus();
                closeSidebar(!modalOpen && !popoverOpen && sidebarOpen);
            }
        });
    }

    function init() {
        bindShell();
        bindAi();
        bindTabs();
        bindPagination();
        bindGlobal();
    }

    window.GeoFlowInteractions = { init, showModal, showToast };
}());
