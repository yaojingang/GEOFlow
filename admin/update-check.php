<?php
define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/update_check.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_redirect('dashboard.php');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['admin_message_error'] = __('message.csrf_failed');
    admin_redirect('dashboard.php');
}

geoflow_get_update_state(true);
$_SESSION['admin_message_success'] = __('update.check_completed');

$target = trim((string) ($_POST['redirect_target'] ?? 'dashboard'));
if ($target === 'site-settings') {
    admin_redirect('site-settings.php#system-update');
}

admin_redirect('dashboard.php');
