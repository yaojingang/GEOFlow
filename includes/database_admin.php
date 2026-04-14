<?php
/**
 * GEO+AI内容生成系统 - 后台数据库结构
 *
 * @author 姚金刚
 * @version 3.0
 * @date 2025-10-06
 */

// 防止直接访问
if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class DatabaseAdmin {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $this->connect();
        $this->createTables();
        $this->ensureTaskQueueSchema();
        $this->ensureApiSchema();
        $this->ensureCompatibilitySchema();
        $this->ensurePgvectorSchema();
        $this->insertDefaultData();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function connect() {
        try {
            $this->pdo = db_create_runtime_pdo();
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            die('数据库配置错误: ' . $e->getMessage());
        }
    }
    
    public function getPDO() {
        return $this->pdo;
    }
    
    private function createTables() {
        $sql = "
        -- 管理员表
        CREATE TABLE IF NOT EXISTS admins (
            id BIGSERIAL PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) DEFAULT '',
            display_name VARCHAR(100) DEFAULT '',
            role VARCHAR(20) DEFAULT 'admin',
            status VARCHAR(20) DEFAULT 'active',
            created_by INTEGER DEFAULT NULL,
            last_login TIMESTAMP DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 网站设置表（键值对）
        CREATE TABLE IF NOT EXISTS site_settings (
            id BIGSERIAL PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- AI模型配置表
        CREATE TABLE IF NOT EXISTS ai_models (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            version VARCHAR(50) DEFAULT '',
            api_key VARCHAR(500) NOT NULL,
            model_id VARCHAR(100) NOT NULL,
            model_type VARCHAR(20) DEFAULT 'chat',
            api_url VARCHAR(500) DEFAULT 'https://api.tu-zi.com',
            daily_limit INTEGER DEFAULT 0, -- 每日调用限制，0为不限制
            used_today INTEGER DEFAULT 0,
            total_used INTEGER DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active', -- active, inactive
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 提示词配置表
        CREATE TABLE IF NOT EXISTS prompts (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(50) NOT NULL, -- title, content, keyword, description
            content TEXT NOT NULL,
            variables TEXT DEFAULT '', -- 支持的变量列表
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 关键词库表
        CREATE TABLE IF NOT EXISTS keyword_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            keyword_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 关键词表
        CREATE TABLE IF NOT EXISTS keywords (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            keyword VARCHAR(200) NOT NULL,
            used_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES keyword_libraries(id) ON DELETE CASCADE
        );

        -- 标题库表
        CREATE TABLE IF NOT EXISTS title_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            title_count INTEGER DEFAULT 0,
            generation_type VARCHAR(20) DEFAULT 'manual', -- manual, ai_generated
            keyword_library_id INTEGER DEFAULT NULL,
            ai_model_id INTEGER DEFAULT NULL,
            prompt_id INTEGER DEFAULT NULL,
            generation_rounds INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (keyword_library_id) REFERENCES keyword_libraries(id),
            FOREIGN KEY (ai_model_id) REFERENCES ai_models(id),
            FOREIGN KEY (prompt_id) REFERENCES prompts(id)
        );

        -- 标题表
        CREATE TABLE IF NOT EXISTS titles (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            title VARCHAR(500) NOT NULL,
            keyword VARCHAR(200) DEFAULT '',
            used_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES title_libraries(id) ON DELETE CASCADE
        );

        -- 图片库表
        CREATE TABLE IF NOT EXISTS image_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            image_count INTEGER DEFAULT 0,
            used_task_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 图片表
        CREATE TABLE IF NOT EXISTS images (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            filename VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INTEGER DEFAULT 0,
            mime_type VARCHAR(100) DEFAULT '',
            used_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES image_libraries(id) ON DELETE CASCADE
        );

        -- AI知识库表
        CREATE TABLE IF NOT EXISTS knowledge_bases (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            content TEXT NOT NULL,
            character_count INTEGER DEFAULT 0,
            used_task_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 作者表
        CREATE TABLE IF NOT EXISTS authors (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            bio TEXT DEFAULT '',
            email VARCHAR(100) DEFAULT '',
            avatar VARCHAR(200) DEFAULT '',
            website VARCHAR(200) DEFAULT '',
            social_links TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 任务表
        CREATE TABLE IF NOT EXISTS tasks (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            title_library_id INTEGER NOT NULL,
            image_library_id INTEGER DEFAULT NULL,
            image_count INTEGER DEFAULT 1, -- 每篇文章配图数量
            prompt_id INTEGER NOT NULL,
            ai_model_id INTEGER NOT NULL,
            author_id INTEGER DEFAULT NULL,
            need_review INTEGER DEFAULT 1, -- 是否需要人工审核
            publish_interval INTEGER DEFAULT 3600, -- 发布间隔（秒）
            author_type VARCHAR(20) DEFAULT 'random', -- custom, random
            custom_author_id INTEGER DEFAULT NULL,
            auto_keywords INTEGER DEFAULT 1, -- 自动生成关键词
            auto_description INTEGER DEFAULT 1, -- 自动生成描述
            draft_limit INTEGER DEFAULT 10, -- 草稿数量限制
            is_loop INTEGER DEFAULT 0, -- 是否循环生成
            status VARCHAR(20) DEFAULT 'active', -- active, paused, completed
            created_count INTEGER DEFAULT 0, -- 已创建文章数
            published_count INTEGER DEFAULT 0, -- 已发布文章数
            loop_count INTEGER DEFAULT 0, -- 循环次数
            knowledge_base_id INTEGER DEFAULT NULL,
            category_mode VARCHAR(20) DEFAULT 'smart',
            fixed_category_id INTEGER DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (title_library_id) REFERENCES title_libraries(id),
            FOREIGN KEY (image_library_id) REFERENCES image_libraries(id),
            FOREIGN KEY (prompt_id) REFERENCES prompts(id),
            FOREIGN KEY (ai_model_id) REFERENCES ai_models(id),
            FOREIGN KEY (author_id) REFERENCES authors(id),
            FOREIGN KEY (custom_author_id) REFERENCES authors(id)
        );

        -- 分类表
        CREATE TABLE IF NOT EXISTS categories (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) UNIQUE NOT NULL,
            description TEXT DEFAULT '',
            sort_order INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 文章表
        CREATE TABLE IF NOT EXISTS articles (
            id BIGSERIAL PRIMARY KEY,
            title VARCHAR(500) NOT NULL,
            slug VARCHAR(500) UNIQUE NOT NULL,
            excerpt TEXT DEFAULT '',
            content TEXT NOT NULL,
            category_id INTEGER NOT NULL,
            author_id INTEGER NOT NULL,
            task_id INTEGER DEFAULT NULL, -- 关联的任务ID
            original_keyword VARCHAR(200) DEFAULT '', -- 原始关键词
            keywords TEXT DEFAULT '', -- SEO关键词
            meta_description TEXT DEFAULT '', -- SEO描述
            status VARCHAR(20) DEFAULT 'draft', -- draft, published, private
            review_status VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected, auto_approved
            view_count INTEGER DEFAULT 0,
            is_ai_generated INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            published_at TIMESTAMP DEFAULT NULL,
            deleted_at TIMESTAMP DEFAULT NULL,
            FOREIGN KEY (category_id) REFERENCES categories(id),
            FOREIGN KEY (author_id) REFERENCES authors(id),
            FOREIGN KEY (task_id) REFERENCES tasks(id)
        );

        -- 文章图片关联表
        CREATE TABLE IF NOT EXISTS article_images (
            id BIGSERIAL PRIMARY KEY,
            article_id INTEGER NOT NULL,
            image_id INTEGER NOT NULL,
            position INTEGER DEFAULT 0, -- 图片在文章中的位置
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
            FOREIGN KEY (image_id) REFERENCES images(id)
        );

        -- 敏感词表
        CREATE TABLE IF NOT EXISTS sensitive_words (
            id BIGSERIAL PRIMARY KEY,
            word VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 任务调度表
        CREATE TABLE IF NOT EXISTS task_schedules (
            id BIGSERIAL PRIMARY KEY,
            task_id INTEGER NOT NULL,
            next_run_time TIMESTAMP NOT NULL,
            status VARCHAR(20) DEFAULT 'pending', -- pending, running, completed, failed
            error_message TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        );

        -- 系统日志表
        CREATE TABLE IF NOT EXISTS system_logs (
            id BIGSERIAL PRIMARY KEY,
            type VARCHAR(50) NOT NULL, -- task, article, system, error
            message TEXT NOT NULL,
            data TEXT DEFAULT '', -- JSON格式的额外数据
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 管理员操作日志
        CREATE TABLE IF NOT EXISTS admin_activity_logs (
            id BIGSERIAL PRIMARY KEY,
            admin_id INTEGER DEFAULT NULL,
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

        -- 文章审核记录表
        CREATE TABLE IF NOT EXISTS article_reviews (
            id BIGSERIAL PRIMARY KEY,
            article_id INTEGER NOT NULL,
            admin_id INTEGER NOT NULL,
            review_status VARCHAR(20) NOT NULL, -- pending, approved, rejected
            review_note TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES admins(id)
        );

        -- 关键词库表
        CREATE TABLE IF NOT EXISTS keyword_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '',
            keyword_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 关键词表
        CREATE TABLE IF NOT EXISTS keywords (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            keyword VARCHAR(200) NOT NULL,
            usage_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES keyword_libraries(id) ON DELETE CASCADE,
            UNIQUE(library_id, keyword)
        );

        -- 标题库表
        CREATE TABLE IF NOT EXISTS title_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '',
            title_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 标题表
        CREATE TABLE IF NOT EXISTS titles (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            title VARCHAR(500) NOT NULL,
            is_ai_generated BOOLEAN DEFAULT FALSE,
            usage_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES title_libraries(id) ON DELETE CASCADE
        );

        -- 图片库表
        CREATE TABLE IF NOT EXISTS image_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '',
            image_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 图片表
        CREATE TABLE IF NOT EXISTS images (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INTEGER DEFAULT 0,
            mime_type VARCHAR(100) DEFAULT '',
            width INTEGER DEFAULT 0,
            height INTEGER DEFAULT 0,
            tags TEXT DEFAULT '', -- JSON格式的标签
            usage_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES image_libraries(id) ON DELETE CASCADE
        );

        -- AI知识库表
        CREATE TABLE IF NOT EXISTS knowledge_bases (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '',
            content TEXT DEFAULT '',
            file_type VARCHAR(20) DEFAULT 'markdown', -- markdown, word, text
            file_path VARCHAR(500) DEFAULT '',
            word_count INTEGER DEFAULT 0,
            usage_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS knowledge_chunks (
            id BIGSERIAL PRIMARY KEY,
            knowledge_base_id BIGINT NOT NULL,
            chunk_index INTEGER NOT NULL,
            content TEXT NOT NULL,
            content_hash VARCHAR(64) DEFAULT '',
            token_count INTEGER DEFAULT 0,
            embedding_json TEXT DEFAULT '',
            embedding_model_id INTEGER DEFAULT NULL,
            embedding_dimensions INTEGER DEFAULT 0,
            embedding_provider VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (knowledge_base_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE,
            UNIQUE(knowledge_base_id, chunk_index)
        );

        -- URL智能采集任务表
        CREATE TABLE IF NOT EXISTS url_import_jobs (
            id BIGSERIAL PRIMARY KEY,
            url TEXT NOT NULL,
            normalized_url TEXT NOT NULL,
            source_domain VARCHAR(255) DEFAULT '',
            page_title VARCHAR(255) DEFAULT '',
            status VARCHAR(20) DEFAULT 'queued', -- queued, running, completed, failed
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

        -- URL智能采集任务日志表
        CREATE TABLE IF NOT EXISTS url_import_job_logs (
            id BIGSERIAL PRIMARY KEY,
            job_id INTEGER NOT NULL,
            level VARCHAR(20) DEFAULT 'info',
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (job_id) REFERENCES url_import_jobs(id) ON DELETE CASCADE
        );
        ";

        try {
            $this->pdo->exec($sql);
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_base ON knowledge_chunks(knowledge_base_id, chunk_index)");
        } catch (PDOException $e) {
            die('创建数据表失败: ' . $e->getMessage());
        }
    }

    private function insertDefaultData() {
        // 检查是否已有数据
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM admins");
        $count = $stmt->fetch()['count'];
        
        if ($count > 0) {
            return; // 已有数据，不需要插入默认数据
        }

        $bootstrapPassword = getenv('ADMIN_BOOTSTRAP_PASSWORD');
        if (!$bootstrapPassword) {
            $bootstrapPassword = 'admin888';
            error_log('GEO后台默认管理员密码已设置：admin / ' . $bootstrapPassword . '，请首次登录后立即修改。');
        }

        $sql = "
        -- 插入默认管理员
        INSERT INTO admins (username, password, display_name, role, status, updated_at) VALUES ('admin', '" . password_hash($bootstrapPassword, PASSWORD_DEFAULT) . "', '系统超级管理员', 'super_admin', 'active', CURRENT_TIMESTAMP);

        -- 插入网站设置
        INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES
        ('site_name', 'GEO+AI内容生成系统', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
        ('site_description', '基于AI的智能内容生成与发布平台', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
        ('site_keywords', 'AI,内容生成,SEO,GEO', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
        ('copyright_info', '© 2025 GEO+AI内容生成系统. All rights reserved.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

        -- 插入默认提示词
        INSERT INTO prompts (name, type, content, variables) VALUES 
        ('默认标题生成', 'title', '请根据关键词\"{{keyword}}\"生成5个吸引人的文章标题。要求：\n1. 标题要有吸引力和点击欲望\n2. 包含关键词但不生硬\n3. 字数控制在15-30字之间\n4. 适合SEO优化\n5. 符合中文表达习惯\n\n请直接输出标题列表，每行一个标题。', 'keyword'),
        ('默认内容生成', 'content', '请根据标题\"{{title}}\"和关键词\"{{keyword}}\"写一篇详细的文章。\n\n要求：\n1. 文章结构清晰，包含引言、正文和结论\n2. 内容丰富，字数在800-1500字之间\n3. 自然融入关键词，避免堆砌\n4. 使用Markdown格式，包含适当的标题层级\n5. 内容要有价值，对读者有帮助\n\n{{#if Knowledge}}参考知识：\n{{Knowledge}}{{/if}}\n\n请开始写作：', 'title,keyword,Knowledge'),
        ('默认关键词提取', 'keyword', '请从以下文章内容中提取5-10个最重要的关键词，用逗号分隔：\n\n{{content}}', 'content'),
        ('默认描述生成', 'description', '请为以下文章内容生成一个简洁的描述，长度控制在120-160字符以内，适合用作SEO描述：\n\n{{content}}', 'content');

        -- 插入默认分类
        INSERT INTO categories (name, slug, description) VALUES 
        ('科技资讯', 'tech-news', '最新的科技动态和资讯'),
        ('人工智能', 'artificial-intelligence', 'AI技术和应用相关内容'),
        ('互联网', 'internet', '互联网行业动态和趋势');

        -- 插入默认作者
        INSERT INTO authors (name, bio) VALUES 
        ('AI编辑', 'AI智能编辑，专注于科技内容创作'),
        ('科技观察员', '资深科技行业观察者'),
        ('数码评测师', '专业数码产品评测专家');
        ";

        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            // 忽略插入错误，可能是重复插入
        }
    }

    private function ensureTaskQueueSchema() {
        $columnsToAdd = [
            'last_run_at' => "ALTER TABLE tasks ADD COLUMN last_run_at TIMESTAMP DEFAULT NULL",
            'next_run_at' => "ALTER TABLE tasks ADD COLUMN next_run_at TIMESTAMP DEFAULT NULL",
            'last_success_at' => "ALTER TABLE tasks ADD COLUMN last_success_at TIMESTAMP DEFAULT NULL",
            'last_error_at' => "ALTER TABLE tasks ADD COLUMN last_error_at TIMESTAMP DEFAULT NULL",
            'last_error_message' => "ALTER TABLE tasks ADD COLUMN last_error_message TEXT DEFAULT ''",
            'schedule_enabled' => "ALTER TABLE tasks ADD COLUMN schedule_enabled INTEGER DEFAULT 1",
            'max_retry_count' => "ALTER TABLE tasks ADD COLUMN max_retry_count INTEGER DEFAULT 3",
        ];

        foreach ($columnsToAdd as $column => $sql) {
            if (!db_column_exists($this->pdo, 'tasks', $column)) {
                $this->pdo->exec($sql);
            }
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS job_queue (
                id BIGSERIAL PRIMARY KEY,
                task_id INTEGER NOT NULL,
                job_type VARCHAR(50) NOT NULL DEFAULT 'generate_article',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                payload TEXT DEFAULT '',
                attempt_count INTEGER DEFAULT 0,
                max_attempts INTEGER DEFAULT 3,
                available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                claimed_at TIMESTAMP DEFAULT NULL,
                finished_at TIMESTAMP DEFAULT NULL,
                worker_id VARCHAR(100) DEFAULT '',
                error_message TEXT DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS task_runs (
                id BIGSERIAL PRIMARY KEY,
                task_id INTEGER NOT NULL,
                job_id INTEGER DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                article_id INTEGER DEFAULT NULL,
                error_message TEXT DEFAULT '',
                duration_ms INTEGER DEFAULT 0,
                meta TEXT DEFAULT '',
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                finished_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                FOREIGN KEY (job_id) REFERENCES job_queue(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS worker_heartbeats (
                worker_id VARCHAR(100) PRIMARY KEY,
                status VARCHAR(20) NOT NULL DEFAULT 'idle',
                current_job_id INTEGER DEFAULT NULL,
                last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                meta TEXT DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (current_job_id) REFERENCES job_queue(id) ON DELETE SET NULL
            );

            CREATE INDEX IF NOT EXISTS idx_job_queue_status_available ON job_queue(status, available_at);
            CREATE INDEX IF NOT EXISTS idx_job_queue_task ON job_queue(task_id);
            CREATE INDEX IF NOT EXISTS idx_task_runs_task ON task_runs(task_id);
            CREATE INDEX IF NOT EXISTS idx_task_runs_status ON task_runs(status);
            CREATE INDEX IF NOT EXISTS idx_worker_heartbeats_last_seen ON worker_heartbeats(last_seen_at);
        ");
    }

    private function ensureApiSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS api_tokens (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                token_hash VARCHAR(255) NOT NULL UNIQUE,
                scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_by_admin_id BIGINT DEFAULT NULL,
                last_used_at TIMESTAMP DEFAULT NULL,
                expires_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS api_idempotency_keys (
                id BIGSERIAL PRIMARY KEY,
                idempotency_key VARCHAR(120) NOT NULL,
                route_key VARCHAR(120) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                response_body TEXT NOT NULL,
                response_status INTEGER NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (idempotency_key, route_key)
            );

            CREATE INDEX IF NOT EXISTS idx_api_tokens_status ON api_tokens(status);
            CREATE INDEX IF NOT EXISTS idx_api_tokens_expires_at ON api_tokens(expires_at);
            CREATE INDEX IF NOT EXISTS idx_api_tokens_created_by ON api_tokens(created_by_admin_id, created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_api_idempotency_created_at ON api_idempotency_keys(created_at DESC);
        ");
    }

    private function ensureCompatibilitySchema() {
        $columnsToAdd = [
            'site_settings' => [
                'setting_key' => "ALTER TABLE site_settings ADD COLUMN setting_key VARCHAR(100) DEFAULT ''",
                'setting_value' => "ALTER TABLE site_settings ADD COLUMN setting_value TEXT DEFAULT ''",
            ],
            'tasks' => [
                'author_id' => "ALTER TABLE tasks ADD COLUMN author_id INTEGER DEFAULT NULL",
                'prompt_id' => "ALTER TABLE tasks ADD COLUMN prompt_id INTEGER DEFAULT NULL",
                'knowledge_base_id' => "ALTER TABLE tasks ADD COLUMN knowledge_base_id INTEGER DEFAULT NULL",
                'category_mode' => "ALTER TABLE tasks ADD COLUMN category_mode VARCHAR(20) DEFAULT 'smart'",
                'fixed_category_id' => "ALTER TABLE tasks ADD COLUMN fixed_category_id INTEGER DEFAULT NULL",
            ],
            'admins' => [
                'display_name' => "ALTER TABLE admins ADD COLUMN display_name VARCHAR(100) DEFAULT ''",
                'role' => "ALTER TABLE admins ADD COLUMN role VARCHAR(20) DEFAULT 'admin'",
                'status' => "ALTER TABLE admins ADD COLUMN status VARCHAR(20) DEFAULT 'active'",
                'created_by' => "ALTER TABLE admins ADD COLUMN created_by INTEGER DEFAULT NULL",
                'updated_at' => "ALTER TABLE admins ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            ],
            'image_libraries' => [
                'description' => "ALTER TABLE image_libraries ADD COLUMN description TEXT DEFAULT ''",
            ],
            'images' => [
                'file_name' => "ALTER TABLE images ADD COLUMN file_name VARCHAR(255) DEFAULT ''",
                'width' => "ALTER TABLE images ADD COLUMN width INTEGER DEFAULT 0",
                'height' => "ALTER TABLE images ADD COLUMN height INTEGER DEFAULT 0",
                'tags' => "ALTER TABLE images ADD COLUMN tags TEXT DEFAULT ''",
                'usage_count' => "ALTER TABLE images ADD COLUMN usage_count INTEGER DEFAULT 0",
            ],
            'keyword_libraries' => [
                'description' => "ALTER TABLE keyword_libraries ADD COLUMN description TEXT DEFAULT ''",
            ],
            'keywords' => [
                'usage_count' => "ALTER TABLE keywords ADD COLUMN usage_count INTEGER DEFAULT 0",
            ],
            'title_libraries' => [
                'description' => "ALTER TABLE title_libraries ADD COLUMN description TEXT DEFAULT ''",
                'is_ai_generated' => "ALTER TABLE title_libraries ADD COLUMN is_ai_generated INTEGER DEFAULT 0",
            ],
            'titles' => [
                'keyword' => "ALTER TABLE titles ADD COLUMN keyword VARCHAR(200) DEFAULT ''",
                'is_ai_generated' => "ALTER TABLE titles ADD COLUMN is_ai_generated BOOLEAN DEFAULT FALSE",
                'used_count' => "ALTER TABLE titles ADD COLUMN used_count INTEGER DEFAULT 0",
            ],
            'knowledge_bases' => [
                'description' => "ALTER TABLE knowledge_bases ADD COLUMN description TEXT DEFAULT ''",
                'file_type' => "ALTER TABLE knowledge_bases ADD COLUMN file_type VARCHAR(20) DEFAULT 'markdown'",
                'file_path' => "ALTER TABLE knowledge_bases ADD COLUMN file_path VARCHAR(500) DEFAULT ''",
                'word_count' => "ALTER TABLE knowledge_bases ADD COLUMN word_count INTEGER DEFAULT 0",
                'usage_count' => "ALTER TABLE knowledge_bases ADD COLUMN usage_count INTEGER DEFAULT 0",
            ],
            'ai_models' => [
                'model_type' => "ALTER TABLE ai_models ADD COLUMN model_type VARCHAR(20) DEFAULT 'chat'",
            ],
            'authors' => [
                'email' => "ALTER TABLE authors ADD COLUMN email VARCHAR(100) DEFAULT ''",
                'avatar' => "ALTER TABLE authors ADD COLUMN avatar VARCHAR(200) DEFAULT ''",
                'website' => "ALTER TABLE authors ADD COLUMN website VARCHAR(200) DEFAULT ''",
                'social_links' => "ALTER TABLE authors ADD COLUMN social_links TEXT DEFAULT ''",
                'updated_at' => "ALTER TABLE authors ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            ],
            'knowledge_chunks' => [
                'embedding_model_id' => "ALTER TABLE knowledge_chunks ADD COLUMN embedding_model_id INTEGER DEFAULT NULL",
                'embedding_dimensions' => "ALTER TABLE knowledge_chunks ADD COLUMN embedding_dimensions INTEGER DEFAULT 0",
                'embedding_provider' => "ALTER TABLE knowledge_chunks ADD COLUMN embedding_provider VARCHAR(255) DEFAULT ''",
            ],
        ];

        foreach ($columnsToAdd as $table => $definitions) {
            foreach ($definitions as $column => $sql) {
                if (!db_column_exists($this->pdo, $table, $column)) {
                    $this->pdo->exec($sql);
                }
            }
        }

        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_site_settings_key ON site_settings(setting_key)");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS knowledge_chunks (
                id BIGSERIAL PRIMARY KEY,
                knowledge_base_id BIGINT NOT NULL,
                chunk_index INTEGER NOT NULL,
                content TEXT NOT NULL,
                content_hash VARCHAR(64) DEFAULT '',
                token_count INTEGER DEFAULT 0,
                embedding_json TEXT DEFAULT '',
                embedding_model_id INTEGER DEFAULT NULL,
                embedding_dimensions INTEGER DEFAULT 0,
                embedding_provider VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (knowledge_base_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE,
                UNIQUE(knowledge_base_id, chunk_index)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_base ON knowledge_chunks(knowledge_base_id, chunk_index)");
        $this->pdo->exec("UPDATE ai_models SET model_type = COALESCE(NULLIF(model_type, ''), 'chat')");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin_activity_logs (
            id BIGSERIAL PRIMARY KEY,
            admin_id INTEGER DEFAULT NULL,
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
        )");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_activity_logs_admin ON admin_activity_logs(admin_id, created_at DESC)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_activity_logs_created ON admin_activity_logs(created_at DESC)");
        $this->pdo->exec("UPDATE admins SET status = COALESCE(NULLIF(status, ''), 'active'), updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)");

        $superAdminCount = (int) $this->pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'")->fetchColumn();
        if ($superAdminCount === 0) {
            $firstAdminId = (int) $this->pdo->query("SELECT id FROM admins ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($firstAdminId > 0) {
                $stmt = $this->pdo->prepare("UPDATE admins SET role = 'super_admin', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$firstAdminId]);
            }
        }
    }

    private function ensurePgvectorSchema(): void {
        try {
            $this->pdo->exec("CREATE EXTENSION IF NOT EXISTS vector");
        } catch (Throwable $e) {
            error_log('pgvector 扩展初始化失败，将继续使用文本检索回退: ' . $e->getMessage());
            return;
        }

        try {
            $stmt = $this->pdo->query("
                SELECT EXISTS (
                    SELECT 1
                    FROM pg_type
                    WHERE typname = 'vector'
                )
            ");
            $vectorAvailable = (bool) ($stmt ? $stmt->fetchColumn() : false);
        } catch (Throwable $e) {
            error_log('pgvector 可用性检查失败: ' . $e->getMessage());
            return;
        }

        if (!$vectorAvailable) {
            return;
        }

        try {
            if (!db_column_exists($this->pdo, 'knowledge_chunks', 'embedding_vector')) {
                $this->pdo->exec("ALTER TABLE knowledge_chunks ADD COLUMN embedding_vector vector(3072)");
            }

            $this->pdo->exec("
                CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_embedding_hnsw
                ON knowledge_chunks
                USING hnsw (embedding_vector vector_cosine_ops)
                WHERE embedding_vector IS NOT NULL
            ");
        } catch (Throwable $e) {
            error_log('pgvector 向量列或索引初始化失败: ' . $e->getMessage());
        }
    }
}

// 创建全局数据库连接
try {
    if (!class_exists('DatabaseNew', false)) {
        class_alias(DatabaseAdmin::class, 'DatabaseNew');
    }

    $db = DatabaseAdmin::getInstance()->getPDO();
} catch (Exception $e) {
    die('数据库初始化失败: ' . $e->getMessage());
}
?>
