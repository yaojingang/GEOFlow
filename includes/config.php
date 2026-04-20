<?php
/**
 * GEO+AI内容生成系统 - 配置文件
 * 
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-03
 */

// 防止直接访问
if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function env_value($key, $default = null) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

require_once __DIR__ . '/db_support.php';
require_once __DIR__ . '/i18n.php';

// 网站基本配置
define('SITE_NAME', env_value('SITE_NAME', '智能GEO内容系统'));
define('SITE_FULL_NAME', env_value('SITE_FULL_NAME', 'GEO+AI内容生成系统'));
define('SITE_URL', env_value('SITE_URL', 'http://localhost'));
define('SITE_DESCRIPTION', env_value('SITE_DESCRIPTION', 'AI驱动的智能内容生成与发布平台，支持自动化文章创作、SEO优化和内容管理'));
define('SITE_KEYWORDS', env_value('SITE_KEYWORDS', 'AI内容生成,自动化写作,SEO优化,内容管理,人工智能,机器学习'));
define('ADMIN_BASE_PATH', '/' . trim(env_value('ADMIN_BASE_PATH', 'geo_admin'), '/'));
define('APP_VERSION', env_value('APP_VERSION', '1.2.0'));
define('APP_VERSION_DATE', env_value('APP_VERSION_DATE', '2026-04-20'));
define('APP_UPDATE_METADATA_URL', env_value('APP_UPDATE_METADATA_URL', 'https://raw.githubusercontent.com/yaojingang/GEOFlow/main/version.json'));

// SQLite 源库路径，仅用于迁移和兼容维护脚本
define('DB_PATH', db_get_sqlite_path());

// 管理员配置
define('ADMIN_USERNAME', 'admin');
// 使用更安全的password_hash，默认密码：admin888
define('ADMIN_PASSWORD', '$2y$12$1OOZUqzYbO4XRjf9XKx0luP5MMHS8d0Cpl8pIZ9YzhBCOEnsFbgrm'); // admin888

// 分页配置
define('ITEMS_PER_PAGE', 12);
define('ADMIN_ITEMS_PER_PAGE', 20);

// 上传配置
define('UPLOAD_PATH', __DIR__ . '/../assets/images/');
define('UPLOAD_URL', '/assets/images/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2MB

// 缓存配置
define('CACHE_ENABLED', true);
define('CACHE_TIME', 3600); // 1小时

// 安全配置
define('SESSION_NAME', 'blog_secure_session');
define('CSRF_TOKEN_NAME', 'csrf_token');
define('SECRET_KEY', 'your-secret-key-change-this-in-production');
define('APP_SECRET_KEY', env_value('APP_SECRET_KEY', SECRET_KEY));
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15分钟
define('SESSION_TIMEOUT', 3600); // 1小时

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 错误报告设置
if (filter_var(env_value('DEBUG', defined('DEBUG') ? DEBUG : false), FILTER_VALIDATE_BOOLEAN)) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// 网站设置默认值
$default_settings = [
    'site_title' => 'GEO+AI内容生成系统',
    'site_description' => 'AI驱动的智能内容生成与发布平台，支持自动化文章创作、SEO优化和内容管理',
    'site_keywords' => 'AI内容生成,自动化写作,SEO优化,内容管理,人工智能,机器学习',
    'site_logo' => '',
    'site_favicon' => '',
    'copyright_text' => '© 2025 GEO+AI内容生成系统. All rights reserved.',
    'contact_email' => 'admin@example.com',
    'analytics_code' => '',
    'featured_limit' => 6,
    'per_page' => ITEMS_PER_PAGE,
    'featured_description' => 'AI驱动的智能内容生成平台',
    'author_name' => '系统管理员',
    'author_bio' => 'GEO+AI内容生成系统管理员，专注于AI驱动的内容创作和SEO优化。',
    'author_avatar' => '/assets/images/avatar.jpg',
    'author_website' => '',
    'social_github' => '',
    'social_email' => 'admin@example.com',
    // AI系统相关设置
    'ai_generation_enabled' => 1,
    'auto_publish_enabled' => 1,
    'content_review_required' => 1,
    'sensitive_word_check' => 1,
    'max_daily_generation' => 50,
    'default_ai_model' => 1,
    'default_embedding_model_id' => 0
];

function normalize_setting_key($key) {
    $key = trim((string) $key);

    $map = [
        'site_title' => 'site_name',
        'copyright_text' => 'copyright_info'
    ];

    return $map[$key] ?? $key;
}

function get_app_encryption_key() {
    return hash('sha256', (string) APP_SECRET_KEY, true);
}

function get_legacy_encryption_keys() {
    $keys = [get_app_encryption_key()];
    $legacy_candidates = [
        'your-secret-key-change-this-in-production'
    ];

    foreach ($legacy_candidates as $candidate) {
        $derived = hash('sha256', (string) $candidate, true);
        $exists = false;
        foreach ($keys as $key) {
            if (hash_equals($key, $derived)) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $keys[] = $derived;
        }
    }

    return $keys;
}

function is_encrypted_value($value) {
    return is_string($value) && str_starts_with($value, 'enc:v1:');
}

function encrypt_sensitive_value($plaintext) {
    $plaintext = (string) $plaintext;
    if ($plaintext === '') {
        return '';
    }

    if (is_encrypted_value($plaintext)) {
        return $plaintext;
    }

    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', get_app_encryption_key(), OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        return $plaintext;
    }

    return 'enc:v1:' . base64_encode($iv . $ciphertext);
}

