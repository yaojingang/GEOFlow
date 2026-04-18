<?php
/**
 * 智能GEO内容系统 - 后台公共底部
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-06
 */

// 确保已经包含必要的文件
if (!defined('FEISHU_TREASURE')) {
    die('Direct access not allowed');
}

?>

    </div> <!-- 结束主要内容区域 -->

    <!-- 底部信息 -->
    <?php
    $projectGithubUrl = 'https://github.com/yaojingang/GEOFlow';
    $xProfileUrl = 'https://x.com/yaojingang';
    $changelogUrl = app_locale() === 'en'
        ? 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG_en.md'
        : 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG.md';
    ?>
    <footer class="bg-white border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row md:flex-wrap justify-center items-center gap-3 md:gap-4 text-sm text-gray-500 text-center">
                <span><?php echo htmlspecialchars(__('footer.copyright')); ?></span>
                <span>|</span>
                <span><?php echo htmlspecialchars(__('footer.version', ['version' => '1.2'])); ?></span>
                <span>|</span>
                <span class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1">
                    <?php echo htmlspecialchars(__('footer.author')); ?>
                    <a href="<?php echo htmlspecialchars($xProfileUrl); ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-700 underline-offset-2 hover:underline">
                        <?php echo htmlspecialchars(__('footer.author_x_profile')); ?>
                    </a>
                    <a href="<?php echo htmlspecialchars($projectGithubUrl); ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-700 underline-offset-2 hover:underline">
                        <?php echo htmlspecialchars(__('footer.project_github_link')); ?>
                    </a>
                    <a href="<?php echo htmlspecialchars($changelogUrl); ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-700 underline-offset-2 hover:underline">
                        <?php echo htmlspecialchars(__('footer.changelog_link')); ?>
                    </a>
                </span>
            </div>
        </div>
    </footer>

    <!-- 全局JavaScript -->
    <script>
        window.ADMIN_BASE_PATH = <?php echo json_encode(rtrim(ADMIN_BASE_PATH, '/'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        window.adminUrl = function(path = '') {
            const base = window.ADMIN_BASE_PATH || '';
            if (!path) {
                return `${base}/`;
            }
            return `${base}/${String(path).replace(/^\/+/, '')}`;
        };

        // 确保Lucide图标正确加载
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

        // 全局工具函数
        window.AdminUtils = {
            // 显示确认对话框
            confirm: function(message, callback) {
                if (confirm(message)) {
                    if (typeof callback === 'function') {
                        callback();
                    }
                    return true;
                }
                return false;
            },

            // 显示加载状态
            showLoading: function(element) {
                if (element) {
                    element.disabled = true;
                    const originalText = element.textContent;
                    element.setAttribute('data-original-text', originalText);
                    element.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 mr-2 animate-spin inline"></i><?php echo addslashes(__('message.processing')); ?>';
                    lucide.createIcons();
                }
            },

            // 隐藏加载状态
            hideLoading: function(element) {
                if (element) {
                    element.disabled = false;
                    const originalText = element.getAttribute('data-original-text');
                    if (originalText) {
                        element.textContent = originalText;
                        element.removeAttribute('data-original-text');
                    }
                }
            },

            // 复制到剪贴板
            copyToClipboard: function(text) {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function() {
                        AdminUtils.showToast('<?php echo addslashes(__('message.copied')); ?>', 'success');
                    });
                } else {
                    // 降级方案
                    const textArea = document.createElement('textarea');
                    textArea.value = text;
                    document.body.appendChild(textArea);
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    AdminUtils.showToast('<?php echo addslashes(__('message.copied')); ?>', 'success');
                }
            },

            // 显示Toast消息
            showToast: function(message, type = 'info') {
                const toast = document.createElement('div');
                const bgColor = type === 'success' ? 'bg-green-500' : 
                               type === 'error' ? 'bg-red-500' : 
                               type === 'warning' ? 'bg-yellow-500' : 'bg-blue-500';
                
                toast.className = `fixed top-4 right-4 ${bgColor} text-white px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300 translate-x-full`;
                toast.innerHTML = `
                    <div class="flex items-center space-x-2">
                        <span>${message}</span>
                        <button onclick="this.parentElement.parentElement.remove()" class="ml-2">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                `;
                
                document.body.appendChild(toast);
                lucide.createIcons();
                
                // 显示动画
                setTimeout(() => {
                    toast.classList.remove('translate-x-full');
                }, 100);
                
                // 自动隐藏
                setTimeout(() => {
                    toast.classList.add('translate-x-full');
                    setTimeout(() => {
                        if (toast.parentElement) {
                            toast.remove();
                        }
                    }, 300);
                }, 3000);
            },

            // 格式化文件大小
            formatFileSize: function(bytes) {
                if (bytes >= 1073741824) {
                    return (bytes / 1073741824).toFixed(2) + ' GB';
                } else if (bytes >= 1048576) {
                    return (bytes / 1048576).toFixed(2) + ' MB';
                } else if (bytes >= 1024) {
                    return (bytes / 1024).toFixed(2) + ' KB';
                } else {
                    return bytes + ' B';
                }
            },

            // 格式化数字
            formatNumber: function(num) {
                return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            }
        };

        // 键盘快捷键
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + S 保存
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const saveButton = document.querySelector('button[type="submit"], .save-button');
                if (saveButton && !saveButton.disabled) {
                    saveButton.click();
                }
            }
            
            // ESC 关闭模态框
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.fixed.inset-0:not(.hidden)');
                modals.forEach(modal => {
                    if (modal.classList.contains('bg-gray-600')) { // 模态框背景
                        modal.classList.add('hidden');
                    }
                });
            }
        });

        // 页面加载完成后的初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化所有工具提示
            const tooltips = document.querySelectorAll('[data-tooltip]');
            tooltips.forEach(function(element) {
                element.addEventListener('mouseenter', function() {
                    const tooltip = document.createElement('div');
                    tooltip.className = 'absolute z-50 px-2 py-1 text-xs text-white bg-gray-900 rounded shadow-lg';
                    tooltip.textContent = this.getAttribute('data-tooltip');
                    tooltip.style.top = this.offsetTop - 30 + 'px';
                    tooltip.style.left = this.offsetLeft + 'px';
                    this.parentElement.appendChild(tooltip);
                    this.setAttribute('data-tooltip-element', 'true');
                });
                
                element.addEventListener('mouseleave', function() {
                    if (this.getAttribute('data-tooltip-element')) {
                        const tooltip = this.parentElement.querySelector('.absolute.z-50');
                        if (tooltip) {
                            tooltip.remove();
                        }
                        this.removeAttribute('data-tooltip-element');
                    }
                });
            });
        });
    </script>

    <?php if (isset($additional_js)): ?>
        <?php echo $additional_js; ?>
    <?php endif; ?>

</body>
</html>
