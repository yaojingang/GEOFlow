<?php
/**
 * 清洁版本的functions.php - 只包含config.php中没有的函数
 */

// 防止直接访问
if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/update_check.php';

function sync_admin_session(array $admin): void {
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = (int) ($admin['id'] ?? 0);
    $_SESSION['admin_username'] = (string) ($admin['username'] ?? '');
    $_SESSION['admin_email'] = (string) ($admin['email'] ?? '');
    $_SESSION['admin_role'] = (string) ($admin['role'] ?? 'admin');
    $_SESSION['admin_status'] = (string) ($admin['status'] ?? 'active');
    $_SESSION['admin_display_name'] = (string) ($admin['display_name'] ?? '');
}

function clear_admin_session(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

// 检查管理员是否已登录
function is_admin_logged_in() {
    return !empty($_SESSION['admin_logged_in']) && !empty($_SESSION['admin_id']);
}

function admin_welcome_auto_open_request_key(): string {
    return '__admin_welcome_should_auto_open';
}

function admin_welcome_state(): array {
    $updateState = function_exists('geoflow_get_update_state')
        ? geoflow_get_update_state(false)
        : [
            'current_version' => APP_VERSION,
            'latest_version' => '',
            'payload' => [],
            'last_checked_at' => null,
            'ignored_version' => '',
            'is_update_available' => false,
            'is_ignored' => false,
        ];

    if (!empty($updateState['is_update_available']) && empty($updateState['is_ignored'])) {
        return [
            'mode' => 'update',
            'version' => 'update:' . (string) ($updateState['latest_version'] ?? ''),
            'update' => $updateState,
            'copy' => function_exists('geoflow_get_update_copy') ? geoflow_get_update_copy($updateState) : [],
        ];
    }

    return [
        'mode' => 'intro',
        'version' => 'intro:' . APP_VERSION,
        'update' => $updateState,
        'copy' => function_exists('geoflow_get_update_copy') ? geoflow_get_update_copy($updateState) : [],
    ];
}

function admin_welcome_version(): string {
    $state = admin_welcome_state();
    return (string) ($state['version'] ?? ('intro:' . APP_VERSION));
}

function reset_admin_welcome_auto_open_state(): void {
    unset($GLOBALS[admin_welcome_auto_open_request_key()]);
}

function prepare_admin_welcome_auto_open(?array $admin): void {
    if (!$admin) {
        $GLOBALS[admin_welcome_auto_open_request_key()] = false;
        return;
    }

    $currentVersion = admin_welcome_version();
    $seenVersion = (string) ($admin['welcome_seen_version'] ?? '');
    $shouldAutoOpen = $seenVersion !== $currentVersion;

    if ($shouldAutoOpen) {
        global $db;
        if ($db instanceof PDO) {
            try {
                $stmt = $db->prepare("
                    UPDATE admins
                    SET welcome_seen_version = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$currentVersion, (int) $admin['id']]);
            } catch (Throwable $e) {
                error_log('更新管理员欢迎页版本失败: ' . $e->getMessage());
            }
        }
    }

    $GLOBALS[admin_welcome_auto_open_request_key()] = $shouldAutoOpen;
}

function current_admin_welcome_auto_open(): bool {
    return !empty($GLOBALS[admin_welcome_auto_open_request_key()]);
}

