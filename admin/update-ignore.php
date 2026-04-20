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

$state = geoflow_get_update_state(false);
$version = trim((string) ($_POST['version'] ?? ($state['latest_version'] ?? '')));

if ($version !== '') {
    set_setting('update_ignored_version', $version);
    $_SESSION['admin_message_success'] = __('update.ignore_saved', ['version' => $version]);
}

$target = trim((string) ($_POST['redirect_target'] ?? 'dashboard'));
if ($target === 'site-settings') {
    admin_redirect('site-settings.php#system-update');
}

admin_redirect('dashboard.php');
