/**
 * 飞书宝藏库 - 主要JavaScript功能
 * 
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-03
 */

(function() {
    'use strict';

    // 全局配置
    const CONFIG = {
        ANIMATION_DURATION: 300,
        TOAST_DURATION: 3000,
        DEBOUNCE_DELAY: 300
    };

    // 工具函数
    const Utils = {
        // 防抖函数
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },

        // 节流函数
        throttle: function(func, limit) {
            let inThrottle;
            return function() {
                const args = arguments;
                const context = this;
                if (!inThrottle) {
                    func.apply(context, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        },

        // 格式化数字
        formatNumber: function(num) {
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M';
            } else if (num >= 1000) {
                return (num / 1000).toFixed(1) + 'K';
            }
            return num.toString();
        },

        // 获取元素
        $: function(selector) {
            return document.querySelector(selector);
        },

        // 获取所有元素
        $$: function(selector) {
            return document.querySelectorAll(selector);
        },

        // 添加事件监听器
        on: function(element, event, handler) {
            if (typeof element === 'string') {
                element = this.$(element);
            }
            if (element) {
                element.addEventListener(event, handler);
            }
        },

        // 移除事件监听器
        off: function(element, event, handler) {
            if (typeof element === 'string') {
                element = this.$(element);
            }
            if (element) {
                element.removeEventListener(event, handler);
            }
        }
    };

    // 提示消息系统
    const Toast = {
        container: null,

        init: function() {
            this.container = document.createElement('div');
            this.container.className = 'fixed top-4 right-4 z-50 space-y-2';
            document.body.appendChild(this.container);
        },

        show: function(message, type = 'info', duration = CONFIG.TOAST_DURATION) {
            const toast = document.createElement('div');
            const colors = {
                success: 'bg-green-500',
                error: 'bg-red-500',
                warning: 'bg-yellow-500',
                info: 'bg-blue-500'
            };

            toast.className = `px-6 py-3 rounded-lg text-white shadow-lg transform translate-x-full transition-transform duration-300 ${colors[type] || colors.info}`;
            toast.textContent = message;

            this.container.appendChild(toast);

            // 显示动画
            setTimeout(() => {
                toast.classList.remove('translate-x-full');
            }, 10);

            // 自动隐藏
            setTimeout(() => {
                toast.classList.add('translate-x-full');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, CONFIG.ANIMATION_DURATION);
            }, duration);

            return toast;
        }
    };

    // 搜索功能增强
    const Search = {
        init: function() {
            const searchInput = Utils.$('input[name="search"]');
            if (!searchInput) return;

            // 搜索建议功能（可扩展）
            const debouncedSearch = Utils.debounce(this.handleSearch.bind(this), CONFIG.DEBOUNCE_DELAY);
            Utils.on(searchInput, 'input', debouncedSearch);

            // 回车搜索
            Utils.on(searchInput, 'keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.performSearch(searchInput.value);
                }
            });

            // 搜索框焦点效果
            Utils.on(searchInput, 'focus', () => {
                searchInput.parentElement.classList.add('ring-2', 'ring-blue-500');
            });

            Utils.on(searchInput, 'blur', () => {
                searchInput.parentElement.classList.remove('ring-2', 'ring-blue-500');
            });
        },

        handleSearch: function(e) {
            const query = e.target.value.trim();
            if (query.length > 2) {
                // 这里可以添加搜索建议功能
                console.log('搜索建议:', query);
            }
        },

        performSearch: function(query) {
            if (query.trim()) {
                window.location.href = `/?search=${encodeURIComponent(query)}`;
            }
        }
    };

    // 卡片交互增强
    const CardInteraction = {
        init: function() {
            const cards = Utils.$$('.card-hover');
            cards.forEach(card => {
                this.enhanceCard(card);
            });
        },

        enhanceCard: function(card) {
            // 添加键盘导航支持
            card.setAttribute('tabindex', '0');
            card.setAttribute('role', 'button');

            // 键盘事件
            Utils.on(card, 'keypress', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    card.click();
                }
            });

            // 鼠标进入效果
            Utils.on(card, 'mouseenter', () => {
                card.style.transform = 'translateY(-4px)';
            });

            Utils.on(card, 'mouseleave', () => {
                card.style.transform = 'translateY(0)';
            });
        }
    };

    // 点赞功能
    const LikeSystem = {
        init: function() {
            const likeButtons = Utils.$$('.like-button');
            likeButtons.forEach(button => {
                Utils.on(button, 'click', this.handleLike.bind(this));
            });
        },

        handleLike: function(e) {
            e.preventDefault();
            const button = e.currentTarget;
            const linkId = button.dataset.linkId;

            if (!linkId || button.disabled) return;

            this.performLike(button, linkId);
        },

        performLike: function(button, linkId) {
            button.disabled = true;
            const originalContent = button.innerHTML;
            button.innerHTML = '<i class="w-4 h-4 mr-2 animate-spin">⟳</i>点赞中...';

            // 模拟API调用
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=like&link_id=${linkId}&csrf_token=${window.csrfToken || ''}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.updateLikeButton(button, true);
                    this.updateLikeCount(linkId, data.newCount);
                    Toast.show('点赞成功！', 'success');
                    
                    // 记录到本地存储
                    this.recordLike(linkId);
                } else {
                    throw new Error(data.message || '点赞失败');
                }
            })
            .catch(error => {
                console.error('点赞失败:', error);
                Toast.show(error.message || '网络错误，请稍后重试', 'error');
                button.innerHTML = originalContent;
                button.disabled = false;
            });
        },

        updateLikeButton: function(button, liked) {
            if (liked) {
                button.classList.add('liked');
                button.innerHTML = '<i class="w-4 h-4 mr-2">❤️</i>已点赞';
            } else {
                button.classList.remove('liked');
                button.innerHTML = '<i class="w-4 h-4 mr-2">🤍</i>点赞';
                button.disabled = false;
            }
        },

        updateLikeCount: function(linkId, newCount) {
            const countElement = Utils.$(`#like-count-${linkId}`);
            if (countElement) {
                countElement.textContent = Utils.formatNumber(newCount);
            }
        },

        recordLike: function(linkId) {
            const likedLinks = JSON.parse(localStorage.getItem('likedLinks') || '[]');
            if (!likedLinks.includes(linkId)) {
                likedLinks.push(linkId);
                localStorage.setItem('likedLinks', JSON.stringify(likedLinks));
            }
        },

        checkLikedStatus: function() {
            const likedLinks = JSON.parse(localStorage.getItem('likedLinks') || '[]');
            const likeButtons = Utils.$$('.like-button');
            
            likeButtons.forEach(button => {
                const linkId = button.dataset.linkId;
                if (linkId && likedLinks.includes(linkId)) {
                    this.updateLikeButton(button, true);
                    button.disabled = true;
                }
            });
        }
    };

    // 分享功能
    const ShareSystem = {
        init: function() {
            const shareButtons = Utils.$$('.share-button');
            shareButtons.forEach(button => {
                Utils.on(button, 'click', this.handleShare.bind(this));
            });
        },

        handleShare: function(e) {
            e.preventDefault();
            const button = e.currentTarget;
            const url = button.dataset.url || window.location.href;
            const title = button.dataset.title || document.title;

            this.share(url, title);
        },

        share: function(url, title) {
            if (navigator.share) {
                navigator.share({
                    title: title,
                    url: url
                }).catch(console.error);
            } else {
                this.copyToClipboard(url);
            }
        },

        copyToClipboard: function(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => {
                    Toast.show('链接已复制到剪贴板', 'success');
                }).catch(() => {
                    this.fallbackCopy(text);
                });
            } else {
                this.fallbackCopy(text);
            }
        },

        fallbackCopy: function(text) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                document.execCommand('copy');
                Toast.show('链接已复制到剪贴板', 'success');
            } catch (err) {
                Toast.show('复制失败，请手动复制链接', 'error');
            }
            
            document.body.removeChild(textArea);
        }
    };

    // 懒加载功能
    const LazyLoad = {
        init: function() {
            if ('IntersectionObserver' in window) {
                this.observer = new IntersectionObserver(this.handleIntersection.bind(this));
                const lazyElements = Utils.$$('[data-lazy]');
                lazyElements.forEach(el => this.observer.observe(el));
            }
        },

        handleIntersection: function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    this.loadElement(entry.target);
                    this.observer.unobserve(entry.target);
                }
            });
        },

        loadElement: function(element) {
            const src = element.dataset.lazy;
            if (src) {
                element.src = src;
                element.removeAttribute('data-lazy');
                element.classList.add('fade-in');
            }
        }
    };

    // 主初始化函数
    function init() {
        // 检查DOM是否已加载
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
            return;
        }

        // 初始化各个模块
        Toast.init();
        Search.init();
        CardInteraction.init();
        LikeSystem.init();
        ShareSystem.init();
        LazyLoad.init();

        // 检查已点赞状态
        LikeSystem.checkLikedStatus();

        // 初始化图标
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

        console.log('飞书宝藏库已初始化完成');
    }

    // 导出到全局
    window.FeishuTreasure = {
        Utils,
        Toast,
        Search,
        CardInteraction,
        LikeSystem,
        ShareSystem,
        LazyLoad
    };

    // 启动应用
    init();

})();