function get_current_admin(bool $forceRefresh = false): ?array {
    static $cachedAdmin = null;
    static $cachedAdminId = null;

    if (!is_admin_logged_in()) {
        return null;
    }

    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        return null;
    }

    if (!$forceRefresh && $cachedAdmin !== null && $cachedAdminId === $adminId) {
        return $cachedAdmin;
    }

    global $db;
    if (!($db instanceof PDO)) {
        return [
            'id' => $adminId,
            'username' => (string) ($_SESSION['admin_username'] ?? ''),
            'email' => (string) ($_SESSION['admin_email'] ?? ''),
            'display_name' => (string) ($_SESSION['admin_display_name'] ?? ''),
            'role' => (string) ($_SESSION['admin_role'] ?? 'admin'),
            'status' => (string) ($_SESSION['admin_status'] ?? 'active'),
        ];
    }

    $stmt = $db->prepare("
        SELECT id, username, email, display_name, role, status, last_login, welcome_dismissed_at, welcome_seen_version, created_at
        FROM admins
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($admin) {
        sync_admin_session($admin);
        $cachedAdmin = $admin;
        $cachedAdminId = $adminId;
    }

    return $admin;
}

function is_super_admin(): bool {
    $admin = get_current_admin();
    return ($admin['role'] ?? ($_SESSION['admin_role'] ?? 'admin')) === 'super_admin';
}

function get_admin_request_ip(): string {
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }

        if (str_contains($candidate, ',')) {
            $parts = array_map('trim', explode(',', $candidate));
            $candidate = $parts[0] ?? '';
        }

        if ($candidate !== '') {
            return mb_substr($candidate, 0, 64);
        }
    }

    return '';
}

function sanitize_admin_activity_payload(array $payload): array {
    $sensitiveKeys = [
        'password', 'current_password', 'new_password', 'confirm_password',
        'api_key', 'csrf_token'
    ];
    $largeTextPattern = '/content|prompt|description|bio|note|words|html/i';
    $clean = [];

    foreach ($payload as $key => $value) {
        $key = (string) $key;

        if (in_array($key, $sensitiveKeys, true)) {
            $clean[$key] = '[redacted]';
            continue;
        }

        if (is_array($value)) {
            $clean[$key] = sanitize_admin_activity_payload($value);
            continue;
        }

        if (is_bool($value)) {
            $clean[$key] = $value;
            continue;
        }

        if ($value === null) {
            $clean[$key] = null;
            continue;
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            $clean[$key] = '';
            continue;
        }

        if (preg_match($largeTextPattern, $key)) {
            $clean[$key] = '[text:' . mb_strlen($stringValue) . ' chars]';
            continue;
        }

        if (mb_strlen($stringValue) > 180) {
            $stringValue = mb_substr($stringValue, 0, 180) . '...';
        }

        $clean[$key] = $stringValue;
    }

    return $clean;
}

function log_admin_activity(string $action, array $context = []): void {
    global $db;

    if (!($db instanceof PDO) || !is_admin_logged_in()) {
        return;
    }

    $admin = get_current_admin();
    if (!$admin) {
        return;
    }

    $details = $context['details'] ?? [];
    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_UNICODE);
    } else {
        $details = (string) $details;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO admin_activity_logs (
                admin_id, admin_username, admin_role, action, request_method,
                page, target_type, target_id, ip_address, details, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            (int) ($admin['id'] ?? 0),
            (string) ($admin['username'] ?? ($_SESSION['admin_username'] ?? '')),
            (string) ($admin['role'] ?? ($_SESSION['admin_role'] ?? 'admin')),
            $action,
            strtoupper((string) ($context['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'))),
            (string) ($context['page'] ?? basename($_SERVER['PHP_SELF'] ?? '')),
            (string) ($context['target_type'] ?? ''),
            isset($context['target_id']) ? (int) $context['target_id'] : null,
            (string) ($context['ip_address'] ?? get_admin_request_ip()),
            $details
        ]);
    } catch (Throwable $e) {
        error_log('记录管理员操作日志失败: ' . $e->getMessage());
    }
}