function decrypt_sensitive_value($stored_value) {
    $stored_value = (string) $stored_value;
    if ($stored_value === '') {
        return '';
    }

    if (!is_encrypted_value($stored_value)) {
        return $stored_value;
    }

    $payload = base64_decode(substr($stored_value, 7), true);
    if ($payload === false || strlen($payload) <= 16) {
        return '';
    }

    $iv = substr($payload, 0, 16);
    $ciphertext = substr($payload, 16);

    foreach (get_legacy_encryption_keys() as $key) {
        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext !== false) {
            return $plaintext;
        }
    }

    return '';
}

function encrypt_ai_api_key($api_key) {
    return encrypt_sensitive_value($api_key);
}

function decrypt_ai_api_key($stored_api_key) {
    return decrypt_sensitive_value($stored_api_key);
}

function apply_curl_network_defaults($ch) {
    if (!is_resource($ch) && !($ch instanceof CurlHandle)) {
        return;
    }

    curl_setopt($ch, CURLOPT_PROXY, '');
    curl_setopt($ch, CURLOPT_NOPROXY, '*');
}

function apply_ai_curl_request_defaults($ch, int $timeoutSeconds = 180, int $connectTimeoutSeconds = 10): void {
    if (!is_resource($ch) && !($ch instanceof CurlHandle)) {
        return;
    }

    apply_curl_network_defaults($ch);

    curl_setopt($ch, CURLOPT_TIMEOUT, max(30, $timeoutSeconds));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(5, min($connectTimeoutSeconds, $timeoutSeconds)));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GEO-Content-System/1.0');
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

    // Disable "low speed" aborts so reasoning models can stay silent while thinking.
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 0);
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 0);
}

function migrate_ai_model_api_keys($database) {
    if (!$database instanceof PDO) {
        return 0;
    }

    $stmt = $database->query("SELECT id, api_key FROM ai_models");
    $models = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (empty($models)) {
        return 0;
    }

    $updated = 0;
    $update_stmt = $database->prepare("UPDATE ai_models SET api_key = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");

    foreach ($models as $model) {
        $api_key = $model['api_key'] ?? '';
        if ($api_key === '') {
            continue;
        }

        $plaintext = decrypt_ai_api_key($api_key);
        if ($plaintext === '') {
            continue;
        }

        $encrypted_key = encrypt_ai_api_key($plaintext);

        if ($encrypted_key === '' || $encrypted_key === $api_key) {
            continue;
        }

        if ($update_stmt->execute([$encrypted_key, $model['id']])) {
            $updated++;
        }
    }

    return $updated;
}

// 获取网站设置
function get_setting($key, $default = '') {
    global $db;
    if (!$db) return $default;

    $normalized_key = normalize_setting_key($key);

    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$normalized_key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['setting_value'] !== '') {
            return $result['setting_value'];
        }

        $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// 设置网站配置
function set_setting($key, $value) {
    global $db;
    if (!$db) return false;

    $normalized_key = normalize_setting_key($key);

    try {
        $stmt = $db->prepare("
            UPDATE site_settings
            SET setting_value = ?, updated_at = CURRENT_TIMESTAMP
            WHERE setting_key = ?
        ");
        $stmt->execute([$value, $normalized_key]);

        if ($stmt->rowCount() === 0) {
            $stmt = $db->prepare("
                INSERT INTO site_settings (setting_key, setting_value, updated_at)
                VALUES (?, ?, CURRENT_TIMESTAMP)
            ");
            return $stmt->execute([$normalized_key, $value]);
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

// URL重写规则（用于伪静态）
$url_rules = [
    '/^category\/(\d+)$/' => 'index.php?category=$1',
    '/^search\/(.+)$/' => 'index.php?search=$1'
];

// 安全函数
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

function generate_csrf_token() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verify_csrf_token($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

// 响应函数
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect($url, $permanent = false) {
    $status = $permanent ? 301 : 302;
    http_response_code($status);
    header("Location: $url");
    exit;
}

function admin_url($path = '') {
    $base = rtrim(ADMIN_BASE_PATH, '/');
    $path = ltrim((string) $path, '/');

    if ($path === '') {
        return $base . '/';
    }

    return $base . '/' . $path;
}

function admin_redirect($path = '', $permanent = false) {
    redirect(admin_url($path), $permanent);
}

// 格式化函数
function format_number($number) {
    if ($number >= 1000000) {
        return round($number / 1000000, 1) . 'M';
    } elseif ($number >= 1000) {
        return round($number / 1000, 1) . 'K';
    }
    return $number;
}

function time_ago($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return '刚刚';
    if ($time < 3600) return floor($time / 60) . '分钟前';
    if ($time < 86400) return floor($time / 3600) . '小时前';
    if ($time < 2592000) return floor($time / 86400) . '天前';
    if ($time < 31536000) return floor($time / 2592000) . '个月前';
    
    return floor($time / 31536000) . '年前';
}

// 日志函数
function write_log($message, $level = 'INFO') {
    $log_file = __DIR__ . '/../logs/' . date('Y-m-d') . '.log';
    $log_dir = dirname($log_file);
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
}
