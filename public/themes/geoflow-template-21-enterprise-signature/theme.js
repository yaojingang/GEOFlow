(() => {
    const ready = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }

        callback();
    };

    ready(() => {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

        const header = document.querySelector('[data-ent-header]');
        const menuTrigger = document.querySelector('[data-ent-menu-trigger]');
        const mobileNav = document.querySelector('[data-ent-mobile-nav]');
        const searchOpen = document.querySelector('[data-ent-search-open]');
        const searchClose = document.querySelector('[data-ent-search-close]');
        const searchPanel = document.querySelector('[data-ent-search-panel]');
        const searchInput = document.querySelector('[data-ent-search-input]');

        const closeMenu = (restoreFocus = false) => {
            if (!menuTrigger || !mobileNav) {
                return;
            }

            const wasOpen = !mobileNav.hidden;
            mobileNav.hidden = true;
            menuTrigger.setAttribute('aria-expanded', 'false');
            menuTrigger.setAttribute('aria-label', '打开导航');
            if (restoreFocus && wasOpen) {
                menuTrigger.focus();
            }
        };

        const closeSearch = (restoreFocus = false) => {
            const wasOpen = searchPanel && !searchPanel.hidden;
            if (searchPanel) {
                searchPanel.hidden = true;
            }
            searchOpen?.setAttribute('aria-expanded', 'false');
            if (restoreFocus && wasOpen) {
                searchOpen?.focus();
            }
        };

        menuTrigger?.addEventListener('click', () => {
            if (!mobileNav) {
                return;
            }

            const willOpen = mobileNav.hidden;
            mobileNav.hidden = !willOpen;
            menuTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            menuTrigger.setAttribute('aria-label', willOpen ? '关闭导航' : '打开导航');
            closeSearch();
        });

        mobileNav?.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', closeMenu);
        });

        searchOpen?.addEventListener('click', () => {
            if (!searchPanel) {
                return;
            }

            searchPanel.hidden = false;
            searchOpen.setAttribute('aria-expanded', 'true');
            closeMenu();
            window.setTimeout(() => searchInput?.focus(), 0);
        });

        searchClose?.addEventListener('click', () => closeSearch(true));

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                if (searchPanel && !searchPanel.hidden) {
                    event.preventDefault();
                    closeSearch(true);
                } else if (mobileNav && !mobileNav.hidden) {
                    event.preventDefault();
                    closeMenu(true);
                }
            }
        });

        let scrollFrame = 0;
        const updateHeader = () => {
            header?.classList.toggle('is-scrolled', window.scrollY > 18);
            scrollFrame = 0;
        };

        window.addEventListener('scroll', () => {
            if (scrollFrame === 0) {
                scrollFrame = window.requestAnimationFrame(updateHeader);
            }
        }, { passive: true });
        updateHeader();

        document.querySelectorAll('[data-ent-tabs]').forEach((tabs) => {
            const buttons = Array.from(tabs.querySelectorAll('[data-ent-tab]'));
            const panels = Array.from(tabs.querySelectorAll('[data-ent-panel]'));

            const selectTab = (selectedButton) => {
                const selectedKey = selectedButton.dataset.entTab;

                buttons.forEach((button) => {
                    const selected = button === selectedButton;
                    button.setAttribute('aria-selected', selected ? 'true' : 'false');
                    button.tabIndex = selected ? 0 : -1;
                });

                panels.forEach((panel) => {
                    const selected = panel.dataset.entPanel === selectedKey;
                    panel.hidden = !selected;
                    panel.classList.toggle('is-active', selected);
                });
            };

            buttons.forEach((button, index) => {
                button.addEventListener('click', () => selectTab(button));
                button.addEventListener('keydown', (event) => {
                    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                        return;
                    }

                    event.preventDefault();
                    let nextIndex = index;

                    if (event.key === 'ArrowLeft') {
                        nextIndex = (index - 1 + buttons.length) % buttons.length;
                    } else if (event.key === 'ArrowRight') {
                        nextIndex = (index + 1) % buttons.length;
                    } else if (event.key === 'Home') {
                        nextIndex = 0;
                    } else if (event.key === 'End') {
                        nextIndex = buttons.length - 1;
                    }

                    buttons[nextIndex].focus();
                    selectTab(buttons[nextIndex]);
                });
            });
        });

        document.querySelectorAll('[data-ent-copy-url]').forEach((button) => {
            button.addEventListener('click', async () => {
                const url = button.dataset.url || window.location.href;
                const status = button.parentElement?.querySelector('[data-ent-copy-status]');
                const setStatus = (message) => {
                    button.setAttribute('aria-label', message);
                    if (status) {
                        status.textContent = message;
                    }
                };
                const markCopied = () => {
                    button.classList.add('is-copied');
                    setStatus('链接已复制');
                    window.setTimeout(() => {
                        button.classList.remove('is-copied');
                        button.setAttribute('aria-label', '复制文章链接');
                    }, 1800);
                };

                try {
                    await navigator.clipboard.writeText(url);
                    markCopied();
                } catch {
                    const buffer = document.createElement('textarea');
                    let copied = false;

                    buffer.value = url;
                    buffer.setAttribute('readonly', '');
                    buffer.className = 'ent-copy-buffer';
                    document.body.appendChild(buffer);
                    buffer.select();

                    try {
                        copied = document.execCommand('copy');
                    } catch {
                        copied = false;
                    }

                    buffer.remove();
                    if (copied) {
                        markCopied();
                    } else {
                        setStatus('复制失败，请手动复制浏览器地址');
                    }
                }
            });
        });

        const articleContent = document.querySelector('[data-ent-article-content]');
        const articleToc = document.querySelector('[data-ent-article-toc]');
        const articleTocList = articleToc?.querySelector('[data-ent-toc-list]');

        if (articleContent && articleToc && articleTocList) {
            const headings = Array.from(articleContent.querySelectorAll('h2, h3'))
                .filter((heading) => (
                    heading.textContent.trim() !== ''
                    && !heading.closest('.ent-article-cta, .article-text-ads')
                ));
            const usedIds = new Set();

            headings.forEach((heading, index) => {
                let headingId = heading.id.trim();
                const idOwner = headingId !== '' ? document.getElementById(headingId) : null;
                if (headingId === '' || usedIds.has(headingId) || (idOwner && idOwner !== heading)) {
                    headingId = `article-section-${index + 1}`;
                }
                while (
                    usedIds.has(headingId)
                    || (
                        document.getElementById(headingId)
                        && document.getElementById(headingId) !== heading
                    )
                ) {
                    headingId = `${headingId}-${index + 1}`;
                }

                heading.id = headingId;
                usedIds.add(headingId);

                const link = document.createElement('a');
                link.href = `#${headingId}`;
                link.textContent = heading.textContent.trim();
                link.dataset.entTocTarget = headingId;
                link.classList.toggle('is-subsection', heading.tagName === 'H3');
                articleTocList.appendChild(link);
            });

            if (headings.length > 0) {
                articleToc.hidden = false;
                const tocLinks = Array.from(articleTocList.querySelectorAll('a'));
                let tocScrollFrame = 0;
                let activeTocTarget = '';

                const keepTocLinkVisible = (link) => {
                    if (articleToc.offsetParent === null) {
                        return;
                    }

                    const tocRect = articleToc.getBoundingClientRect();
                    const linkRect = link.getBoundingClientRect();
                    const edgeInset = 16;
                    const titleHeight = articleToc.querySelector('h2')?.getBoundingClientRect().height ?? 0;

                    if (linkRect.top < tocRect.top + titleHeight + edgeInset) {
                        articleToc.scrollTop += linkRect.top - tocRect.top - titleHeight - edgeInset;
                    } else if (linkRect.bottom > tocRect.bottom - edgeInset) {
                        articleToc.scrollTop += linkRect.bottom - tocRect.bottom + edgeInset;
                    }
                };

                const syncActiveToc = () => {
                    let activeHeading = headings[0];
                    headings.forEach((heading) => {
                        if (heading.getBoundingClientRect().top <= 150) {
                            activeHeading = heading;
                        }
                    });

                    let activeLink = tocLinks[0];
                    tocLinks.forEach((link) => {
                        const isActive = link.dataset.entTocTarget === activeHeading.id;
                        link.classList.toggle('is-active', isActive);
                        if (isActive) {
                            activeLink = link;
                            link.setAttribute('aria-current', 'location');
                        } else {
                            link.removeAttribute('aria-current');
                        }
                    });
                    if (activeHeading.id !== activeTocTarget) {
                        activeTocTarget = activeHeading.id;
                        keepTocLinkVisible(activeLink);
                    }
                    tocScrollFrame = 0;
                };

                window.addEventListener('scroll', () => {
                    if (tocScrollFrame === 0) {
                        tocScrollFrame = window.requestAnimationFrame(syncActiveToc);
                    }
                }, { passive: true });

                tocLinks.forEach((link) => link.addEventListener('click', () => {
                    tocLinks.forEach((item) => {
                        item.classList.toggle('is-active', item === link);
                        if (item === link) {
                            item.setAttribute('aria-current', 'location');
                        } else {
                            item.removeAttribute('aria-current');
                        }
                    });
                }));

                syncActiveToc();
            }
        }

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const revealItems = document.querySelectorAll('.ent-reveal');

        if (reducedMotion || !('IntersectionObserver' in window)) {
            revealItems.forEach((item) => item.classList.add('is-visible'));
            return;
        }

        document.documentElement.classList.add('ent-motion-ready');
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, {
            rootMargin: '0px 0px -8% 0px',
            threshold: 0.08,
        });

        revealItems.forEach((item) => observer.observe(item));
    });
})();