function log_admin_request_if_needed(array $context = []): void {
    static $alreadyLogged = false;

    if ($alreadyLogged || !is_admin_logged_in()) {
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $payload = $context['details'] ?? (!empty($_POST) ? sanitize_admin_activity_payload($_POST) : []);
    $action = (string) ($context['action'] ?? ($_POST['action'] ?? 'submit'));
    $page = (string) ($context['page'] ?? basename($_SERVER['PHP_SELF'] ?? ''));

    log_admin_activity($page . ':' . $action, [
        'request_method' => $method,
        'page' => $page,
        'target_type' => (string) ($context['target_type'] ?? ''),
        'target_id' => $context['target_id'] ?? null,
        'details' => $payload
    ]);

    $alreadyLogged = true;
}

function admin_forbidden(string $message = '仅超级管理员可访问此页面'): void {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>访问受限</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-gray-50"><div class="min-h-screen flex items-center justify-center px-6"><div class="max-w-md w-full bg-white shadow rounded-2xl p-8 text-center"><h1 class="text-2xl font-bold text-gray-900 mb-3">访问受限</h1><p class="text-gray-600 mb-6">' . escape_html($message) . '</p><a href="' . escape_html(admin_url('dashboard.php')) . '" class="inline-flex items-center px-4 py-2 rounded-md bg-blue-600 text-white">返回后台首页</a></div></div></body></html>';
    exit;
}

// 管理员登录检查
function require_admin_login() {
    if (!is_admin_logged_in()) {
        admin_redirect();
    }

    $admin = get_current_admin(true);
    if (!$admin || ($admin['status'] ?? 'active') !== 'active') {
        clear_admin_session();
        admin_redirect();
    }

    prepare_admin_welcome_auto_open($admin);
    log_admin_request_if_needed();
}

function require_super_admin(): void {
    require_admin_login();

    if (!is_super_admin()) {
        admin_forbidden();
    }
}

// clean_input函数已在config.php中定义

// 管理员登录验证
function verify_admin_login($username, $password) {
    return $username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD);
}

// 基本函数
function escape_html($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function url($path = '') {
    return SITE_URL . '/' . ltrim($path, '/');
}

function normalize_content_asset_url($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^(?:https?:)?//#i', $path) || str_starts_with($path, 'data:') || str_starts_with($path, '/')) {
        return $path;
    }

    $path = preg_replace('#/+#', '/', $path);

    if (preg_match('#^(uploads|assets)/#i', $path)) {
        return '/' . ltrim($path, '/');
    }

    return $path;
}

function normalize_cta_target_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('#^(?:https?:)?//#i', $url) || str_starts_with($url, '/')) {
        return $url;
    }

    return '/' . ltrim($url, '/');
}

function get_article_detail_ads(): array {
    $raw = get_setting('article_detail_ads', '[]');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $ads = [];
    foreach ($decoded as $ad) {
        if (!is_array($ad)) {
            continue;
        }

        $copy = trim((string) ($ad['copy'] ?? ''));
        $title = trim((string) ($ad['title'] ?? ''));
        $buttonText = trim((string) ($ad['button_text'] ?? ''));
        $buttonUrl = normalize_cta_target_url((string) ($ad['button_url'] ?? ''));
        if ($copy === '' || $buttonText === '' || $buttonUrl === '') {
            continue;
        }

        $ads[] = [
            'id' => trim((string) ($ad['id'] ?? '')),
            'name' => trim((string) ($ad['name'] ?? '')),
            'badge' => trim((string) ($ad['badge'] ?? '')),
            'title' => $title,
            'copy' => $copy,
            'button_text' => $buttonText,
            'button_url' => $buttonUrl,
            'enabled' => !empty($ad['enabled'])
        ];
    }

    return $ads;
}

function get_active_article_detail_ad(): ?array {
    foreach (get_article_detail_ads() as $ad) {
        if (!empty($ad['enabled'])) {
            if ($ad['id'] === '') {
                $ad['id'] = 'article-detail-ad';
            }
            if ($ad['name'] === '') {
                $ad['name'] = '文章详情广告';
            }
            return $ad;
        }
    }

    return null;
}

