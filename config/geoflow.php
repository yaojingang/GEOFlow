<?php

/**
 * GEOFlow 业务相关配置（站点信息、后台路径、上传、缓存、会话与安全）。
 *
 * 环境变量键名与默认值见各条目旁注释；修改后建议 `php artisan config:clear`。
 */
$adminBasePath = trim((string) env('ADMIN_BASE_PATH', 'geo_admin'), '/');
$adminBasePath = $adminBasePath !== '' ? $adminBasePath : 'geo_admin';
$defaultUpdateMetadataUrl = 'https://raw.githubusercontent.com/yaojingang/GEOFlow/main/version.json';
$updateMetadataUrl = trim((string) env('GEOFLOW_UPDATE_METADATA_URL', $defaultUpdateMetadataUrl));
$updateMetadataUrl = $updateMetadataUrl !== '' ? $updateMetadataUrl : $defaultUpdateMetadataUrl;

return [

    // 站点展示名称（页眉、标题等）
    'site_name' => env('SITE_NAME', 'GEOFlow'),
    // 站点完整/副标题文案
    'site_full_name' => env('SITE_FULL_NAME', 'GEOFlow'),
    // 站点根 URL，用于生成绝对链接（末尾无斜杠）
    'site_url' => rtrim((string) env('SITE_URL', 'http://localhost'), '/'),
    // SEO 描述
    'site_description' => env('SITE_DESCRIPTION', ''),
    // SEO 关键词（逗号分隔等，依前端使用方式）
    'site_keywords' => env('SITE_KEYWORDS', ''),

    // 后台入口路径前缀，如 /geo_admin（勿与前台路由冲突）
    'admin_base_path' => '/'.$adminBasePath,

    // 前台 Blade 使用的 Laravel 翻译 locale（与 APP_LOCALE、后台会话语言独立；对齐旧站中文导航）
    'public_locale' => env('GEOFLOW_PUBLIC_LOCALE', 'zh_CN'),
    // 默认前台主题；后台未显式选择主题时使用
    'default_theme' => env('GEOFLOW_DEFAULT_THEME', 'toutiao-news-20260426'),

    // 当前系统版本（底部展示、GitHub 更新检查对比）
    'app_version' => env('GEOFLOW_APP_VERSION', '1.2.0'),
    // 欢迎弹窗「介绍」文案版本：变更后所有管理员会再次看到介绍弹窗
    'welcome_intro_version' => env('GEOFLOW_WELCOME_INTRO_VERSION', '1.2.0'),
    // GitHub version.json 地址；默认每天检查一次，可通过 GEOFLOW_UPDATE_CHECK_ENABLED=false 关闭
    'update_check_enabled' => filter_var(env('GEOFLOW_UPDATE_CHECK_ENABLED', env('APP_ENV') !== 'testing'), FILTER_VALIDATE_BOOLEAN),
    'update_metadata_url' => $updateMetadataUrl,
    'update_metadata_cache_ttl_seconds' => (int) env('GEOFLOW_UPDATE_METADATA_CACHE_TTL', 86400),

    // 前台列表每页条数
    'items_per_page' => (int) env('GEOFLOW_ITEMS_PER_PAGE', 12),
    // 后台列表每页条数
    'admin_items_per_page' => (int) env('GEOFLOW_ADMIN_ITEMS_PER_PAGE', 20),
    // 标题库 AI 生成时从关键词库随机抽取的最大条数（1–100）
    'title_ai_keyword_sample_limit' => max(1, min(100, (int) env('GEOFLOW_TITLE_AI_KEYWORD_SAMPLE_LIMIT', 10))),
    // 为 true 时记录知识库「查询向量」是否由默认 embedding 接口生成（便于对照 bak 验证；默认关闭）
    'debug_knowledge_query_embedding' => filter_var(env('GEOFLOW_DEBUG_KNOWLEDGE_QUERY_EMBEDDING', false), FILTER_VALIDATE_BOOLEAN),

    // 本地上传根目录（绝对路径）
    'upload_path' => env('GEOFLOW_UPLOAD_PATH', public_path('assets/images')),
    // 上传资源对外访问 URL 前缀
    'upload_url' => env('GEOFLOW_UPLOAD_URL', '/assets/images/'),
    // 单文件上传最大字节数
    'max_upload_bytes' => (int) env('GEOFLOW_MAX_UPLOAD_BYTES', 2 * 1024 * 1024),

    // 是否启用 GEOFlow 业务层缓存
    'cache_enabled' => filter_var(env('GEOFLOW_CACHE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    // 业务缓存 TTL（秒）
    'cache_ttl_seconds' => (int) env('GEOFLOW_CACHE_TTL', 3600),

    // 遗留会话 Cookie 名（与 bak 对齐时可改）
    'session_name' => env('GEOFLOW_SESSION_NAME', 'blog_secure_session'),
    // CSRF 隐藏字段/input 名
    'csrf_token_name' => env('GEOFLOW_CSRF_TOKEN_NAME', 'csrf_token'),

    // ai_models API Key enc:v1 根材料（仅在此读取 APP_KEY；应用代码禁止 env()，统一 config('geoflow.api_key_crypto_roots')）
    'api_key_crypto_roots' => array_values(array_filter([(string) env('APP_KEY', '')])),

    // 登录失败锁定前允许尝试次数
    'max_login_attempts' => (int) env('GEOFLOW_MAX_LOGIN_ATTEMPTS', 5),
    // 超出次数后锁定时长（秒）
    'login_lockout_seconds' => (int) env('GEOFLOW_LOGIN_LOCKOUT_SECONDS', 900),
    // 会话空闲超时（秒）
    'session_timeout_seconds' => (int) env('GEOFLOW_SESSION_TIMEOUT', 3600),

];
