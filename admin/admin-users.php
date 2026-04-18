<?php
/**
 * 智能GEO内容系统 - 管理员管理
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';

require_super_admin();
session_write_close();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'create_admin':
                $username = trim($_POST['username'] ?? '');
                $displayName = trim($_POST['display_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if ($username === '') {
                    $error = __('admin_users.error.username_required');
                } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                    $error = __('admin_users.error.username_invalid');
                } elseif ($password === '' || $confirmPassword === '') {
                    $error = __('admin_users.error.password_required');
                } elseif ($password !== $confirmPassword) {
                    $error = __('admin_users.error.password_mismatch');
                } elseif (strlen($password) < 8) {
                    $error = __('admin_users.error.password_too_short');
                } else {
                    try {
                        $checkStmt = $db->prepare("SELECT COUNT(*) FROM admins WHERE username = ?");
                        $checkStmt->execute([$username]);
                        if ((int) $checkStmt->fetchColumn() > 0) {
                            $error = __('admin_users.error.username_exists');
                            break;
                        }

                        $stmt = $db->prepare("
                            INSERT INTO admins (
                                username, password, email, display_name, role, status, created_by, created_at, updated_at
                            ) VALUES (?, ?, ?, ?, 'admin', 'active', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");

                        $stmt->execute([
                            $username,
                            password_hash($password, PASSWORD_DEFAULT),
                            $email,
                            $displayName,
                            (int) ($_SESSION['admin_id'] ?? 0)
                        ]);

                        $message = __('admin_users.message.create_success');
                    } catch (Throwable $e) {
                        $error = __('admin_users.message.create_error', ['message' => $e->getMessage()]);
                    }
                }
                break;

            case 'toggle_admin_status':
                $adminId = (int) ($_POST['admin_id'] ?? 0);
                $nextStatus = ($_POST['next_status'] ?? 'inactive') === 'active' ? 'active' : 'inactive';

                if ($adminId <= 0) {
                    $error = __('admin_users.error.invalid_id');
                    break;
                }

                if ($adminId === (int) ($_SESSION['admin_id'] ?? 0)) {
                    $error = __('admin_users.error.cannot_toggle_self');
                    break;
                }

                try {
                    $stmt = $db->prepare("SELECT role, username FROM admins WHERE id = ?");
                    $stmt->execute([$adminId]);
                    $targetAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$targetAdmin) {
                        $error = __('admin_users.error.not_found');
                        break;
                    }

                    if (($targetAdmin['role'] ?? 'admin') === 'super_admin') {
                        $error = __('admin_users.error.cannot_toggle_super_admin');
                        break;
                    }

                    $updateStmt = $db->prepare("
                        UPDATE admins
                        SET status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$nextStatus, $adminId]);

                    $message = $nextStatus === 'active'
                        ? __('admin_users.message.enabled')
                        : __('admin_users.message.disabled');
                } catch (Throwable $e) {
                    $error = __('admin_users.message.toggle_error', ['message' => $e->getMessage()]);
                }
                break;
        }
    }
}

$adminsStmt = $db->query("
    SELECT
        a.id,
        a.username,
        a.email,
        a.display_name,
        a.role,
        a.status,
        a.last_login,
        a.created_at,
        creator.username AS creator_username,
        (
            SELECT COUNT(*)
            FROM admin_activity_logs aal
            WHERE aal.admin_id = a.id
        ) AS activity_count
    FROM admins a
    LEFT JOIN admins creator ON creator.id = a.created_by
    ORDER BY
        CASE a.role WHEN 'super_admin' THEN 0 ELSE 1 END,
        a.created_at ASC,
        a.id ASC
");
$admins = $adminsStmt->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'total_admins' => count($admins),
    'active_admins' => count(array_filter($admins, static fn(array $admin): bool => ($admin['status'] ?? 'active') === 'active')),
    'super_admins' => count(array_filter($admins, static fn(array $admin): bool => ($admin['role'] ?? 'admin') === 'super_admin')),
];

$page_title = __('admin_users.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="security-settings.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . htmlspecialchars(__('admin_users.page_title')) . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . htmlspecialchars(__('admin_users.page_subtitle')) . '</p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <a href="admin-activity-logs.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            <i data-lucide="clipboard-list" class="w-4 h-4 mr-2"></i>
            ' . htmlspecialchars(__('admin_users.view_logs')) . '
        </a>
        <button onclick="showCreateAdminModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
            <i data-lucide="user-plus" class="w-4 h-4 mr-2"></i>
            ' . htmlspecialchars(__('admin_users.add_admin')) . '
        </button>
    </div>
</div>
';

require_once __DIR__ . '/includes/header.php';
?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="users" class="h-6 w-6 text-indigo-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('admin_users.total_admins'); ?></dt>
                            <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_admins']; ?></dd>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="badge-check" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('admin_users.active_admins'); ?></dt>
                            <dd class="text-lg font-medium text-gray-900"><?php echo $stats['active_admins']; ?></dd>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="shield-check" class="h-6 w-6 text-amber-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('admin_users.super_admins'); ?></dt>
                            <dd class="text-lg font-medium text-gray-900"><?php echo $stats['super_admins']; ?></dd>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <i data-lucide="info" class="w-5 h-5 text-blue-600 mt-0.5"></i>
                <div class="text-sm text-blue-900">
                    <?php echo __('admin_users.permission_notice'); ?>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('admin_users.list_title'); ?></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('admin_users.column_account'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('admin_users.column_role'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('admin_users.column_status'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('admin_users.column_last_login'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('admin_users.column_created'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('admin_users.column_activity'); ?></th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('common.actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($admin['display_name'] ?: $admin['username']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($admin['username']); ?></div>
                                    <?php if (!empty($admin['email'])): ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($admin['email']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if (($admin['role'] ?? 'admin') === 'super_admin'): ?>
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800"><?php echo __('admin_users.role_super_admin'); ?></span>
                                    <?php else: ?>
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800"><?php echo __('admin_users.role_admin'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if (($admin['status'] ?? 'active') === 'active'): ?>
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><?php echo __('admin_users.status_active'); ?></span>
                                    <?php else: ?>
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700"><?php echo __('admin_users.status_inactive'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo !empty($admin['last_login']) ? htmlspecialchars($admin['last_login']) : __('admin_users.none_last_login'); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <div><?php echo htmlspecialchars($admin['created_at']); ?></div>
                                    <div class="text-xs text-gray-400"><?php echo __('admin_users.created_by', ['value' => htmlspecialchars($admin['creator_username'] ?: __('admin_users.system_init'))]); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo __('admin_users.activity_count', ['count' => (int) ($admin['activity_count'] ?? 0)]); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <?php if (($admin['role'] ?? 'admin') !== 'super_admin' && (int) $admin['id'] !== (int) ($_SESSION['admin_id'] ?? 0)): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="toggle_admin_status">
                                            <input type="hidden" name="admin_id" value="<?php echo (int) $admin['id']; ?>">
                                            <input type="hidden" name="next_status" value="<?php echo ($admin['status'] ?? 'active') === 'active' ? 'inactive' : 'active'; ?>">
                                            <button type="submit" class="<?php echo ($admin['status'] ?? 'active') === 'active' ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800'; ?>">
                                                <?php echo ($admin['status'] ?? 'active') === 'active' ? __('admin_users.action_disable') : __('admin_users.action_enable'); ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-gray-300">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="create-admin-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('admin_users.modal_create'); ?></h3>
                        <button onclick="hideCreateAdminModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                    <form method="POST" class="px-6 py-5 space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="create_admin">

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('admin_users.field_username'); ?></label>
                            <input type="text" name="username" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="<?php echo htmlspecialchars(__('admin_users.placeholder_username')); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('admin_users.field_display_name'); ?></label>
                            <input type="text" name="display_name" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="<?php echo htmlspecialchars(__('admin_users.placeholder_display_name')); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('admin_users.field_email'); ?></label>
                            <input type="email" name="email" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="<?php echo htmlspecialchars(__('admin_users.placeholder_email')); ?>">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('admin_users.field_password'); ?></label>
                                <input type="password" name="password" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('admin_users.field_confirm_password'); ?></label>
                                <input type="password" name="confirm_password" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>

                        <div class="bg-gray-50 border border-gray-200 rounded-md p-3 text-sm text-gray-600">
                            <?php echo __('admin_users.create_help'); ?>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" onclick="hideCreateAdminModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50"><?php echo __('button.cancel'); ?></button>
                            <button type="submit" class="px-4 py-2 border border-transparent rounded-md text-white bg-indigo-600 hover:bg-indigo-700"><?php echo __('admin_users.create_admin_submit'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function showCreateAdminModal() {
                document.getElementById('create-admin-modal').classList.remove('hidden');
            }

            function hideCreateAdminModal() {
                document.getElementById('create-admin-modal').classList.add('hidden');
            }
        </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