function normalize_article_workflow_state(string $status, string $reviewStatus, ?string $publishedAt = null): array {
    $allowedStatuses = ['draft', 'published', 'private'];
    $allowedReviewStatuses = ['pending', 'approved', 'rejected', 'auto_approved'];

    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'draft';
    }

    if (!in_array($reviewStatus, $allowedReviewStatuses, true)) {
        $reviewStatus = 'pending';
    }

    if (in_array($reviewStatus, ['pending', 'rejected'], true)) {
        $status = 'draft';
    }

    if ($status === 'published' && in_array($reviewStatus, ['pending', 'rejected'], true)) {
        $reviewStatus = 'approved';
    }

    if ($status !== 'published' && $reviewStatus === 'auto_approved') {
        $status = 'published';
    }

    if ($status === 'published' && $reviewStatus === 'pending') {
        $reviewStatus = 'approved';
    }

    if ($status === 'published') {
        $publishedAt = $publishedAt ?: date('Y-m-d H:i:s');
    } else {
        $publishedAt = null;
    }

    return [
        'status' => $status,
        'review_status' => $reviewStatus,
        'published_at' => $publishedAt
    ];
}

function build_article_slug_base(string $title): string {
    // Use a short fixed-width ASCII token so frontend URLs stay consistent
    // regardless of title language or punctuation.
    return generate_random_slug(8);
}

function format_date($date, $format = 'Y-m-d H:i:s') {
    return date($format, strtotime($date));
}

function truncate_text($text, $length = 100, $suffix = '...') {
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . $suffix;
}

