<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GEOFlow 遗留业务表结构（PostgreSQL 原生 SQL）。
 *
 * 对齐 {@see bak/includes/database_admin.php}：createTables + ensureTaskQueueSchema +
 * ensureApiSchema + ensureCompatibilitySchema 合并；向量列见 {@see 2026_04_18_120001_geoflow_knowledge_chunks_embedding_vector}。
 * heredoc 内 `--` 注释为表/字段说明，仅作文档，不改变语义。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared($this->pgsqlSchema());
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->dropOrder() as $table) {
            DB::unprepared("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }

    /**
     * down() 删表顺序：先删有外键依赖的子表，与 up() 相反。
     *
     * @return list<string>
     */
    private function dropOrder(): array
    {
        return [
            'api_idempotency_keys',
            'worker_heartbeats',
            'task_runs',
            'url_import_job_logs',
            'url_import_jobs',
            'article_reviews',
            'article_images',
            'articles',
            'sensitive_words',
            'task_schedules',
            'tasks',
            'admin_activity_logs',
            'system_logs',
            'categories',
            'titles',
            'title_libraries',
            'keywords',
            'keyword_libraries',
            'images',
            'image_libraries',
            'knowledge_chunks',
            'knowledge_bases',
            'authors',
            'prompts',
            'ai_models',
        ];
    }

    private function pgsqlSchema(): string
    {
        return <<<'SQL'
-- ========== 以下为 GEOFlow 业务表；字段行末 -- 为中文说明 ==========

-- 表 ai_models：第三方 LLM 接入配置（密钥、限额、模型标识、类型）
CREATE TABLE IF NOT EXISTS ai_models (
    id BIGSERIAL PRIMARY KEY, -- 主键
    name VARCHAR(100) NOT NULL, -- 后台展示名称
    version VARCHAR(50) DEFAULT '', -- 版本号（展示/兼容）
    api_key VARCHAR(500) NOT NULL, -- API 密钥（敏感，勿泄露）
    model_id VARCHAR(100) NOT NULL, -- 供应商侧模型 ID
    model_type VARCHAR(20) DEFAULT 'chat', -- 类型：chat 等；空视为 chat
    api_url VARCHAR(500) DEFAULT 'https://api.deepseek.com', -- 网关 Base URL
    failover_priority INTEGER DEFAULT 100, -- 故障转移优先级（数值越小越优先可选）
    daily_limit INTEGER DEFAULT 0, -- 日调用上限（0 表示不限制，依业务）
    used_today INTEGER DEFAULT 0, -- 当日已用量
    total_used INTEGER DEFAULT 0, -- 累计用量
    status VARCHAR(20) DEFAULT 'active', -- active/disabled 等
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 表 prompts：提示词模板（按 type 区分用途，如 content 为正文生成）
CREATE TABLE IF NOT EXISTS prompts (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL, -- 模板名称
    type VARCHAR(50) NOT NULL, -- 用途分类，如 content
    content TEXT NOT NULL, -- 模板正文，可含占位符
    variables TEXT DEFAULT '', -- 变量说明或 JSON
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 表 keyword_libraries：关键词库（用于标题/标签等）
CREATE TABLE IF NOT EXISTS keyword_libraries (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT '',
    keyword_count INTEGER DEFAULT 0, -- 缓存条数
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 表 keywords：关键词库内词条
CREATE TABLE IF NOT EXISTS keywords (
    id BIGSERIAL PRIMARY KEY,
    library_id BIGINT NOT NULL,
    keyword VARCHAR(200) NOT NULL,
    used_count INTEGER DEFAULT 0,
    usage_count INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (library_id) REFERENCES keyword_libraries(id) ON DELETE CASCADE,
    UNIQUE (library_id, keyword)
);

-- 表 title_libraries：标题库（可关联关键词库、模型、提示词）
CREATE TABLE IF NOT EXISTS title_libraries (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT '',
    title_count INTEGER DEFAULT 0,
    generation_type VARCHAR(20) DEFAULT 'manual', -- manual / ai 等
    keyword_library_id BIGINT DEFAULT NULL,
    ai_model_id BIGINT DEFAULT NULL,
    prompt_id BIGINT DEFAULT NULL,
    generation_rounds INTEGER DEFAULT 1,
    is_ai_generated INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (keyword_library_id) REFERENCES keyword_libraries(id),
    FOREIGN KEY (ai_model_id) REFERENCES ai_models(id),
    FOREIGN KEY (prompt_id) REFERENCES prompts(id)
);

-- 表 titles：标题库内单条标题
CREATE TABLE IF NOT EXISTS titles (
    id BIGSERIAL PRIMARY KEY,
    library_id BIGINT NOT NULL,
    title VARCHAR(500) NOT NULL,
    keyword VARCHAR(200) DEFAULT '',
    is_ai_generated BOOLEAN DEFAULT FALSE,
    used_count INTEGER DEFAULT 0,
    usage_count INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (library_id) REFERENCES title_libraries(id) ON DELETE CASCADE
);

-- 表 image_libraries：图片库
CREATE TABLE IF NOT EXISTS image_libraries (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT '',
    image_count INTEGER DEFAULT 0,
    used_task_count INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 表 images：图片文件元数据
CREATE TABLE IF NOT EXISTS images (
    id BIGSERIAL PRIMARY KEY,
    library_id BIGINT NOT NULL,
    filename VARCHAR(255) NOT NULL, -- 存储文件名
    original_name VARCHAR(255) NOT NULL, -- 上传原名
    file_name VARCHAR(255) NOT NULL DEFAULT '',
    file_path VARCHAR(500) NOT NULL, -- 相对/绝对路径
    file_size INTEGER DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT '',
    width INTEGER DEFAULT 0,
    height INTEGER DEFAULT 0,
    tags TEXT DEFAULT '',
    used_count INTEGER DEFAULT 0,
    usage_count INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (library_id) REFERENCES image_libraries(id) ON DELETE CASCADE
);

-- 表 knowledge_bases：知识库主文档
CREATE TABLE IF NOT EXISTS knowledge_bases (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT '',
    content TEXT NOT NULL, -- 全文或导入内容
    character_count INTEGER DEFAULT 0,
    used_task_count INTEGER DEFAULT 0,
    file_type VARCHAR(20) DEFAULT 'markdown',
    file_path VARCHAR(500) DEFAULT '', -- 若来自文件导入
    word_count INTEGER DEFAULT 0,
    usage_count INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 表 knowledge_chunks：知识分块（向量列见下一迁移）
CREATE TABLE IF NOT EXISTS knowledge_chunks (
    id BIGSERIAL PRIMARY KEY,
    knowledge_base_id BIGINT NOT NULL,
    chunk_index INTEGER NOT NULL, -- 块序号
    content TEXT NOT NULL,
    content_hash VARCHAR(64) DEFAULT '', -- 去重/变更检测
    token_count INTEGER DEFAULT 0,
    embedding_json TEXT DEFAULT '', -- 可选 JSON 向量备份
    embedding_model_id INTEGER DEFAULT NULL,
    embedding_dimensions INTEGER DEFAULT 0,
    embedding_provider VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (knowledge_base_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    UNIQUE (knowledge_base_id, chunk_index)
);

CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_base ON knowledge_chunks (knowledge_base_id, chunk_index);

-- 表 authors：文章作者展示信息
CREATE TABLE IF NOT EXISTS authors (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    bio TEXT DEFAULT '',
    email VARCHAR(100) DEFAULT '',
    avatar VARCHAR(200) DEFAULT '',
    website VARCHAR(200) DEFAULT '',
    social_links TEXT DEFAULT '', -- JSON 或文本
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 表 tasks：内容生成任务（调度、模型、审核、分类策略等）
CREATE TABLE IF NOT EXISTS tasks (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    title_library_id BIGINT NOT NULL,
    image_library_id BIGINT DEFAULT NULL,
    image_count INTEGER DEFAULT 1,
    prompt_id BIGINT NOT NULL,
    ai_model_id BIGINT NOT NULL,
    author_id BIGINT DEFAULT NULL,
    need_review INTEGER DEFAULT 1, -- 是否需人工审核
    publish_interval INTEGER DEFAULT 3600, -- 发布间隔秒
    author_type VARCHAR(20) DEFAULT 'random', -- 作者选取策略
    custom_author_id BIGINT DEFAULT NULL,
    auto_keywords INTEGER DEFAULT 1,
    auto_description INTEGER DEFAULT 1,
    draft_limit INTEGER DEFAULT 10, -- 草稿池上限
    article_limit INTEGER DEFAULT 10, -- 任务总生成文章数上限
    is_loop INTEGER DEFAULT 0, -- 是否循环任务
    model_selection_mode VARCHAR(20) DEFAULT 'fixed', -- fixed / smart_failover
    status VARCHAR(20) DEFAULT 'active', -- active / paused
    created_count INTEGER DEFAULT 0,
    published_count INTEGER DEFAULT 0,
    loop_count INTEGER DEFAULT 0,
    knowledge_base_id BIGINT DEFAULT NULL,
    category_mode VARCHAR(20) DEFAULT 'smart', -- smart / fixed
    fixed_category_id BIGINT DEFAULT NULL,
    last_run_at TIMESTAMP DEFAULT NULL,
    next_run_at TIMESTAMP DEFAULT NULL,
    next_publish_at TIMESTAMP DEFAULT NULL,
    last_success_at TIMESTAMP DEFAULT NULL,
    last_error_at TIMESTAMP DEFAULT NULL,
    last_error_message TEXT DEFAULT '',
    schedule_enabled INTEGER DEFAULT 1,
    max_retry_count INTEGER DEFAULT 3,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (title_library_id) REFERENCES title_libraries(id),
    FOREIGN KEY (image_library_id) REFERENCES image_libraries(id),
    FOREIGN KEY (prompt_id) REFERENCES prompts(id),
    FOREIGN KEY (ai_model_id) REFERENCES ai_models(id),
    FOREIGN KEY (author_id) REFERENCES authors(id),
    FOREIGN KEY (custom_author_id) REFERENCES authors(id)
);

-- 表 categories：站点文章分类
CREATE TABLE IF NOT EXISTS categories (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL, -- URL 段
    description TEXT DEFAULT '',
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 表 articles：站点文章（软删除）
CREATE TABLE IF NOT EXISTS articles (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    slug VARCHAR(500) UNIQUE NOT NULL,
    excerpt TEXT DEFAULT '',
    content TEXT NOT NULL,
    category_id BIGINT NOT NULL,
    author_id BIGINT NOT NULL,
    task_id BIGINT DEFAULT NULL, -- 来源生成任务
    original_keyword VARCHAR(200) DEFAULT '',
    keywords TEXT DEFAULT '',
    meta_description TEXT DEFAULT '',
    status VARCHAR(20) DEFAULT 'draft', -- draft / published 等
    review_status VARCHAR(20) DEFAULT 'pending',
    view_count INTEGER DEFAULT 0,
    is_ai_generated INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMP DEFAULT NULL,
    deleted_at TIMESTAMP DEFAULT NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (author_id) REFERENCES authors(id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
);

-- 表 article_images：文章与图片多对多及排序
CREATE TABLE IF NOT EXISTS article_images (
    id BIGSERIAL PRIMARY KEY,
    article_id BIGINT NOT NULL,
    image_id BIGINT NOT NULL,
    position INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    FOREIGN KEY (image_id) REFERENCES images(id)
);

-- 表 sensitive_words：敏感词过滤
CREATE TABLE IF NOT EXISTS sensitive_words (
    id BIGSERIAL PRIMARY KEY,
    word VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 表 task_schedules：任务调度计划（与 next_run_at 等配合）
CREATE TABLE IF NOT EXISTS task_schedules (
    id BIGSERIAL PRIMARY KEY,
    task_id BIGINT NOT NULL,
    next_run_time TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    error_message TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

-- 表 system_logs：系统级日志
CREATE TABLE IF NOT EXISTS system_logs (
    id BIGSERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    data TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 表 admin_activity_logs：后台管理员操作审计
CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT DEFAULT NULL,
    admin_username VARCHAR(50) NOT NULL,
    admin_role VARCHAR(20) DEFAULT 'admin',
    action VARCHAR(120) NOT NULL,
    request_method VARCHAR(10) DEFAULT 'POST',
    page VARCHAR(255) DEFAULT '',
    target_type VARCHAR(50) DEFAULT '',
    target_id BIGINT DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT '',
    details TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_admin_activity_logs_admin ON admin_activity_logs (admin_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_admin_activity_logs_created ON admin_activity_logs (created_at DESC);

-- 表 article_reviews：文章审核记录
CREATE TABLE IF NOT EXISTS article_reviews (
    id BIGSERIAL PRIMARY KEY,
    article_id BIGINT NOT NULL,
    admin_id BIGINT NOT NULL,
    review_status VARCHAR(20) NOT NULL,
    review_note TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins(id)
);

-- 表 url_import_jobs：URL 导入异步任务
CREATE TABLE IF NOT EXISTS url_import_jobs (
    id BIGSERIAL PRIMARY KEY,
    url TEXT NOT NULL,
    normalized_url TEXT NOT NULL,
    source_domain VARCHAR(255) DEFAULT '',
    page_title VARCHAR(255) DEFAULT '',
    status VARCHAR(20) DEFAULT 'queued',
    current_step VARCHAR(50) DEFAULT 'queued',
    progress_percent INTEGER DEFAULT 0,
    options_json TEXT DEFAULT '',
    result_json TEXT DEFAULT '',
    error_message TEXT DEFAULT '',
    created_by VARCHAR(100) DEFAULT '',
    started_at TIMESTAMP DEFAULT NULL,
    finished_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 表 url_import_job_logs：导入任务步骤日志
CREATE TABLE IF NOT EXISTS url_import_job_logs (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT NOT NULL,
    step VARCHAR(50) DEFAULT 'queued',
    level VARCHAR(20) DEFAULT 'info',
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES url_import_jobs(id) ON DELETE CASCADE
);

-- 表 task_runs：单次 Job 执行记录（与产出文章、耗时关联）
CREATE TABLE IF NOT EXISTS task_runs (
    id BIGSERIAL PRIMARY KEY,
    task_id BIGINT NOT NULL,
    status VARCHAR(20) NOT NULL,
    article_id BIGINT DEFAULT NULL,
    error_message TEXT DEFAULT '',
    duration_ms INTEGER DEFAULT 0,
    meta TEXT DEFAULT '', -- JSON
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

-- 表 worker_heartbeats：Worker 存活与当前 Job
CREATE TABLE IF NOT EXISTS worker_heartbeats (
    worker_id VARCHAR(100) PRIMARY KEY,
    status VARCHAR(20) NOT NULL DEFAULT 'idle',
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    meta TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_task_runs_task ON task_runs (task_id);
CREATE INDEX IF NOT EXISTS idx_task_runs_status ON task_runs (status);
CREATE INDEX IF NOT EXISTS idx_worker_heartbeats_last_seen ON worker_heartbeats (last_seen_at);

-- 表 api_idempotency_keys：写接口幂等缓存（键 + 路由 + 请求体哈希）
CREATE TABLE IF NOT EXISTS api_idempotency_keys (
    id BIGSERIAL PRIMARY KEY,
    idempotency_key VARCHAR(120) NOT NULL, -- 客户端 X-Idempotency-Key
    route_key VARCHAR(120) NOT NULL, -- 如 POST /tasks
    request_hash VARCHAR(64) NOT NULL, -- 归一化 body 的 SHA256
    response_body TEXT NOT NULL, -- 完整 JSON 响应缓存
    response_status INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (idempotency_key, route_key)
);

CREATE INDEX IF NOT EXISTS idx_api_idempotency_created_at ON api_idempotency_keys (created_at DESC);
SQL;
    }
};
