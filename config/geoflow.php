<?php

/**
 * GEOFlow 业务相关配置（站点信息、后台路径、上传、缓存、会话与安全）。
 *
 * 环境变量键名与默认值见各条目旁注释；修改后建议 `php artisan config:clear`。
 */
$adminBasePath = trim((string) env('ADMIN_BASE_PATH', 'geo_admin'), '/');
$adminBasePath = $adminBasePath !== '' ? $adminBasePath : 'geo_admin';
$defaultUpdateMetadataUrl = 'https://github.com/yaojingang/GEOFlow/releases/latest/download/version.json';
$updateMetadataUrl = trim((string) env('GEOFLOW_UPDATE_METADATA_URL', $defaultUpdateMetadataUrl));
$updateMetadataUrl = $updateMetadataUrl !== '' ? $updateMetadataUrl : $defaultUpdateMetadataUrl;
$telemetryEndpoint = trim((string) env('GEOFLOW_TELEMETRY_ENDPOINT', ''));
$versionManifestPath = __DIR__.'/../version.json';
$versionManifest = is_file($versionManifestPath)
    ? json_decode((string) file_get_contents($versionManifestPath), true)
    : [];
$appVersion = is_array($versionManifest) ? trim((string) ($versionManifest['version'] ?? '')) : '';
$appVersion = $appVersion !== '' ? $appVersion : '0.0.0-dev';
$normalizeHosts = static function (array $hosts): array {
    $normalized = [];
    foreach ($hosts as $host) {
        $host = strtolower(rtrim(trim((string) $host), '.'));
        if ($host !== ''
            && preg_match('/^[a-z0-9.-]+$/', $host) === 1
            && ! str_contains($host, '..')
            && ! in_array($host, $normalized, true)) {
            $normalized[] = $host;
        }
    }

    return $normalized;
};
$rawConfiguredPrimaryHosts = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('GEOFLOW_PRIMARY_HOSTS', ''))
)));
$configuredPrimaryHosts = $rawConfiguredPrimaryHosts;
$configuredPrimaryHosts[] = (string) parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
$configuredPrimaryHosts[] = (string) parse_url((string) env('SITE_URL', 'http://localhost'), PHP_URL_HOST);
$rawHostedRootDomains = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('GEOFLOW_HOSTED_SITE_ROOT_DOMAINS', ''))
)));
$hostedRootDomains = $normalizeHosts($rawHostedRootDomains);
$normalizedPrimaryHostCandidates = $normalizeHosts($configuredPrimaryHosts);
$primaryHosts = array_values(array_filter(
    array_diff($normalizedPrimaryHostCandidates, $hostedRootDomains),
    static function (string $hostname) use ($hostedRootDomains): bool {
        foreach ($hostedRootDomains as $rootDomain) {
            if (str_ends_with($hostname, '.'.$rootDomain)) {
                return false;
            }
        }

        return true;
    }
));
$configurationErrors = [];
if (count($hostedRootDomains) !== count(array_unique(array_map('strtolower', $rawHostedRootDomains)))) {
    $configurationErrors[] = 'GEOFLOW_HOSTED_SITE_ROOT_DOMAINS contains an invalid or duplicate hostname.';
}
if (count($normalizeHosts($rawConfiguredPrimaryHosts)) !== count(array_unique(array_map('strtolower', $rawConfiguredPrimaryHosts)))) {
    $configurationErrors[] = 'GEOFLOW_PRIMARY_HOSTS contains an invalid or duplicate hostname.';
}
if (array_diff($normalizedPrimaryHostCandidates, $primaryHosts) !== []) {
    $configurationErrors[] = 'A primary host overlaps a hosted root domain or one of its subdomains.';
}