// 数据库相关函数
function get_categories() {
    global $db;
    try {
        $stmt = $db->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function get_featured_articles($limit = 6) {
    global $db;
    try {
        $stmt = $db->prepare("
            SELECT a.*, c.name as category_name, au.name as author_name
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            LEFT JOIN authors au ON a.author_id = au.id
            WHERE a.status = 'published' AND a.deleted_at IS NULL AND a.is_featured = 1
            ORDER BY a.published_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function search_articles($search, $page = 1, $per_page = 12) {
    global $db;
    try {
        $offset = ($page - 1) * $per_page;
        $stmt = $db->prepare("
            SELECT a.*, c.name as category_name, au.name as author_name
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            LEFT JOIN authors au ON a.author_id = au.id
            WHERE a.status = 'published'
              AND a.deleted_at IS NULL
            AND (a.title LIKE ? OR a.content LIKE ? OR a.excerpt LIKE ?)
            ORDER BY a.published_at DESC
            LIMIT ? OFFSET ?
        ");
        $search_term = '%' . $search . '%';
        $stmt->execute([$search_term, $search_term, $search_term, $per_page, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function get_search_count($search) {
    global $db;
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM articles 
            WHERE status = 'published'
              AND deleted_at IS NULL
              AND (title LIKE ? OR content LIKE ? OR excerpt LIKE ?)
        ");
        $search_term = '%' . $search . '%';
        $stmt->execute([$search_term, $search_term, $search_term]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'];
    } catch (Exception $e) {
        return 0;
    }
}

function get_category_by_id($id) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

function get_articles_by_category($category_id, $page = 1, $per_page = 12) {
    global $db;
    try {
        $offset = ($page - 1) * $per_page;
        $stmt = $db->prepare("
            SELECT a.*, c.name as category_name, au.name as author_name
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            LEFT JOIN authors au ON a.author_id = au.id
            WHERE a.status = 'published' AND a.deleted_at IS NULL AND a.category_id = ?
            ORDER BY a.published_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$category_id, $per_page, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function get_category_article_count($category_id) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM articles WHERE status = 'published' AND deleted_at IS NULL AND category_id = ?");
        $stmt->execute([$category_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'];
    } catch (Exception $e) {
        return 0;
    }
}

// 根据slug获取文章详情
function get_article_by_slug($slug) {
    global $db;
    try {
        $stmt = $db->prepare("
            SELECT a.*, c.name as category_name, c.slug as category_slug, au.name as author_name
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            LEFT JOIN authors au ON a.author_id = au.id
            WHERE a.slug = ? AND a.status = 'published' AND a.deleted_at IS NULL
        ");
        $stmt->execute([$slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

// 根据ID获取文章详情
function get_article_by_id($id) {
    global $db;
    try {
        $stmt = $db->prepare("
            SELECT a.*, c.name as category_name, c.slug as category_slug, au.name as author_name
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            LEFT JOIN authors au ON a.author_id = au.id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

function get_public_article_by_id($id) {
    global $db;
    try {
        $stmt = $db->prepare("
            SELECT a.*, c.name as category_name, c.slug as category_slug, au.name as author_name
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            LEFT JOIN authors au ON a.author_id = au.id
            WHERE a.id = ?
              AND a.status = 'published'
              AND a.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

// 增加文章访问量
function increment_article_views($article_id) {
    global $db;
    try {
        $stmt = $db->prepare("UPDATE articles SET views = views + 1 WHERE id = ?");
        $stmt->execute([$article_id]);
    } catch (Exception $e) {
        // 静默处理错误
    }
}

// 生成随机slug
function generate_random_slug($length = 8) {
    $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $slug = '';
    for ($i = 0; $i < $length; $i++) {
        $slug .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $slug;
}

// 生成唯一的文章slug
function generate_unique_article_slug($dbOrTitle = '', $titleOrArticleId = null, $excludeArticleId = null) {
    global $db;

    if ($dbOrTitle instanceof PDO) {
        $pdo = $dbOrTitle;
        $title = (string) ($titleOrArticleId ?? '');
        $articleId = $excludeArticleId !== null ? (int) $excludeArticleId : null;
    } else {
        $pdo = $db;
        $title = (string) $dbOrTitle;
        $articleId = $titleOrArticleId !== null ? (int) $titleOrArticleId : null;
    }

    $slug = build_article_slug_base($title);

    while (true) {
        try {
            if ($articleId !== null) {
                $stmt = $pdo->prepare("SELECT id FROM articles WHERE slug = ? AND id != ?");
                $stmt->execute([$slug, $articleId]);
            } else {
                $stmt = $pdo->prepare("SELECT id FROM articles WHERE slug = ?");
                $stmt->execute([$slug]);
            }

            if (!$stmt->fetch()) {
                return $slug;
            }

            $slug = generate_random_slug(8);
        } catch (Exception $e) {
            return generate_random_slug(8);
        }
    }
}

function get_article_tags($article_id) {
    global $db;
    try {
        $stmt = $db->prepare("
            SELECT t.* FROM tags t
            JOIN article_tags at ON t.id = at.tag_id
            WHERE at.article_id = ?
            ORDER BY t.name
        ");
        $stmt->execute([$article_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function get_all_tags() {
    global $db;
    try {
        $stmt = $db->query("SELECT * FROM tags ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function get_tag_by_slug($slug) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT * FROM tags WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

function get_articles_by_tag($tag_slug, $page = 1, $per_page = 12) {
    global $db;
    try {
        $offset = ($page - 1) * $per_page;
        $stmt = $db->prepare("
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            INNER JOIN article_tags at ON a.id = at.article_id
            INNER JOIN tags t ON at.tag_id = t.id
            WHERE t.slug = ? AND a.status = 'published' AND a.deleted_at IS NULL
            ORDER BY a.published_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$tag_slug, $per_page, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function get_tag_article_count($tag_id) {
    global $db;
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM articles a
            INNER JOIN article_tags at ON a.id = at.article_id
            WHERE at.tag_id = ? AND a.status = 'published' AND a.deleted_at IS NULL
        ");
        $stmt->execute([$tag_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return intval($result['count'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

function get_author_settings() {
    global $db;

    $defaults = [
        'avatar_url' => get_setting('author_avatar', ''),
        'name' => get_setting('author_name', '系统管理员'),
        'title' => 'GEO+AI内容系统运营者',
        'bio' => get_setting('author_bio', '专注于AI内容生成、自动化生产与内容管理系统建设。'),
        'location' => '中国',
        'website' => get_setting('author_website', ''),
        'email' => get_setting('social_email', get_setting('contact_email', '')),
        'github' => get_setting('social_github', ''),
        'twitter' => '',
        'linkedin' => '',
        'wechat' => '',
        'skills' => 'AI内容生成,SEO/GEO优化,自动化写作,内容运营',
        'frameworks' => 'PHP,Tailwind CSS,SQLite,任务调度',
        'tools' => 'OpenAI API,日志监控,内容审核,批量任务',
        'databases' => 'SQLite'
    ];

    try {
        $stmt = $db->prepare("SELECT * FROM author_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        return $settings ?: $defaults;
    } catch (Exception $e) {
        return $defaults;
    }
}

// 获取网站统计数据
function get_site_stats() {
    global $db;
    try {
        $stats = [];

        // 文章统计
        $stmt = $db->query("SELECT COUNT(*) as count FROM articles WHERE status = 'published' AND deleted_at IS NULL");
        $stats['articles'] = $stmt->fetch()['count'];

        // 分类统计
        $stmt = $db->query("SELECT COUNT(*) as count FROM categories");
        $stats['categories'] = $stmt->fetch()['count'];

        // 标签统计
        $stmt = $db->query("SELECT COUNT(*) as count FROM tags");
        $stats['tags'] = $stmt->fetch()['count'];

        // 总访问量
        $stmt = $db->query("SELECT SUM(view_count) as total_views FROM articles");
        $result = $stmt->fetch();
        $stats['total_views'] = $result['total_views'] ?: 0;

        $stats['total_articles'] = $stats['articles'];
        $stats['total_categories'] = $stats['categories'];
        $stats['total_tags'] = $stats['tags'];

        return $stats;
    } catch (Exception $e) {
        return [
            'articles' => 0,
            'categories' => 0,
            'tags' => 0,
            'total_views' => 0,
            'total_articles' => 0,
            'total_categories' => 0,
            'total_tags' => 0
        ];
    }
}

function get_reading_time($content) {
    $word_count = str_word_count(strip_tags($content));
    return max(1, ceil($word_count / 200)); // 假设每分钟200字
}

function generate_pagination($current_page, $total_pages, $base_url) {
    if ($total_pages <= 1) return '';
    
    $html = '<nav class="flex justify-center"><div class="flex space-x-2">';
    
    // 上一页
    if ($current_page > 1) {
        $prev_page = $current_page - 1;
        $html .= '<a href="' . $base_url . $prev_page . '" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">上一页</a>';
    }
    
    // 页码
    $start = max(1, $current_page - 2);
    $end = min($total_pages, $current_page + 2);
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $current_page) {
            $html .= '<span class="px-3 py-2 text-sm font-medium text-white bg-blue-600 border border-blue-600 rounded-md">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $base_url . $i . '" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">' . $i . '</a>';
        }
    }
    
    // 下一页
    if ($current_page < $total_pages) {
        $next_page = $current_page + 1;
        $html .= '<a href="' . $base_url . $next_page . '" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">下一页</a>';
    }
    
    $html .= '</div></nav>';
    return $html;
}

/**
 * Missing functions restored for SmartBI prototype
 */

// 增加文章点赞数
function increment_article_likes($id) {
    global $db;
    try {
        $stmt = $db->prepare("UPDATE articles SET like_count = like_count + 1 WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (Exception $e) {
        return false;
    }
}

// 获取相关推荐文章
function get_related_articles($article_id, $category_id, $limit = 4) {
    global $db;
    try {
        $stmt = $db->prepare("
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.category_id = ? AND a.id != ? AND a.status = 'published' AND a.deleted_at IS NULL
            ORDER BY a.view_count DESC, a.like_count DESC
            LIMIT ?
        ");
        $stmt->execute([$category_id, $article_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function render_markdown_inline($text) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $text = preg_replace_callback(
        '/!\[([^\]]*)\]\(([^)]+)\)/',
        static function ($matches) {
            $alt = $matches[1];
            $src = normalize_content_asset_url($matches[2]);
            if ($src === '') {
                return '';
            }

            return '<figure class="my-6"><img src="' . $src . '" alt="' . $alt . '" class="w-full h-auto rounded-xl border border-gray-200" loading="lazy"></figure>';
        },
        $text
    );

    $text = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)]+)\)/',
        static function ($matches) {
            $href = normalize_content_asset_url($matches[2]);
            return '<a href="' . $href . '" class="text-blue-600 hover:text-blue-800 underline" target="_blank" rel="noopener noreferrer">' . $matches[1] . '</a>';
        },
        $text
    );

    $text = preg_replace('/`([^`]+)`/', '<code class="bg-gray-100 px-1 py-0.5 rounded text-sm">$1</code>', $text);
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.*?)__/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text);
    $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '<em>$1</em>', $text);

    return $text;
}

function parse_markdown_table_cells(string $line): array {
    $normalized = trim($line);
    $normalized = preg_replace('/^\|/', '', $normalized);
    $normalized = preg_replace('/\|$/', '', $normalized);

    if ($normalized === '') {
        return [];
    }

    return array_map('trim', explode('|', $normalized));
}

function is_markdown_table_divider(array $cells): bool {
    if (empty($cells)) {
        return false;
    }

    foreach ($cells as $cell) {
        if ($cell === '' || !preg_match('/^:?-{3,}:?$/', $cell)) {
            return false;
        }
    }

    return true;
}

function render_markdown_table_html(array $headers, array $rows): string {
    if (empty($headers)) {
        return '';
    }

    $columnCount = count($headers);
    $thead = '<thead><tr>';
    foreach ($headers as $header) {
        $thead .= '<th>' . render_markdown_inline($header) . '</th>';
    }
    $thead .= '</tr></thead>';

    $tbody = '';
    if (!empty($rows)) {
        $tbody .= '<tbody>';
        foreach ($rows as $row) {
            if (count($row) < $columnCount) {
                $row = array_pad($row, $columnCount, '');
            } elseif (count($row) > $columnCount) {
                $row = array_slice($row, 0, $columnCount);
            }

            $tbody .= '<tr>';
            foreach ($row as $cell) {
                $tbody .= '<td>' . render_markdown_inline($cell) . '</td>';
            }
            $tbody .= '</tr>';
        }
        $tbody .= '</tbody>';
    }

    return '<div class="article-table-wrap"><table class="article-table">' . $thead . $tbody . '</table></div>';
}

function markdown_to_html($text) {
    if (empty($text)) {
        return '';
    }

    $lines = preg_split("/\r\n|\n|\r/", trim($text));
    $lineCount = count($lines);
    $html = [];
    $paragraph = [];
    $blockquote = [];
    $list_items = [];
    $list_type = null;

    $flush_paragraph = static function () use (&$paragraph, &$html) {
        if (empty($paragraph)) {
            return;
        }
        $content = implode('<br />', array_map('render_markdown_inline', $paragraph));
        $html[] = '<p class="my-4 leading-8 text-gray-700">' . $content . '</p>';
        $paragraph = [];
    };

    $flush_blockquote = static function () use (&$blockquote, &$html) {
        if (empty($blockquote)) {
            return;
        }
        $content = implode('<br />', array_map('render_markdown_inline', $blockquote));
        $html[] = '<blockquote class="my-6 rounded-xl border border-gray-200 bg-gray-50 px-5 py-4 text-gray-600 italic leading-7">' . $content . '</blockquote>';
        $blockquote = [];
    };

    $flush_list = static function () use (&$list_items, &$list_type, &$html) {
        if (empty($list_items) || $list_type === null) {
            return;
        }
        $tag = $list_type === 'ol' ? 'ol' : 'ul';
        $class = $list_type === 'ol'
            ? 'my-4 list-decimal space-y-2 pl-6 text-gray-700'
            : 'my-4 list-disc space-y-2 pl-6 text-gray-700';
        $html[] = '<' . $tag . ' class="' . $class . '"><li>' . implode('</li><li>', $list_items) . '</li></' . $tag . '>';
        $list_items = [];
        $list_type = null;
    };

    for ($index = 0; $index < $lineCount; $index++) {
        $line = $lines[$index];
        $trimmed = trim($line);

        if ($trimmed === '') {
            $flush_paragraph();
            $flush_blockquote();
            $flush_list();
            continue;
        }

        if (preg_match('/^#{1,3}\s+(.+)$/u', $trimmed, $matches)) {
            $flush_paragraph();
            $flush_blockquote();
            $flush_list();

            $level = strspn($trimmed, '#');
            $classes = [
                1 => 'text-2xl font-bold text-gray-900 mt-8 mb-4',
                2 => 'text-xl font-semibold text-gray-900 mt-8 mb-4',
                3 => 'text-lg font-semibold text-gray-900 mt-6 mb-3',
            ];
            $content = render_markdown_inline($matches[1]);
            $html[] = '<h' . $level . ' class="' . $classes[$level] . '">' . $content . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^---+$/', $trimmed)) {
            $flush_paragraph();
            $flush_blockquote();
            $flush_list();
            $html[] = '<hr class="my-8 border-gray-200">';
            continue;
        }

        if (str_contains($trimmed, '|') && $index + 1 < $lineCount) {
            $headerCells = parse_markdown_table_cells($trimmed);
            $dividerCells = parse_markdown_table_cells(trim($lines[$index + 1]));

            if (count($headerCells) >= 2
                && count($dividerCells) === count($headerCells)
                && is_markdown_table_divider($dividerCells)
            ) {
                $flush_paragraph();
                $flush_blockquote();
                $flush_list();

                $rows = [];
                $index += 2;

                while ($index < $lineCount) {
                    $rowLine = trim($lines[$index]);
                    if ($rowLine === '' || !str_contains($rowLine, '|')) {
                        $index--;
                        break;
                    }

                    $rowCells = parse_markdown_table_cells($rowLine);
                    if (empty($rowCells) || is_markdown_table_divider($rowCells)) {
                        $index--;
                        break;
                    }

                    $rows[] = $rowCells;
                    $index++;
                }

                $html[] = render_markdown_table_html($headerCells, $rows);
                continue;
            }
        }

        if (preg_match('/^>\s?(.*)$/u', $trimmed, $matches)) {
            $flush_paragraph();
            $flush_list();
            $blockquote[] = $matches[1];
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $matches)) {
            $flush_paragraph();
            $flush_blockquote();
            if ($list_type !== 'ul') {
                $flush_list();
                $list_type = 'ul';
            }
            $list_items[] = render_markdown_inline($matches[1]);
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/u', $trimmed, $matches)) {
            $flush_paragraph();
            $flush_blockquote();
            if ($list_type !== 'ol') {
                $flush_list();
                $list_type = 'ol';
            }
            $list_items[] = render_markdown_inline($matches[1]);
            continue;
        }

        if (preg_match('/^!\[[^\]]*\]\(([^)]+)\)$/', $trimmed)) {
            $flush_paragraph();
            $flush_blockquote();
            $flush_list();
            $html[] = render_markdown_inline($trimmed);
            continue;
        }

        $flush_blockquote();
        $flush_list();
        $paragraph[] = $trimmed;
    }

    $flush_paragraph();
    $flush_blockquote();
    $flush_list();

    return implode("\n", $html);
}
?>
