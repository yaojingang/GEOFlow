<?php
/**
 * 智能GEO内容系统 - 管理员操作日志
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';

require_super_admin();
session_write_close();

$search = trim($_GET['search'] ?? '');
$adminId = (int) ($_GET['admin_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = ['1 = 1'];
$params = [];

if ($adminId > 0) {
    $where[] = 'aal.admin_id = ?';
    $params[] = $adminId;
}

if ($search !== '') {
    $where[] = '(aal.admin_username LIKE ? OR aal.action LIKE ? OR aal.page LIKE ? OR aal.details LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM admin_activity_logs aal WHERE {$whereSql}");
$countStmt->execute($params);
$totalLogs = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalLogs / $perPage));

$listStmt = $db->prepare("
    SELECT
        aal.*,
        a.display_name
    FROM admin_activity_logs aal
    LEFT JOIN admins a ON a.id = aal.admin_id
    WHERE {$whereSql}
    ORDER BY aal.created_at DESC, aal.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$listStmt->execute($params);
$logs = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$admins = $db->query("
    SELECT id, username, display_name, role
    FROM admins
    ORDER BY CASE role WHEN 'super_admin' THEN 0 ELSE 1 END, username ASC
")->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'total_logs' => $totalLogs,
    'today_logs' => (int) $db->query("SELECT COUNT(*) FROM admin_activity_logs WHERE DATE(created_at) = CURRENT_DATE")->fetchColumn(),
    'active_admins' => (int) $db->query("SELECT COUNT(DISTINCT admin_id) FROM admin_activity_logs WHERE created_at >= CURRENT_TIMESTAMP - INTERVAL '7 days'")->fetchColumn(),
];

$page_title = __('activity_logs.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="admin-users.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . __('activity_logs.heading') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('activity_logs.subtitle') . '</p>
        </div>
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
                            <i data-lucide="clipboard-list" class="h-6 w-6 text-indigo-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('activity_logs.total_logs'); ?></dt>
                            <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_logs']; ?></dd>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="calendar-clock" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('activity_logs.today_logs'); ?></dt>
                            <dd class="text-lg font-medium text-gray-900"><?php echo $stats['today_logs']; ?></dd>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="users" class="h-6 w-6 text-amber-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('activity_logs.active_admins'); ?></dt>
                            <dd class="text-lg font-medium text-gray-900"><?php echo $stats['active_admins']; ?></dd>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('activity_logs.search'); ?></label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="<?php echo htmlspecialchars(__('activity_logs.search_placeholder')); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('activity_logs.admin'); ?></label>
                        <select name="admin_id" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="0"><?php echo __('activity_logs.all_admins'); ?></option>
                            <?php foreach ($admins as $admin): ?>
                                <option value="<?php echo (int) $admin['id']; ?>" <?php echo $adminId === (int) $admin['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(($admin['display_name'] ?: $admin['username']) . ' / ' . $admin['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end gap-3">
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                            <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                            <?php echo __('activity_logs.filter'); ?>
                        </button>
                        <a href="admin-activity-logs.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <?php echo __('activity_logs.reset'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('activity_logs.list_title'); ?></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('activity_logs.time'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('activity_logs.admin'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('activity_logs.action'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('activity_logs.page'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('activity_logs.target'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('activity_logs.details'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('activity_logs.ip'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center text-sm text-gray-500"><?php echo __('activity_logs.empty'); ?></td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($logs as $log): ?>
                            <?php
                                $detailsText = trim((string) ($log['details'] ?? ''));
                                $decodedDetails = $detailsText !== '' ? json_decode($detailsText, true) : null;
                                if (is_array($decodedDetails)) {
                                    $detailsText = json_encode($decodedDetails, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                                }
                            ?>
                            <tr class="align-top">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <div><?php echo htmlspecialchars($log['created_at']); ?></div>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars(time_ago($log['created_at'])); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($log['display_name'] ?: $log['admin_username']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($log['admin_username']); ?></div>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars(($log['admin_role'] ?? 'admin') === 'super_admin' ? __('activity_logs.role_super_admin') : __('activity_logs.role_admin')); ?></div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 whitespace-nowrap"><?php echo htmlspecialchars($log['action']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    <div><?php echo htmlspecialchars($log['page'] ?: '-'); ?></div>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($log['request_method'] ?: 'GET'); ?></div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    <?php if (!empty($log['target_type'])): ?>
                                        <?php echo htmlspecialchars($log['target_type']); ?>
                                        <?php if (!empty($log['target_id'])): ?>
                                            #<?php echo (int) $log['target_id']; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-xs text-gray-600">
                                    <pre class="whitespace-pre-wrap break-words max-w-xl"><?php echo htmlspecialchars($detailsText !== '' ? truncate_text($detailsText, 500) : '-'); ?></pre>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="mt-6 flex items-center justify-between">
                <div class="text-sm text-gray-500"><?php echo __('activity_logs.page_summary', ['total' => $totalLogs, 'page' => $page, 'total_pages' => $totalPages]); ?></div>
                <div class="flex items-center gap-2">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(['search' => $search, 'admin_id' => $adminId, 'page' => $page - 1])); ?>" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 bg-white hover:bg-gray-50"><?php echo __('activity_logs.prev'); ?></a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(['search' => $search, 'admin_id' => $adminId, 'page' => $page + 1])); ?>" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 bg-white hover:bg-gray-50"><?php echo __('activity_logs.next'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