return [

    // Admin UI V3 公共壳层。默认关闭，仅在独立 UI V3 环境显式开启。
    'admin_ui_v3_enabled' => filter_var(env('GEOFLOW_ADMIN_UI_V3_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // 站点展示名称（页眉、标题等）
    'site_name' => env('SITE_NAME', 'GEOFlow'),
    // 站点完整/副标题文案
    'site_full_name' => env('SITE_FULL_NAME', 'GEOFlow'),
    // 站点根 URL，用于生成绝对链接（末尾无斜杠）
    'site_url' => rtrim((string) env('SITE_URL', 'http://localhost'), '/'),
    'knowledge_fact_generation_max_per_run' => 200,
    'knowledge_fact_generation_batch_size' => 25,
    'knowledge_fact_generation_rate_per_minute' => max(1, min(600, (int) env('GEOFLOW_KNOWLEDGE_FACT_GENERATION_RATE_PER_MINUTE', 10))),
    'knowledge_fact_generation_max_batch_attempts' => max(1, min(10, (int) env('GEOFLOW_KNOWLEDGE_FACT_GENERATION_MAX_BATCH_ATTEMPTS', 3))),
    'knowledge_fact_generation_batch_lease_seconds' => max(180, min(600, (int) env('GEOFLOW_KNOWLEDGE_FACT_GENERATION_BATCH_LEASE_SECONDS', 210))),
    'knowledge_fact_generation_max_recovery_attempts' => max(1, min(10, (int) env('GEOFLOW_KNOWLEDGE_FACT_GENERATION_MAX_RECOVERY_ATTEMPTS', 3))),
    'knowledge_fact_generation_recovery_stale_seconds' => max(60, min(3600, (int) env('GEOFLOW_KNOWLEDGE_FACT_GENERATION_RECOVERY_STALE_SECONDS', 300))),
    'knowledge_fact_generation_finalizer_pending_seconds' => max(60, min(3600, (int) env('GEOFLOW_KNOWLEDGE_FACT_GENERATION_FINALIZER_PENDING_SECONDS', 900))),
    'knowledge_fact_generation_pending_batch_max_age_seconds' => max(60, min(86400, (int) env('GEOFLOW_KNOWLEDGE_FACT_GENERATION_PENDING_BATCH_MAX_AGE_SECONDS', 900))),
    'knowledge_fact_generation_retention_days' => 90,

    // SEO 描述
    'site_description' => env('SITE_DESCRIPTION', ''),
    // SEO 关键词（逗号分隔等，依前端使用方式）
    'site_keywords' => env('SITE_KEYWORDS', ''),

    'hosted_sites' => [
        'enabled' => filter_var(env('GEOFLOW_HOSTED_SITES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'root_domains' => $hostedRootDomains,
        'primary_hosts' => $primaryHosts,
        'configuration_errors' => $configurationErrors,
        'nginx_primary_host' => strtolower(trim((string) env('GEOFLOW_NGINX_PRIMARY_HOST', ''))),
        'nginx_root_domain' => strtolower(trim((string) env('GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN', ''))),
        'nginx_public_scheme' => strtolower(trim((string) env('GEOFLOW_NGINX_PUBLIC_SCHEME', 'http'))),
        'nginx_public_port' => max(1, (int) env('GEOFLOW_NGINX_PUBLIC_PORT', 80)),
        'reserved_labels' => [
            'www', 'admin', 'api', 'horizon', 'reverb', 'mail', 'smtp', 'ftp',
            'cdn', 'static', 'assets', 'status', 'up', 'localhost',
        ],
        'resolver_positive_ttl' => max(1, (int) env('GEOFLOW_HOSTED_SITE_RESOLVER_POSITIVE_TTL', 300)),
        'resolver_negative_ttl' => max(1, (int) env('GEOFLOW_HOSTED_SITE_RESOLVER_NEGATIVE_TTL', 30)),
        'default_daily_publish_limit' => max(1, (int) env('GEOFLOW_HOSTED_SITE_DAILY_PUBLISH_LIMIT', 3)),
        'default_min_publish_interval_minutes' => max(0, (int) env('GEOFLOW_HOSTED_SITE_MIN_PUBLISH_INTERVAL_MINUTES', 360)),
        'default_min_articles_before_index' => max(1, (int) env('GEOFLOW_HOSTED_SITE_MIN_ARTICLES_BEFORE_INDEX', 10)),
        'failure_cooldown_threshold' => max(1, (int) env('GEOFLOW_HOSTED_SITE_FAILURE_COOLDOWN_THRESHOLD', 3)),
        'failure_cooldown_minutes' => max(1, (int) env('GEOFLOW_HOSTED_SITE_FAILURE_COOLDOWN_MINUTES', 60)),
        'reservation_ttl_minutes' => max(1, (int) env('GEOFLOW_HOSTED_SITE_RESERVATION_TTL_MINUTES', 30)),
        'reconcile_limit' => max(1, (int) env('GEOFLOW_HOSTED_SITE_RECONCILE_LIMIT', 500)),
        'stale_sending_seconds' => max(90, (int) env('GEOFLOW_HOSTED_SITE_STALE_SENDING_SECONDS', 150)),
        'certified_themes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GEOFLOW_HOSTED_SITE_CERTIFIED_THEMES', 'default'))
        ))),
        'network_preflight_enabled' => filter_var(
            env('GEOFLOW_HOSTED_SITE_NETWORK_PREFLIGHT', env('APP_ENV') === 'production'),
            FILTER_VALIDATE_BOOLEAN
        ),
        'preflight_timeout_seconds' => max(2, (int) env('GEOFLOW_HOSTED_SITE_PREFLIGHT_TIMEOUT', 8)),
        'preflight_fresh_minutes' => max(1, (int) env('GEOFLOW_HOSTED_SITE_PREFLIGHT_FRESH_MINUTES', 15)),
        'index_observation_minutes' => max(0, (int) env('GEOFLOW_HOSTED_SITE_INDEX_OBSERVATION_MINUTES', 30)),
        'sitemap_url_limit' => 50000,
    ],

    // 后台入口路径前缀，如 /geo_admin（勿与前台路由冲突）
    'admin_base_path' => '/'.$adminBasePath,

    // 前台 Blade 使用的 Laravel 翻译 locale（与 APP_LOCALE、后台会话语言独立；对齐旧站中文导航）
    'public_locale' => env('GEOFLOW_PUBLIC_LOCALE', 'zh_CN'),
    // 默认前台主题；后台未显式选择主题时使用
    'default_theme' => env('GEOFLOW_DEFAULT_THEME', 'toutiao-news-20260426'),
    // 兼容旧环境变量；默认 db:seed 与 geoflow:install 均不会读取该值或导入演示内容。
    'seed_frontend_demo' => filter_var(env('GEOFLOW_SEED_FRONTEND_DEMO', false), FILTER_VALIDATE_BOOLEAN),
    // 仅供测试环境显式调用 FrontendDemoSeeder 时控制覆盖行为。
    'seed_frontend_demo_overwrite' => filter_var(env('GEOFLOW_SEED_FRONTEND_DEMO_OVERWRITE', false), FILTER_VALIDATE_BOOLEAN),

    // 当前系统版本（底部展示、GitHub 更新检查对比）；默认跟随本地 version.json，避免已部署 .env 锁死版本号。
    'app_version' => $appVersion,
    // 首次部署登录页初始管理员提示；只展示账号与初始化日志指引，永远不展示密码。
    'initial_admin_hint_enabled' => filter_var(env('GEOFLOW_INITIAL_ADMIN_HINT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'initial_admin_username' => trim((string) env('GEOFLOW_ADMIN_USERNAME', 'admin')) ?: 'admin',
    'initial_admin_email' => trim((string) env('GEOFLOW_ADMIN_EMAIL', 'admin@example.com')) ?: 'admin@example.com',
    'initial_admin_password' => (string) env('GEOFLOW_ADMIN_PASSWORD', ''),
    // 欢迎弹窗「介绍」文案版本：变更后所有管理员会再次看到介绍弹窗
    'welcome_intro_version' => env('GEOFLOW_WELCOME_INTRO_VERSION', '3.0'),
    // 匿名使用统计：只发送随机实例 ID、管理员摘要、版本和活跃事件；监控地址为空时不会产生请求。
    'telemetry_enabled' => filter_var(env('GEOFLOW_TELEMETRY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'telemetry_endpoint' => $telemetryEndpoint,
    'telemetry_interval_seconds' => max(3600, (int) env('GEOFLOW_TELEMETRY_INTERVAL_SECONDS', 86400)),
    // GitHub 最新正式 Release 的 version.json 资产；默认每天检查一次，可通过 GEOFLOW_UPDATE_CHECK_ENABLED=false 关闭
    'update_check_enabled' => filter_var(env('GEOFLOW_UPDATE_CHECK_ENABLED', env('APP_ENV') !== 'testing'), FILTER_VALIDATE_BOOLEAN),
    'update_metadata_url' => $updateMetadataUrl,
    'update_metadata_cache_ttl_seconds' => (int) env('GEOFLOW_UPDATE_METADATA_CACHE_TTL', 86400),
    // 后台系统更新中心由独立 GEOFlow Updater 执行变更，应用仅保留状态、操作桥接和只读旧记录。
    'update_center_enabled' => filter_var(env('GEOFLOW_UPDATE_CENTER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'update_require_admin_password' => filter_var(env('GEOFLOW_UPDATE_REQUIRE_ADMIN_PASSWORD', true), FILTER_VALIDATE_BOOLEAN),
    // 独立 updater bridge：应用只访问 Unix socket 和实例凭据，不接触 Docker socket。
    'updater_socket' => (string) env('GEOFLOW_UPDATER_SOCKET', '/run/geoflow-updater/geoflow-updater.sock'),
    'updater_control_token_file' => (string) env('GEOFLOW_UPDATER_CONTROL_TOKEN_FILE', '/run/secrets/geoflow-updater-control-token'),
    'updater_instance_id' => (string) env('GEOFLOW_UPDATER_INSTANCE_ID', 'primary'),
    'updater_host_root' => rtrim((string) env('GEOFLOW_UPDATER_HOST_ROOT', ''), '/'),
    'updater_connect_timeout_seconds' => max(0.1, (float) env('GEOFLOW_UPDATER_CONNECT_TIMEOUT_SECONDS', 0.5)),
    'updater_read_timeout_seconds' => max(1, (int) env('GEOFLOW_UPDATER_READ_TIMEOUT_SECONDS', 10)),
    'updater_bootstrap_manifest_url' => 'https://github.com/yaojingang/geoflow-updater/releases/latest/download/bootstrap-manifest.json',
    'updater_trusted_root_path' => resource_path('update-trust/root.json'),
    'updater_bootstrap_max_bytes' => 100 * 1024 * 1024,

    // 复刻主题审查包的资源上限。
    'theme_replication_package_max_files' => max(1, (int) env('GEOFLOW_THEME_REPLICATION_PACKAGE_MAX_FILES', 500)),
    'theme_replication_package_max_file_bytes' => max(1, (int) env('GEOFLOW_THEME_REPLICATION_PACKAGE_MAX_FILE_BYTES', 5 * 1024 * 1024)),
    'theme_replication_package_max_total_bytes' => max(1, (int) env('GEOFLOW_THEME_REPLICATION_PACKAGE_MAX_TOTAL_BYTES', 25 * 1024 * 1024)),
    'theme_replication_package_lock_timeout_milliseconds' => max(1, (int) env('GEOFLOW_THEME_REPLICATION_PACKAGE_LOCK_TIMEOUT_MS', 5000)),

    // 前台列表每页条数
    'items_per_page' => (int) env('GEOFLOW_ITEMS_PER_PAGE', 12),
    // 后台列表每页条数
    'admin_items_per_page' => (int) env('GEOFLOW_ADMIN_ITEMS_PER_PAGE', 20),
    // 标题库后台批量生成：任务目标上限、单批数量、模型限流和失败保护。
    'title_ai_max_count' => max(1, min(100_000, (int) env('GEOFLOW_TITLE_AI_MAX_COUNT', 100_000))),
    'title_ai_confirmation_threshold' => max(1, min(100_000, (int) env('GEOFLOW_TITLE_AI_CONFIRMATION_THRESHOLD', 1000))),
    'title_ai_batch_size' => max(1, min(50, (int) env('GEOFLOW_TITLE_AI_BATCH_SIZE', 50))),
    'title_ai_rate_per_minute' => max(1, min(60, (int) env('GEOFLOW_TITLE_AI_RATE_PER_MINUTE', 30))),
    'title_ai_submit_rate_per_minute' => max(1, min(60, (int) env('GEOFLOW_TITLE_AI_SUBMIT_RATE_PER_MINUTE', 6))),
    'title_ai_submit_ip_rate_per_minute' => max(1, min(120, (int) env('GEOFLOW_TITLE_AI_SUBMIT_IP_RATE_PER_MINUTE', 12))),
    'title_ai_max_active_runs_per_admin' => max(1, min(20, (int) env('GEOFLOW_TITLE_AI_MAX_ACTIVE_RUNS_PER_ADMIN', 3))),
    'title_ai_max_pending_titles_per_model' => max(100_000, min(10_000_000, (int) env('GEOFLOW_TITLE_AI_MAX_PENDING_TITLES_PER_MODEL', 300_000))),
    'title_ai_batch_delay_seconds' => max(0, min(60, (int) env('GEOFLOW_TITLE_AI_BATCH_DELAY_SECONDS', 1))),
    'title_ai_max_empty_batches' => max(1, min(10, (int) env('GEOFLOW_TITLE_AI_MAX_EMPTY_BATCHES', 3))),
    'title_ai_max_batch_attempts' => max(1, min(10, (int) env('GEOFLOW_TITLE_AI_MAX_BATCH_ATTEMPTS', 3))),
    'title_ai_max_manual_retries' => max(0, min(10, (int) env('GEOFLOW_TITLE_AI_MAX_MANUAL_RETRIES', 3))),
    'title_ai_max_request_multiplier' => max(2, min(10, (int) env('GEOFLOW_TITLE_AI_MAX_REQUEST_MULTIPLIER', 3))),
    'title_ai_recent_title_sample_limit' => max(0, min(50, (int) env('GEOFLOW_TITLE_AI_RECENT_TITLE_SAMPLE_LIMIT', 20))),
    'title_ai_request_timeout_seconds' => max(10, min(300, (int) env('GEOFLOW_TITLE_AI_REQUEST_TIMEOUT_SECONDS', 90))),
    'title_ai_lease_seconds' => max(420, min(600, (int) env('GEOFLOW_TITLE_AI_LEASE_SECONDS', 420))),
    'title_ai_recovery_stale_seconds' => max(60, min(3600, (int) env('GEOFLOW_TITLE_AI_RECOVERY_STALE_SECONDS', 300))),
    // 文章 AI 质检的全文预算、抽样降级预算、模型输出和证据预算。
    'ai_quality_request_timeout_seconds' => max(30, min(170, (int) env('GEOFLOW_AI_QUALITY_REQUEST_TIMEOUT_SECONDS', 160))),
    'ai_quality_deadline_seconds' => max(60, min(600, (int) env('GEOFLOW_AI_QUALITY_DEADLINE_SECONDS', 180))),
    'ai_quality_sampled_fallback_seconds' => max(15, min(300, (int) env('GEOFLOW_AI_QUALITY_SAMPLED_FALLBACK_SECONDS', 45))),
    'ai_quality_sampled_request_timeout_seconds' => max(10, min(120, (int) env('GEOFLOW_AI_QUALITY_SAMPLED_REQUEST_TIMEOUT_SECONDS', 35))),
    'ai_quality_sampled_max_characters' => max(1000, min(20000, (int) env('GEOFLOW_AI_QUALITY_SAMPLED_MAX_CHARACTERS', 6000))),
    'ai_quality_sampled_max_ranges' => max(3, min(24, (int) env('GEOFLOW_AI_QUALITY_SAMPLED_MAX_RANGES', 12))),
    'ai_quality_full_online_max_characters' => max(12000, min(200000, (int) env('GEOFLOW_AI_QUALITY_FULL_ONLINE_MAX_CHARACTERS', 60000))),
    'ai_quality_sampled_auto_release_enabled' => filter_var(env('GEOFLOW_AI_QUALITY_SAMPLED_AUTO_RELEASE_ENABLED', true), FILTER_VALIDATE_BOOL),
    'ai_quality_max_output_tokens' => max(512, min(4096, (int) env('GEOFLOW_AI_QUALITY_MAX_OUTPUT_TOKENS', 2048))),
    'ai_quality_max_model_candidates' => max(1, min(2, (int) env('GEOFLOW_AI_QUALITY_MAX_MODEL_CANDIDATES', 2))),
    'ai_quality_max_evidence' => max(4, min(24, (int) env('GEOFLOW_AI_QUALITY_MAX_EVIDENCE', 12))),
    'ai_quality_max_evidence_characters' => max(2000, min(12000, (int) env('GEOFLOW_AI_QUALITY_MAX_EVIDENCE_CHARACTERS', 6000))),
    'ai_quality_max_fact_retrievals' => max(0, min(12, (int) env('GEOFLOW_AI_QUALITY_MAX_FACT_RETRIEVALS', 6))),
    'ai_quality_queue' => trim((string) env('GEOFLOW_AI_QUALITY_QUEUE', 'ai-quality')),
    'ai_quality_backfill_queue' => trim((string) env('GEOFLOW_AI_QUALITY_BACKFILL_QUEUE', 'ai-quality-backfill')),
    'ai_quality_persistence_reserve_seconds' => max(5, min(30, (int) env('GEOFLOW_AI_QUALITY_PERSISTENCE_RESERVE_SECONDS', 10))),
    'ai_quality_worker_heartbeat_seconds' => 10,
    'ai_quality_worker_stale_seconds' => max(60, min(900, (int) env('GEOFLOW_AI_QUALITY_WORKER_STALE_SECONDS', 300))),
    'ai_quality_job_timeout_seconds' => max(70, min(945, (int) env('GEOFLOW_AI_QUALITY_JOB_TIMEOUT_SECONDS', 245))),
    'ai_quality_worker_timeout_seconds' => max(75, min(950, (int) env('GEOFLOW_AI_QUALITY_WORKER_TIMEOUT_SECONDS', 250))),
    'ai_quality_front_workers' => max(1, (int) env('AI_QUALITY_QUEUE_REPLICAS', 2)),
    'ai_quality_backfill_workers' => 1,
    'ai_quality_execution_version' => trim((string) env('GEOFLOW_AI_QUALITY_EXECUTION_VERSION', 'legacy')),
    'ai_quality_principle_v2_percent' => max(0, min(100, (int) env('GEOFLOW_AI_QUALITY_PRINCIPLE_V2_PERCENT', 0))),
    'ai_quality_fast_v2_percent' => max(0, min(100, (int) env('GEOFLOW_AI_QUALITY_FAST_V2_PERCENT', 0))),
    'ai_quality_scoring_v2_percent' => max(0, min(100, (int) env('GEOFLOW_AI_QUALITY_SCORING_V2_PERCENT', 0))),
    'ai_quality_shadow_v2_percent' => max(0, min(100, (int) env('GEOFLOW_AI_QUALITY_SHADOW_V2_PERCENT', 0))),
    'ai_quality_evidence_cache_ttl_seconds' => max(60, min(604800, (int) env('GEOFLOW_AI_QUALITY_EVIDENCE_CACHE_TTL_SECONDS', 86400))),
    'ai_quality_evidence_cache_enabled' => filter_var(env('GEOFLOW_AI_QUALITY_EVIDENCE_CACHE_ENABLED', true), FILTER_VALIDATE_BOOL),
    'ai_quality_circuit_consecutive_failures' => max(1, min(20, (int) env('GEOFLOW_AI_QUALITY_CIRCUIT_CONSECUTIVE_FAILURES', 5))),
    'ai_quality_circuit_sample_size' => max(2, min(100, (int) env('GEOFLOW_AI_QUALITY_CIRCUIT_SAMPLE_SIZE', 10))),
    'ai_quality_circuit_failure_percent' => max(1, min(100, (int) env('GEOFLOW_AI_QUALITY_CIRCUIT_FAILURE_PERCENT', 50))),
    'ai_quality_circuit_open_seconds' => max(5, min(900, (int) env('GEOFLOW_AI_QUALITY_CIRCUIT_OPEN_SECONDS', 60))),
    'ai_quality_front_queue_wait_seconds' => max(1, min(120, (int) env('GEOFLOW_AI_QUALITY_FRONT_QUEUE_WAIT_SECONDS', 10))),
    'ai_quality_backfill_quota_reserve' => max(0, min(100, (int) env('GEOFLOW_AI_QUALITY_BACKFILL_QUOTA_RESERVE', 2))),
    'ai_quality_recovery_stale_seconds' => max(60, min(900, (int) env('GEOFLOW_AI_QUALITY_RECOVERY_STALE_SECONDS', 60))),
    'ai_quality_structured_reprobe_seconds' => max(300, min(604800, (int) env('GEOFLOW_AI_QUALITY_STRUCTURED_REPROBE_SECONDS', 86400))),

    // AI 质检自动优化默认关闭，完成评测与灰度门槛后再逐步放量。
    'ai_quality_optimization_enabled' => filter_var(env('GEOFLOW_AI_QUALITY_OPTIMIZATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'ai_quality_optimization_auto_apply_enabled' => filter_var(env('GEOFLOW_AI_QUALITY_OPTIMIZATION_AUTO_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'ai_quality_optimization_percent' => max(0, min(100, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_PERCENT', 0))),
    'ai_quality_optimization_auto_apply_percent' => max(0, min(100, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_AUTO_APPLY_PERCENT', 0))),
    'ai_quality_optimization_bulk_quota_reserve' => max(0, min(100, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_BULK_QUOTA_RESERVE', 2))),
    'ai_quality_optimization_max_model_attempts' => max(1, min(3, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_MAX_MODEL_ATTEMPTS', 2))),
    'ai_quality_optimization_queue' => trim((string) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_QUEUE', 'ai-content-optimization')),
    'ai_quality_optimization_bulk_queue' => trim((string) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_BULK_QUEUE', 'ai-content-optimization-bulk')),
    'ai_quality_optimization_max_rounds' => max(1, min(3, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_MAX_ROUNDS', 3))),
    'ai_quality_optimization_max_edit_characters' => max(100, min(8000, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_MAX_EDIT_CHARACTERS', 8000))),
    'ai_quality_optimization_round_estimated_seconds' => max(30, min(600, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_ROUND_ESTIMATED_SECONDS', 235))),
    'ai_quality_optimization_lease_seconds' => max(60, min(900, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_LEASE_SECONDS', 300))),
    'ai_quality_optimization_recovery_stale_seconds' => max(60, min(900, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_RECOVERY_STALE_SECONDS', 300))),
    'ai_quality_optimization_job_timeout_seconds' => max(60, min(900, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_JOB_TIMEOUT_SECONDS', 850))),
    'ai_quality_optimization_worker_timeout_seconds' => max(70, min(940, (int) env('GEOFLOW_AI_QUALITY_OPTIMIZATION_WORKER_TIMEOUT_SECONDS', 900))),
    'ai_quality_optimization_strategies' => [
        'pass' => ['max_rounds' => 1, 'edit_budget_percent' => 15],
        'excellent_80' => ['max_rounds' => 2, 'edit_budget_percent' => 25],
        'excellent_90' => ['max_rounds' => 3, 'edit_budget_percent' => 35],
    ],
    // 统一出站安全网关：仅此处列出的精确 host:port 可连接私网地址；不支持通配符或路径。
    'outbound_private_targets' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEOFLOW_OUTBOUND_PRIVATE_TARGETS', ''))), static fn (string $target): bool => $target !== '')),
    'outbound_json_max_bytes' => max(1, (int) env('GEOFLOW_OUTBOUND_JSON_MAX_BYTES', 4 * 1024 * 1024)),
    'outbound_ai_max_bytes' => max(1, (int) env('GEOFLOW_OUTBOUND_AI_MAX_BYTES', 8 * 1024 * 1024)),
    'outbound_import_max_bytes' => max(1, (int) env('GEOFLOW_OUTBOUND_IMPORT_MAX_BYTES', 5 * 1024 * 1024)),
    'outbound_metadata_max_bytes' => max(1, (int) env('GEOFLOW_OUTBOUND_METADATA_MAX_BYTES', 1024 * 1024)),
    'outbound_response_max_bytes' => max(1, (int) env(
        'GEOFLOW_OUTBOUND_RESPONSE_MAX_BYTES',
        env('GEOFLOW_UPDATE_ARCHIVE_MAX_BYTES', 50 * 1024 * 1024),
    )),
    // 为 true 时记录知识库「查询向量」是否由默认 embedding 接口生成（便于对照 bak 验证；默认关闭）
    'debug_knowledge_query_embedding' => filter_var(env('GEOFLOW_DEBUG_KNOWLEDGE_QUERY_EMBEDDING', false), FILTER_VALIDATE_BOOLEAN),
    // 语义切片规划 prompt 最大字符数；超过后直接走结构化规则回退，避免长知识库拖慢或超上下文。
    'semantic_chunking_max_chars' => max(1, (int) env('GEOFLOW_SEMANTIC_CHUNKING_MAX_CHARS', 20000)),
    // Embedding 文档向量化单次请求切片数；部分供应商限制 batch 较小，默认保守拆分。
    'embedding_batch_size' => max(1, min(64, (int) env('GEOFLOW_EMBEDDING_BATCH_SIZE', 1))),
    // 管理员 AI 执行身份灰度开关：先写入和 Shadow，对账完成后再逐步强制访问与撤权边界。
    'admin_ai_access' => [
        'ownership_write_enabled' => filter_var(env('GEOFLOW_ADMIN_AI_OWNERSHIP_WRITE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'shadow_enabled' => filter_var(env('GEOFLOW_ADMIN_AI_SHADOW_ENABLED', true), FILTER_VALIDATE_BOOL),
        'access_enforce_enabled' => filter_var(env('GEOFLOW_ADMIN_AI_ACCESS_ENFORCE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'revocation_enforce_enabled' => filter_var(env('GEOFLOW_ADMIN_AI_REVOCATION_ENFORCE_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
    // 单个知识向量化 Job 最多处理的切片数，控制队列进程峰值内存。
    'knowledge_embedding_job_size' => max(1, min(32, (int) env('GEOFLOW_KNOWLEDGE_EMBEDDING_JOB_SIZE', 32))),
    // Worker 心跳超过该秒数未更新时，任务页标记为 stale。
    'worker_stale_seconds' => max(30, (int) env('GEOFLOW_WORKER_STALE_SECONDS', 120)),
    // 正文生成默认最大输出 token 数；当 AI 模型未单独配置 max_tokens 时使用此兜底值，
    // 避免依赖各服务商较小的默认上限（常见 4K）导致长文被截断。
    'content_max_tokens' => max(256, (int) env('GEOFLOW_CONTENT_MAX_TOKENS', 16384)),
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
