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
$versionManifestPath = __DIR__.'/../version.json';
$versionManifest = is_file($versionManifestPath)
    ? json_decode((string) file_get_contents($versionManifestPath), true)
    : [];
$appVersion = is_array($versionManifest) ? trim((string) ($versionManifest['version'] ?? '')) : '';
$appVersion = $appVersion !== '' ? $appVersion : '0.0.0-dev';
$defaultAnalyticsCode = <<<'HTML'
<script>
var _hmt = _hmt || [];
(function() {
  var hm = document.createElement("script");
  hm.src = "https://hm.baidu.com/hm.js?1743638f313788caa4cb55e299444a87";
  var s = document.getElementsByTagName("script")[0];
  s.parentNode.insertBefore(hm, s);
})();
</script>
HTML;

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
    'default_theme' => env('GEOFLOW_DEFAULT_THEME', 'geoflow-template-21-enterprise-signature'),
    // 仅在空库首次安装时写入；升级、重复安装和已有站点设置都不会覆盖。
    'default_analytics_code' => $defaultAnalyticsCode,
    // 是否在手动 db:seed 中导入前台参考内容。geoflow:install 仅在全新空库默认导入。
    'seed_frontend_demo' => filter_var(env('GEOFLOW_SEED_FRONTEND_DEMO', false), FILTER_VALIDATE_BOOLEAN),
    // 参考内容默认只补缺，不覆盖用户已修改的作者、分类和文章；仅重置演示库时显式开启覆盖。
    'seed_frontend_demo_overwrite' => filter_var(env('GEOFLOW_SEED_FRONTEND_DEMO_OVERWRITE', false), FILTER_VALIDATE_BOOLEAN),

    // 当前系统版本（底部展示、GitHub 更新检查对比）；默认跟随本地 version.json，避免已部署 .env 锁死版本号。
    'app_version' => $appVersion,
    // 首次部署登录页初始管理员提示；只展示账号与初始化日志指引，永远不展示密码。
    'initial_admin_hint_enabled' => filter_var(env('GEOFLOW_INITIAL_ADMIN_HINT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'initial_admin_username' => trim((string) env('GEOFLOW_ADMIN_USERNAME', 'admin')) ?: 'admin',
    'initial_admin_email' => trim((string) env('GEOFLOW_ADMIN_EMAIL', 'admin@example.com')) ?: 'admin@example.com',
    'initial_admin_password' => (string) env('GEOFLOW_ADMIN_PASSWORD', ''),
    // 欢迎弹窗「介绍」文案版本：变更后所有管理员会再次看到介绍弹窗
    'welcome_intro_version' => env('GEOFLOW_WELCOME_INTRO_VERSION', '2.1'),
    // 匿名使用统计：服务端发送随机实例 ID、版本和生命周期事件，后台 Pulse 额外发送管理员匿名摘要。
    'telemetry_enabled' => filter_var(env('GEOFLOW_TELEMETRY_ENABLED', env('APP_ENV') === 'production'), FILTER_VALIDATE_BOOLEAN),
    'telemetry_endpoint' => trim((string) env(
        'GEOFLOW_TELEMETRY_ENDPOINT',
        'https://geoflow-telemetry-gateway.pages.dev/api/pulse',
    )),
    'telemetry_interval_seconds' => max(3600, (int) env('GEOFLOW_TELEMETRY_INTERVAL_SECONDS', 86400)),
    // GitHub version.json 地址；默认每天检查一次，可通过 GEOFLOW_UPDATE_CHECK_ENABLED=false 关闭
    'update_check_enabled' => filter_var(env('GEOFLOW_UPDATE_CHECK_ENABLED', env('APP_ENV') !== 'testing'), FILTER_VALIDATE_BOOLEAN),
    'update_metadata_url' => $updateMetadataUrl,
    'update_metadata_cache_ttl_seconds' => (int) env('GEOFLOW_UPDATE_METADATA_CACHE_TTL', 86400),
    // 后台系统更新中心：默认可查看和备份，真正执行代码更新默认关闭。
    'update_center_enabled' => filter_var(env('GEOFLOW_UPDATE_CENTER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'update_execution_enabled' => filter_var(env('GEOFLOW_UPDATE_EXECUTION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'update_rollback_enabled' => filter_var(env('GEOFLOW_UPDATE_ROLLBACK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'update_backup_keep' => max(1, (int) env('GEOFLOW_UPDATE_BACKUP_KEEP', 10)),
    'update_backup_path' => trim((string) env('GEOFLOW_UPDATE_BACKUP_PATH', 'geoflow-updates'), '/'),
    'update_allowed_repository' => trim((string) env('GEOFLOW_UPDATE_ALLOWED_REPOSITORY', 'https://github.com/yaojingang/GEOFlow'), '/'),
    'update_archive_max_bytes' => max(1, (int) env('GEOFLOW_UPDATE_ARCHIVE_MAX_BYTES', 50 * 1024 * 1024)),
    'update_archive_max_files' => max(1, (int) env('GEOFLOW_UPDATE_ARCHIVE_MAX_FILES', 2000)),
    'update_archive_max_file_bytes' => max(1, (int) env('GEOFLOW_UPDATE_ARCHIVE_MAX_FILE_BYTES', 50 * 1024 * 1024)),
    'update_archive_max_uncompressed_bytes' => max(1, (int) env('GEOFLOW_UPDATE_ARCHIVE_MAX_UNCOMPRESSED_BYTES', 150 * 1024 * 1024)),
    'update_min_free_disk_bytes' => max(1, (int) env('GEOFLOW_UPDATE_MIN_FREE_DISK_BYTES', 200 * 1024 * 1024)),
    'update_preflight_check_git_dirty' => filter_var(env('GEOFLOW_UPDATE_PREFLIGHT_CHECK_GIT_DIRTY', true), FILTER_VALIDATE_BOOLEAN),
    'update_require_admin_password' => filter_var(env('GEOFLOW_UPDATE_REQUIRE_ADMIN_PASSWORD', true), FILTER_VALIDATE_BOOLEAN),
    'update_archive_apply_enabled' => filter_var(env('GEOFLOW_UPDATE_ALLOW_ARCHIVE_APPLY', false), FILTER_VALIDATE_BOOLEAN),
    'update_database_backup_enabled' => filter_var(env('GEOFLOW_UPDATE_DATABASE_BACKUP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'update_lock_ttl_seconds' => max(30, (int) env('GEOFLOW_UPDATE_LOCK_TTL', 900)),
    // 系统更新任务超过该时间仍处于 queued/running 时，在更新中心提示为可能卡住。
    'update_run_stale_minutes' => max(1, (int) env('GEOFLOW_UPDATE_RUN_STALE_MINUTES', 15)),

    // 复刻主题审查包的资源上限。
    'theme_replication_package_max_files' => max(1, (int) env('GEOFLOW_THEME_REPLICATION_PACKAGE_MAX_FILES', 500)),
    'theme_replication_package_max_file_bytes' => max(1, (int) env('GEOFLOW_THEME_REPLICATION_PACKAGE_MAX_FILE_BYTES', 5 * 1024 * 1024)),
    'theme_replication_package_max_total_bytes' => max(1, (int) env('GEOFLOW_THEME_REPLICATION_PACKAGE_MAX_TOTAL_BYTES', 25 * 1024 * 1024)),
    'theme_replication_package_lock_timeout_milliseconds' => max(1, (int) env('GEOFLOW_THEME_REPLICATION_PACKAGE_LOCK_TIMEOUT_MS', 5000)),

    // 前台列表每页条数
    'items_per_page' => (int) env('GEOFLOW_ITEMS_PER_PAGE', 12),
    // 后台列表每页条数
    'admin_items_per_page' => (int) env('GEOFLOW_ADMIN_ITEMS_PER_PAGE', 20),
    // 标题库 AI 生成时从关键词库随机抽取的最大条数（1–100）
    'title_ai_keyword_sample_limit' => max(1, min(100, (int) env('GEOFLOW_TITLE_AI_KEYWORD_SAMPLE_LIMIT', 10))),
    // 统一出站安全网关：仅此处列出的精确 host:port 可连接私网地址；不支持通配符或路径。
    'outbound_private_targets' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEOFLOW_OUTBOUND_PRIVATE_TARGETS', ''))), static fn (string $target): bool => $target !== '')),
    'outbound_json_max_bytes' => max(1, (int) env('GEOFLOW_OUTBOUND_JSON_MAX_BYTES', 4 * 1024 * 1024)),
    'outbound_ai_max_bytes' => max(1, (int) env('GEOFLOW_OUTBOUND_AI_MAX_BYTES', 8 * 1024 * 1024)),
    'outbound_import_max_bytes' => max(1, (int) env('GEOFLOW_OUTBOUND_IMPORT_MAX_BYTES', 5 * 1024 * 1024)),
    'outbound_metadata_max_bytes' => max(1, (int) env('GEOFLOW_OUTBOUND_METADATA_MAX_BYTES', 1024 * 1024)),
    // 为 true 时记录知识库「查询向量」是否由默认 embedding 接口生成（便于对照 bak 验证；默认关闭）
    'debug_knowledge_query_embedding' => filter_var(env('GEOFLOW_DEBUG_KNOWLEDGE_QUERY_EMBEDDING', false), FILTER_VALIDATE_BOOLEAN),
    // 语义切片规划 prompt 最大字符数；超过后直接走结构化规则回退，避免长知识库拖慢或超上下文。
    'semantic_chunking_max_chars' => max(1, (int) env('GEOFLOW_SEMANTIC_CHUNKING_MAX_CHARS', 20000)),
    // Embedding 文档向量化单次请求切片数；部分供应商限制 batch 较小，默认保守拆分。
    'embedding_batch_size' => max(1, min(64, (int) env('GEOFLOW_EMBEDDING_BATCH_SIZE', 1))),
    // 单个知识向量化 Job 最多处理的切片数，控制队列进程峰值内存。
    'knowledge_embedding_job_size' => max(1, min(32, (int) env('GEOFLOW_KNOWLEDGE_EMBEDDING_JOB_SIZE', 32))),
    // Worker 心跳超过该秒数未更新时，任务页标记为 stale。
    'worker_stale_seconds' => max(30, (int) env('GEOFLOW_WORKER_STALE_SECONDS', 120)),
    // 正文生成默认最大输出 token 数；当 AI 模型未单独配置 max_tokens 时使用此兜底值，
    // 避免依赖各服务商较小的默认上限（常见 4K）导致长文被截断。
    'content_max_tokens' => max(256, (int) env('GEOFLOW_CONTENT_MAX_TOKENS', 8192)),
    // AI 可见性查询底层能力：豆包 Ark Responses、豆包 Search Custom、DeepSeek 二次分析共用。
    'ai_visibility' => [
        'http_timeout_seconds' => max(5, (int) env('GEOFLOW_AI_VISIBILITY_HTTP_TIMEOUT', 60)),
        'http_connect_timeout_seconds' => max(1, (int) env('GEOFLOW_AI_VISIBILITY_CONNECT_TIMEOUT', 10)),
        'http_retry_attempts' => max(1, (int) env('GEOFLOW_AI_VISIBILITY_HTTP_RETRY_ATTEMPTS', 2)),
        'http_retry_sleep_ms' => max(0, (int) env('GEOFLOW_AI_VISIBILITY_HTTP_RETRY_SLEEP_MS', 300)),
        'doubao_search_endpoint' => env('GEOFLOW_DOUBAO_SEARCH_ENDPOINT', 'https://open.feedcoopapi.com/search_api/web_search'),
        'ark_responses_path' => env('GEOFLOW_ARK_RESPONSES_PATH', '/responses'),
        'default_search_count' => max(1, min(20, (int) env('GEOFLOW_AI_VISIBILITY_SEARCH_COUNT', 10))),
        'default_analysis_max_tokens' => max(512, (int) env('GEOFLOW_AI_VISIBILITY_ANALYSIS_MAX_TOKENS', 4096)),
    ],

    // 本地上传根目录（绝对路径）
    'upload_path' => env('GEOFLOW_UPLOAD_PATH', public_path('assets/images')),
    // 上传资源对外访问 URL 前缀
    'upload_url' => env('GEOFLOW_UPLOAD_URL', '/assets/images/'),
    // 单文件上传最大字节数
    'max_upload_bytes' => (int) env('GEOFLOW_MAX_UPLOAD_BYTES', 2 * 1024 * 1024),
    // 兼容旧客户端直接提交已存在图片路径；默认关闭，建议使用 multipart 上传。
    'legacy_image_path_input' => filter_var(env('GEOFLOW_LEGACY_IMAGE_PATH_INPUT', false), FILTER_VALIDATE_BOOLEAN),
    // 升级门禁：确认旧 worker 已全部退出且图片路径哈希回填完成后，才允许物理文件删除。
    'managed_image_deletion_enabled' => filter_var(env('GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    // 删除准备完成后短时间内仍未收敛的记录视为 stale，便于发现崩溃或中断。
    'security_audit_deleting_stale_minutes' => max(1, (int) env('GEOFLOW_SECURITY_AUDIT_DELETING_STALE_MINUTES', 15)),
    // present 且长期没有图片引用的注册表记录才视为 orphan，避免误报正常上传窗口。
    'security_audit_orphan_age_hours' => max(1, (int) env('GEOFLOW_SECURITY_AUDIT_ORPHAN_AGE_HOURS', 24)),

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
    // 后台“记住我”凭证有效期（分钟），默认 30 天
    'admin_remember_minutes' => max(1, (int) env('GEOFLOW_ADMIN_REMEMBER_MINUTES', 43200)),
    // API 登录限速：同一账号/IP 在窗口期内最多尝试次数
    'api_login_rate_limit_attempts' => (int) env('GEOFLOW_API_LOGIN_RATE_LIMIT_ATTEMPTS', 10),
    // API 登录限速窗口（秒）
    'api_login_rate_limit_decay_seconds' => (int) env('GEOFLOW_API_LOGIN_RATE_LIMIT_DECAY', 60),
    // API Token 默认有效期（天）
    'api_token_default_ttl_days' => (int) env('GEOFLOW_API_TOKEN_DEFAULT_TTL_DAYS', 30),
    // 会话空闲超时（秒）
    'session_timeout_seconds' => (int) env('GEOFLOW_SESSION_TIMEOUT', 2592000),

];
