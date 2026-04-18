<?php
/**
 * GEO+AI内容生成系统 - 管理后台入口
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-06
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';

$admin_site_name = get_setting('site_title', SITE_NAME);

// 如果已经登录，跳转到管理面板
if (is_admin_logged_in()) {
    admin_redirect('dashboard.php');
}

$error = '';
$success = '';

if (isset($_GET['password_updated']) && $_GET['password_updated'] === '1') {
    $success = __('login.success.password_updated');
}

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    // 验证CSRF令牌
    if (!verify_csrf_token($csrf_token)) {
        $error = __('login.error.invalid_request');
    } elseif (empty($username) || empty($password)) {
        $error = __('login.error.empty_credentials');
    } else {
        // 使用当前数据库进行登录验证
        $stmt = $db->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && ($admin['status'] ?? 'active') === 'active' && password_verify($password, $admin['password'])) {
            sync_admin_session($admin);

            $updateStmt = $db->prepare("
                UPDATE admins
                SET last_login = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $updateStmt->execute([(int) $admin['id']]);

            // 记录登录日志
            write_log("管理员登录: {$admin['username']}", 'INFO');
            log_admin_activity('auth:login', [
                'request_method' => 'POST',
                'page' => 'index.php',
                'details' => [
                    'username' => $admin['username'],
                    'role' => $admin['role'] ?? 'admin'
                ]
            ]);

            // 直接跳转到仪表盘
            admin_redirect('dashboard.php');
            exit;
        } else {
            $error = __('login.error.invalid_credentials');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(app_html_lang()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(__('login.title')); ?> - <?php echo htmlspecialchars($admin_site_name); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0) 32%),
                radial-gradient(circle at bottom right, rgba(229, 231, 235, 0.72), rgba(229, 231, 235, 0) 30%),
                linear-gradient(180deg, #f5f5f7 0%, #e5e7eb 100%);
            min-height: 100vh;
            overflow: hidden;
            position: relative;
            color: #1f2937;
        }

        *, *::before, *::after {
            transition: none !important;
            animation: none !important;
        }

        .login-container {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 100%;
            max-width: 28rem;
            padding: 1rem;
        }

        .login-form {
            width: 100%;
            box-sizing: border-box;
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(24px) saturate(180%);
            border: 1px solid rgba(209, 213, 219, 0.9);
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
        }

        .login-badge {
            background: linear-gradient(180deg, #6b7280 0%, #374151 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18);
        }

        .back-link {
            color: #4b5563;
        }

        .back-link:hover {
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="fixed right-4 top-4 z-50">
        <select onchange="window.location.href=this.value" class="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            <?php foreach (app_supported_locales() as $localeCode => $localeLabel): ?>
                <option value="<?php echo htmlspecialchars(app_locale_switch_url($localeCode)); ?>" <?php echo app_locale() === $localeCode ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($localeLabel); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="login-container">
        <!-- 登录卡片 -->
        <div class="rounded-2xl p-8 login-form">
            <!-- Logo和标题 -->
            <div class="text-center mb-8">
                <div class="login-badge w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="shield-check" class="w-8 h-8 text-white"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-2"><?php echo __('login.title'); ?></h1>
                <p class="text-gray-600"><?php echo htmlspecialchars(__('login.subtitle', ['site_name' => $admin_site_name])); ?></p>
            </div>

            <!-- 错误提示 -->
            <?php if (!empty($error)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center">
                        <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-2"></i>
                        <span class="text-red-700"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 成功提示 -->
            <?php if (!empty($success)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-center">
                        <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-2"></i>
                        <span class="text-green-700"><?php echo htmlspecialchars($success); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 登录表单 -->
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                        <?php echo __('login.username'); ?>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i data-lucide="user" class="w-5 h-5 text-gray-400"></i>
                        </div>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            required
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                            placeholder="<?php echo htmlspecialchars(__('login.username_placeholder')); ?>"
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                        >
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        <?php echo __('login.password'); ?>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i data-lucide="lock" class="w-5 h-5 text-gray-400"></i>
                        </div>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                            placeholder="<?php echo htmlspecialchars(__('login.password_placeholder')); ?>"
                        >
                    </div>
                </div>

                <button
                    type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                >
                    <?php echo __('login.submit'); ?>
                </button>
            </form>

        </div>

        <!-- 返回首页链接 -->
        <div class="text-center mt-6">
            <a href="../" class="back-link">
                <i data-lucide="arrow-left" class="w-4 h-4 inline mr-1"></i>
                <?php echo __('login.back_home'); ?>
            </a>
        </div>
    </div>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script>
        // 等待页面完全加载后再执行
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化图标
            lucide.createIcons();

            // 延迟聚焦，避免页面加载时的滑动
            setTimeout(function() {
                const usernameInput = document.getElementById('username');
                if (usernameInput) {
                    usernameInput.focus();
                }
            }, 100);

            // 表单提交时显示加载状态（移除动画）
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const button = this.querySelector('button[type="submit"]');
                    if (button) {
                        button.innerHTML = '<?php echo addslashes(__('login.submitting')); ?>';
                        button.disabled = true;
                    }
                });
            }
        });

        // 防止页面滚动
        document.body.style.overflow = 'hidden';
    </script>
</body>
</html>
