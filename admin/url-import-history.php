<?php
define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin_login();
session_write_close();

$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;

$where = ['1 = 1'];
$params = [];

if ($status !== '') {
    $where[] = 'j.status = ?';
    $params[] = $status;
}

if ($search !== '') {
    $where[] = '(j.url LIKE ? OR j.source_domain LIKE ? OR j.page_title LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);
$countStmt = $db->prepare("SELECT COUNT(*) AS total FROM url_import_jobs j WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) ($countStmt->fetch()['total'] ?? 0);
$totalPages = max(1, (int) ceil($total / $per_page));
$offset = ($page - 1) * $per_page;

$listSql = "
    SELECT j.*,
           (
               SELECT message
               FROM url_import_job_logs l
               WHERE l.job_id = j.id
               ORDER BY l.id DESC
               LIMIT 1
           ) AS latest_log
    FROM url_import_jobs j
    WHERE {$whereSql}
    ORDER BY j.created_at DESC, j.id DESC
    LIMIT {$per_page} OFFSET {$offset}
";
$listStmt = $db->prepare($listSql);
$listStmt->execute($params);
$jobs = $listStmt->fetchAll();

$stats = [
    'total' => (int) ($db->query("SELECT COUNT(*) AS count FROM url_import_jobs")->fetch()['count'] ?? 0),
    'completed' => (int) ($db->query("SELECT COUNT(*) AS count FROM url_import_jobs WHERE status = 'completed'")->fetch()['count'] ?? 0),
    'running' => (int) ($db->query("SELECT COUNT(*) AS count FROM url_import_jobs WHERE status = 'running'")->fetch()['count'] ?? 0),
    'failed' => (int) ($db->query("SELECT COUNT(*) AS count FROM url_import_jobs WHERE status = 'failed'")->fetch()['count'] ?? 0),
];

function url_import_status_meta(string $status): array {
    return match ($status) {
        'completed' => ['label' => __('url_import_history.status.completed'), 'class' => 'bg-emerald-100 text-emerald-800'],
        'running' => ['label' => __('url_import_history.status.running'), 'class' => 'bg-blue-100 text-blue-800'],
        'failed' => ['label' => __('url_import_history.status.failed'), 'class' => 'bg-red-100 text-red-800'],
        default => ['label' => __('url_import_history.status.queued'), 'class' => 'bg-yellow-100 text-yellow-800'],
    };
}

function url_import_import_meta(array $job): array {
    $result = json_decode($job['result_json'] ?? '{}', true);
    $importResult = is_array($result) ? ($result['import_result'] ?? null) : null;
    if (is_array($importResult) && !empty($importResult['imported_at'])) {
        return [
            'label' => __('url_import_history.import.imported'),
            'class' => 'bg-emerald-100 text-emerald-800',
            'summary' => __('url_import_history.import.summary', [
                'knowledge_base' => !empty($importResult['knowledge_base_id']) ? '#' . $importResult['knowledge_base_id'] : '-',
                'keywords' => (int) ($importResult['inserted_keywords'] ?? 0),
                'titles' => (int) ($importResult['inserted_titles'] ?? 0),
                'images' => (int) ($importResult['inserted_images'] ?? 0),
            ]),
        ];
    }

    return [
        'label' => __('url_import_history.import.not_imported'),
        'class' => 'bg-gray-100 text-gray-700',
        'summary' => __('url_import_history.import.not_imported_summary'),
    ];
}

$page_title = __('url_import_history.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="materials.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . __('url_import_history.page_heading') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('url_import_history.page_subtitle') . '</p>
        </div>
    </div>
    <a href="url-import.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-cyan-600 hover:bg-cyan-700">
        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
        ' . __('url_import_history.button.new_job') . '
    </a>
</div>
';

require_once __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
    <div class="bg-white shadow rounded-lg p-5"><div class="text-sm text-gray-500"><?php echo __('url_import_history.stats.total'); ?></div><div class="mt-2 text-2xl font-semibold text-gray-900"><?php echo $stats['total']; ?></div></div>
    <div class="bg-white shadow rounded-lg p-5"><div class="text-sm text-gray-500"><?php echo __('url_import_history.stats.completed'); ?></div><div class="mt-2 text-2xl font-semibold text-emerald-700"><?php echo $stats['completed']; ?></div></div>
    <div class="bg-white shadow rounded-lg p-5"><div class="text-sm text-gray-500"><?php echo __('url_import_history.stats.running'); ?></div><div class="mt-2 text-2xl font-semibold text-blue-700"><?php echo $stats['running']; ?></div></div>
    <div class="bg-white shadow rounded-lg p-5"><div class="text-sm text-gray-500"><?php echo __('url_import_history.stats.failed'); ?></div><div class="mt-2 text-2xl font-semibold text-red-700"><?php echo $stats['failed']; ?></div></div>
</div>

<div class="bg-white shadow rounded-lg mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-medium text-gray-900"><?php echo __('url_import_history.section.filters'); ?></h3>
    </div>
    <div class="px-6 py-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700"><?php echo __('status.label'); ?></label>
                <select name="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-cyan-500 focus:border-cyan-500 sm:text-sm">
                    <option value=""><?php echo __('url_import_history.filter.all_statuses'); ?></option>
                    <option value="queued" <?php echo $status === 'queued' ? 'selected' : ''; ?>><?php echo __('url_import_history.status.queued'); ?></option>
                    <option value="running" <?php echo $status === 'running' ? 'selected' : ''; ?>><?php echo __('url_import_history.status.running'); ?></option>
                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>><?php echo __('url_import_history.status.completed'); ?></option>
                    <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>><?php echo __('url_import_history.status.failed'); ?></option>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700"><?php echo __('button.search'); ?></label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo __('url_import_history.placeholder.search'); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-cyan-500 focus:border-cyan-500 sm:text-sm">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-cyan-600 hover:bg-cyan-700"><?php echo __('button.filter'); ?></button>
                <a href="url-import-history.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"><?php echo __('button.clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<div class="bg-white shadow rounded-lg overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-medium text-gray-900"><?php echo __('url_import_history.section.records'); ?></h3>
    </div>
    <?php if (empty($jobs)): ?>
        <div class="px-6 py-10 text-center text-gray-500"><?php echo __('url_import_history.empty.no_records'); ?></div>
    <?php else: ?>
        <div class="divide-y divide-gray-200">
            <?php foreach ($jobs as $job): ?>
                <?php $meta = url_import_status_meta((string) $job['status']); ?>
                <?php $importMeta = url_import_import_meta($job); ?>
                <div class="px-6 py-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $meta['class']; ?>"><?php echo $meta['label']; ?></span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $importMeta['class']; ?>"><?php echo $importMeta['label']; ?></span>
                                <div class="text-sm text-gray-500">#<?php echo (int) $job['id']; ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($job['source_domain'] ?: __('url_import_history.value.unknown_source')); ?></div>
                            </div>
                            <div class="mt-3 text-sm font-medium text-gray-900 break-all"><?php echo htmlspecialchars($job['url']); ?></div>
                            <?php if (!empty($job['page_title'])): ?>
                                <div class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($job['page_title']); ?></div>
                            <?php endif; ?>
                            <div class="mt-3 grid grid-cols-1 md:grid-cols-4 gap-3 text-sm text-gray-500">
                                <div><?php echo __('url_import_history.label.current_step', ['step' => htmlspecialchars($job['current_step'])]); ?></div>
                                <div><?php echo __('url_import_history.label.progress', ['percent' => (int) $job['progress_percent']]); ?></div>
                                <div><?php echo __('url_import_history.label.created_at', ['time' => htmlspecialchars($job['created_at'])]); ?></div>
                                <div><?php echo __('url_import_history.label.finished_at', ['time' => htmlspecialchars($job['finished_at'] ?: '-')]); ?></div>
                            </div>
                            <div class="mt-3 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600"><?php echo __('url_import_history.label.import_status', ['summary' => htmlspecialchars($importMeta['summary'])]); ?></div>
                            <?php if (!empty($job['latest_log'])): ?>
                                <div class="mt-3 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600"><?php echo __('url_import_history.label.latest_log', ['message' => htmlspecialchars($job['latest_log'])]); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($job['error_message'])): ?>
                                <div class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700"><?php echo __('url_import_history.label.error', ['message' => htmlspecialchars($job['error_message'])]); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
