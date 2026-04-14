<?php
/**
 * 个人博客系统 - 数据库操作类
 *
 * @author 姚金刚
 * @version 2.0
 * @date 2025-10-05
 */

// 防止直接访问
if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

// 引入安全模块
require_once __DIR__ . '/security.php';

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $this->connect();
        $this->createTables();
        $this->ensureTaskQueueSchema();
        $this->ensureCompatibilitySchema();
        $this->ensureIndexes();
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
        -- 分类表（博客分类）
        CREATE TABLE IF NOT EXISTS categories (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) UNIQUE NOT NULL,
            description TEXT DEFAULT '',
            sort_order INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 标签表
        CREATE TABLE IF NOT EXISTS tags (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            slug VARCHAR(50) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 文章表（支持AI生成和手动创建）
        CREATE TABLE IF NOT EXISTS articles (
            id BIGSERIAL PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            slug VARCHAR(200) UNIQUE NOT NULL,
            excerpt TEXT DEFAULT '',
            content TEXT NOT NULL,
            category_id INTEGER NOT NULL,
            author_id INTEGER DEFAULT 1,
            task_id INTEGER DEFAULT NULL, -- 关联的任务ID，NULL表示手动创建
            original_keyword VARCHAR(200) DEFAULT '', -- 原始关键词
            keywords TEXT DEFAULT '', -- SEO关键词
            meta_description TEXT DEFAULT '', -- SEO描述
            status VARCHAR(20) DEFAULT 'draft', -- draft, published, private, deleted
            review_status VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected, auto_approved
            is_featured INTEGER DEFAULT 0,
            view_count INTEGER DEFAULT 0,
            like_count INTEGER DEFAULT 0,
            comment_count INTEGER DEFAULT 0,
            is_ai_generated INTEGER DEFAULT 0, -- 是否AI生成
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            published_at TIMESTAMP DEFAULT NULL,
            deleted_at TIMESTAMP DEFAULT NULL,
            FOREIGN KEY (category_id) REFERENCES categories(id),
            FOREIGN KEY (author_id) REFERENCES authors(id),
            FOREIGN KEY (task_id) REFERENCES tasks(id)
        );

        -- 文章标签关联表
        CREATE TABLE IF NOT EXISTS article_tags (
            id BIGSERIAL PRIMARY KEY,
            article_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
            UNIQUE(article_id, tag_id)
        );

        -- 评论表
        CREATE TABLE IF NOT EXISTS comments (
            id BIGSERIAL PRIMARY KEY,
            article_id INTEGER NOT NULL,
            parent_id INTEGER DEFAULT NULL,
            author_name VARCHAR(100) NOT NULL,
            author_email VARCHAR(100) NOT NULL,
            author_website VARCHAR(200) DEFAULT '',
            content TEXT NOT NULL,
            status INTEGER DEFAULT 0, -- 0: 待审核, 1: 已通过, 2: 已拒绝
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
            FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
        );

        -- 网站配置表
        CREATE TABLE IF NOT EXISTS settings (
            key VARCHAR(100) PRIMARY KEY,
            value TEXT
        );

        -- 管理员表
        CREATE TABLE IF NOT EXISTS admins (
            id BIGSERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            display_name VARCHAR(100) DEFAULT '',
            email VARCHAR(100) DEFAULT '',
            bio TEXT DEFAULT '',
            avatar VARCHAR(200) DEFAULT '',
            website VARCHAR(200) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 阅读日志表
        CREATE TABLE IF NOT EXISTS view_logs (
            id BIGSERIAL PRIMARY KEY,
            article_id INTEGER,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (article_id) REFERENCES articles(id)
        );

        -- ========== AI内容生成系统相关表 ==========

        -- 任务表
        CREATE TABLE IF NOT EXISTS tasks (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            title_library_id INTEGER NOT NULL,
            image_library_id INTEGER DEFAULT NULL,
            image_count INTEGER DEFAULT 0, -- 配图数量
            prompt_id INTEGER NOT NULL, -- 内容提示词ID
            ai_model_id INTEGER NOT NULL,
            author_id INTEGER DEFAULT NULL, -- NULL表示随机选择
            need_review INTEGER DEFAULT 1, -- 是否需要人工审核
            publish_interval INTEGER DEFAULT 3600, -- 发布间隔（秒）
            auto_keywords INTEGER DEFAULT 1, -- 自动提取关键词
            auto_description INTEGER DEFAULT 1, -- 自动生成描述
            draft_limit INTEGER DEFAULT 10, -- 草稿数量限制
            is_loop INTEGER DEFAULT 0, -- 是否循环生成
            status VARCHAR(20) DEFAULT 'active', -- active, paused, completed
            created_count INTEGER DEFAULT 0, -- 已创建文章数
            published_count INTEGER DEFAULT 0, -- 已发布文章数
            loop_count INTEGER DEFAULT 0, -- 循环次数
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (title_library_id) REFERENCES title_libraries(id),
            FOREIGN KEY (image_library_id) REFERENCES image_libraries(id),
            FOREIGN KEY (prompt_id) REFERENCES prompts(id),
            FOREIGN KEY (ai_model_id) REFERENCES ai_models(id),
            FOREIGN KEY (author_id) REFERENCES authors(id)
        );

        -- 关键词库表
        CREATE TABLE IF NOT EXISTS keyword_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            keyword_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 关键词表
        CREATE TABLE IF NOT EXISTS keywords (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            keyword VARCHAR(200) NOT NULL,
            used_count INTEGER DEFAULT 0, -- 使用次数
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES keyword_libraries(id) ON DELETE CASCADE
        );

        -- 标题库表
        CREATE TABLE IF NOT EXISTS title_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            title_count INTEGER DEFAULT 0,
            generation_type VARCHAR(20) DEFAULT 'manual', -- manual, ai_generated
            keyword_library_id INTEGER DEFAULT NULL, -- AI生成时使用的关键词库
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (keyword_library_id) REFERENCES keyword_libraries(id)
        );

        -- 标题表
        CREATE TABLE IF NOT EXISTS titles (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            title VARCHAR(500) NOT NULL,
            keyword VARCHAR(200) DEFAULT '', -- 关联的关键词
            used_count INTEGER DEFAULT 0, -- 使用次数
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES title_libraries(id) ON DELETE CASCADE
        );

        -- 图片库表
        CREATE TABLE IF NOT EXISTS image_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            image_count INTEGER DEFAULT 0,
            used_task_count INTEGER DEFAULT 0, -- 使用的任务数
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
            used_count INTEGER DEFAULT 0, -- 使用次数
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES image_libraries(id) ON DELETE CASCADE
        );

        -- AI知识库表
        CREATE TABLE IF NOT EXISTS knowledge_bases (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            content TEXT NOT NULL,
            character_count INTEGER DEFAULT 0,
            used_task_count INTEGER DEFAULT 0, -- 使用的任务数
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 作者库表
        CREATE TABLE IF NOT EXISTS authors (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            bio TEXT DEFAULT '',
            email VARCHAR(100) DEFAULT '',
            avatar VARCHAR(200) DEFAULT '',
            website VARCHAR(200) DEFAULT '',
            social_links TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- AI模型配置表
        CREATE TABLE IF NOT EXISTS ai_models (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            version VARCHAR(100) DEFAULT '',
            api_key VARCHAR(500) NOT NULL,
            model_id VARCHAR(200) NOT NULL,
            api_url VARCHAR(500) DEFAULT 'https://api.tu-zi.com',
            daily_limit INTEGER DEFAULT 0, -- 每日调用限制，0为不限制
            used_today INTEGER DEFAULT 0, -- 今日已使用次数
            total_used INTEGER DEFAULT 0, -- 总使用次数
            status VARCHAR(20) DEFAULT 'active', -- active, inactive
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 提示词配置表
        CREATE TABLE IF NOT EXISTS prompts (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            type VARCHAR(50) DEFAULT 'content', -- content, title, keyword, description
            content TEXT NOT NULL,
            variables TEXT DEFAULT '', -- 支持的变量列表，JSON格式
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 敏感词库表
        CREATE TABLE IF NOT EXISTS sensitive_words (
            id BIGSERIAL PRIMARY KEY,
            word VARCHAR(200) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 任务调度表
        CREATE TABLE IF NOT EXISTS task_schedules (
            id BIGSERIAL PRIMARY KEY,
            task_id INTEGER NOT NULL,
            next_run_time TIMESTAMP NOT NULL,
            last_run_time TIMESTAMP DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'pending', -- pending, running, completed, failed
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        );

        -- 文章生成队列表
        CREATE TABLE IF NOT EXISTS article_queue (
            id BIGSERIAL PRIMARY KEY,
            task_id INTEGER NOT NULL,
            title_id INTEGER NOT NULL,
            keyword VARCHAR(200) DEFAULT '',
            status VARCHAR(20) DEFAULT 'pending', -- pending, generating, completed, failed
            error_message TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (title_id) REFERENCES titles(id)
        );

        -- 任务使用素材关联表
        CREATE TABLE IF NOT EXISTS task_materials (
            id BIGSERIAL PRIMARY KEY,
            task_id INTEGER NOT NULL,
            material_type VARCHAR(50) NOT NULL, -- keyword_library, image_library, knowledge_base
            material_id INTEGER NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        );

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

        CREATE TABLE IF NOT EXISTS url_import_job_logs (
            id BIGSERIAL PRIMARY KEY,
            job_id INTEGER NOT NULL,
            level VARCHAR(20) DEFAULT 'info',
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (job_id) REFERENCES url_import_jobs(id) ON DELETE CASCADE
        );

        ";
        
        $this->pdo->exec($sql);
    }
    
    private function insertDefaultData() {
        // 检查是否已有数据
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM categories");
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            return; // 已有数据，不需要插入默认数据
        }
        
        // 插入默认分类（适合AI内容生成）
        $categories = [
            ['name' => '科技资讯', 'slug' => 'tech-news', 'description' => '最新科技动态和资讯', 'sort_order' => 1],
            ['name' => '人工智能', 'slug' => 'ai', 'description' => 'AI技术发展和应用', 'sort_order' => 2],
            ['name' => '互联网', 'slug' => 'internet', 'description' => '互联网行业动态', 'sort_order' => 3],
            ['name' => '数码产品', 'slug' => 'digital', 'description' => '数码产品评测和推荐', 'sort_order' => 4],
            ['name' => '编程开发', 'slug' => 'programming', 'description' => '编程技术和开发经验', 'sort_order' => 5],
            ['name' => '创业投资', 'slug' => 'startup', 'description' => '创业故事和投资资讯', 'sort_order' => 6]
        ];

        $stmt = $this->pdo->prepare("INSERT INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)");
        foreach ($categories as $category) {
            $stmt->execute([$category['name'], $category['slug'], $category['description'], $category['sort_order']]);
        }

        // 插入默认标签
        $tags = [
            ['name' => '人工智能', 'slug' => 'ai'],
            ['name' => '机器学习', 'slug' => 'machine-learning'],
            ['name' => '深度学习', 'slug' => 'deep-learning'],
            ['name' => '大数据', 'slug' => 'big-data'],
            ['name' => '云计算', 'slug' => 'cloud-computing'],
            ['name' => '区块链', 'slug' => 'blockchain'],
            ['name' => '物联网', 'slug' => 'iot'],
            ['name' => '5G', 'slug' => '5g'],
            ['name' => '自动驾驶', 'slug' => 'autonomous-driving'],
            ['name' => '虚拟现实', 'slug' => 'vr']
        ];

        $stmt = $this->pdo->prepare("INSERT INTO tags (name, slug) VALUES (?, ?)");
        foreach ($tags as $tag) {
            $stmt->execute([$tag['name'], $tag['slug']]);
        }
        
        // 插入默认管理员（姚金刚）
        $stmt = $this->pdo->prepare("INSERT INTO admins (username, password, display_name, email, bio, website) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            ADMIN_USERNAME,
            ADMIN_PASSWORD,
            '姚金刚',
            'yaodashuai@example.com',
            '资深技术专家，专注于Web开发、系统架构设计和团队管理。热爱分享技术经验，致力于推动技术创新和团队成长。',
            'https://github.com/yaodashuai'
        ]);

        // 插入默认设置
        global $default_settings;
        $stmt = $this->pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?)");
        foreach ($default_settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        // 插入示例AI模型配置（开源安全占位，不包含真实密钥）
        $stmt = $this->pdo->prepare("INSERT INTO ai_models (name, version, api_key, model_id, api_url, daily_limit, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'Sample AI Model',
            'example',
            '',
            'replace-with-your-model-id',
            'https://api.openai.com/v1/chat/completions',
            0,
            'inactive'
        ]);

        // 插入默认提示词
        $default_prompts = [
            [
                'name' => '默认内容生成提示词',
                'type' => 'content',
                'content' => '请根据标题"{{title}}"和关键词"{{keyword}}"，写一篇详细的文章。文章应该包含以下要求：
1. 文章结构清晰，包含引言、主体内容和结论
2. 内容专业且有价值，字数在800-1500字之间
3. 使用Markdown格式，包含适当的标题层级
4. 内容要原创且符合SEO优化要求
5. 语言流畅自然，适合中文读者阅读

{{#if Knowledge}}
参考知识：{{Knowledge}}
{{/if}}

请直接输出文章内容，不需要额外说明。'
            ],
            [
                'name' => '标题生成提示词',
                'type' => 'title',
                'content' => '请根据关键词"{{keyword}}"生成5个吸引人的文章标题。要求：
1. 标题要有吸引力和点击欲望
2. 包含关键词但不生硬
3. 字数控制在15-30字之间
4. 适合SEO优化
5. 符合中文表达习惯

请直接输出标题列表，每行一个标题。'
            ],
            [
                'name' => '关键词提取提示词',
                'type' => 'keyword',
                'content' => '请从以下文章内容中提取3-5个最重要的关键词，用逗号分隔：

{{content}}

要求：
1. 关键词要准确反映文章主题
2. 适合SEO优化
3. 避免过于宽泛的词汇
4. 优先选择有搜索价值的词汇

请直接输出关键词，用逗号分隔。'
            ],
            [
                'name' => '描述生成提示词',
                'type' => 'description',
                'content' => '请为以下文章内容生成一个简洁的描述，用于SEO meta description：

{{content}}

要求：
1. 字数控制在120-160字之间
2. 准确概括文章主要内容
3. 包含重要关键词
4. 语言吸引人，有点击欲望
5. 符合搜索引擎优化要求

请直接输出描述内容。'
            ]
        ];

        $stmt = $this->pdo->prepare("INSERT INTO prompts (name, type, content) VALUES (?, ?, ?)");
        foreach ($default_prompts as $prompt) {
            $stmt->execute([$prompt['name'], $prompt['type'], $prompt['content']]);
        }

        // 插入默认作者
        $default_authors = [
            ['name' => '科技观察员', 'bio' => '专注科技资讯和行业分析'],
            ['name' => 'AI研究者', 'bio' => '人工智能领域专家'],
            ['name' => '数码评测师', 'bio' => '数码产品评测和推荐专家'],
            ['name' => '程序员小王', 'bio' => '全栈开发工程师'],
            ['name' => '创业导师', 'bio' => '创业投资领域观察者']
        ];

        $stmt = $this->pdo->prepare("INSERT INTO authors (name, bio) VALUES (?, ?)");
        foreach ($default_authors as $author) {
            $stmt->execute([$author['name'], $author['bio']]);
        }

        // 插入示例关键词库
        $stmt = $this->pdo->prepare("INSERT INTO keyword_libraries (name, keyword_count) VALUES (?, ?)");
        $stmt->execute(['科技热词库', 10]);
        $keyword_library_id = db_last_insert_id($this->pdo, 'keyword_libraries');

        $sample_keywords = [
            '人工智能', '机器学习', '深度学习', '大数据', '云计算',
            '区块链', '物联网', '5G技术', '自动驾驶', '虚拟现实'
        ];

        $stmt = $this->pdo->prepare("INSERT INTO keywords (library_id, keyword) VALUES (?, ?)");
        foreach ($sample_keywords as $keyword) {
            $stmt->execute([$keyword_library_id, $keyword]);
        }

        // 插入示例标题库
        $stmt = $this->pdo->prepare("INSERT INTO title_libraries (name, title_count, generation_type) VALUES (?, ?, ?)");
        $stmt->execute(['科技资讯标题库', 5, 'manual']);
        $title_library_id = db_last_insert_id($this->pdo, 'title_libraries');

        $sample_titles = [
            '2025年人工智能发展趋势预测',
            '机器学习在企业中的实际应用案例',
            '深度学习技术的最新突破',
            '大数据时代的隐私保护挑战',
            '云计算如何改变传统IT架构'
        ];

        $stmt = $this->pdo->prepare("INSERT INTO titles (library_id, title, keyword) VALUES (?, ?, ?)");
        foreach ($sample_titles as $index => $title) {
            $keyword = $sample_keywords[$index] ?? '';
            $stmt->execute([$title_library_id, $title, $keyword]);
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

    private function ensureCompatibilitySchema() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS site_settings (
                id BIGSERIAL PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_site_settings_key ON site_settings(setting_key);
        ");

        $columnsToAdd = [
            'tasks' => [
                'knowledge_base_id' => "ALTER TABLE tasks ADD COLUMN knowledge_base_id INTEGER DEFAULT NULL",
                'category_mode' => "ALTER TABLE tasks ADD COLUMN category_mode VARCHAR(20) DEFAULT 'smart'",
                'fixed_category_id' => "ALTER TABLE tasks ADD COLUMN fixed_category_id INTEGER DEFAULT NULL",
                'author_type' => "ALTER TABLE tasks ADD COLUMN author_type VARCHAR(20) DEFAULT 'random'",
                'custom_author_id' => "ALTER TABLE tasks ADD COLUMN custom_author_id INTEGER DEFAULT NULL",
                'content_prompt_id' => "ALTER TABLE tasks ADD COLUMN content_prompt_id INTEGER DEFAULT NULL",
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
                'ai_model_id' => "ALTER TABLE title_libraries ADD COLUMN ai_model_id INTEGER DEFAULT NULL",
                'prompt_id' => "ALTER TABLE title_libraries ADD COLUMN prompt_id INTEGER DEFAULT NULL",
                'generation_rounds' => "ALTER TABLE title_libraries ADD COLUMN generation_rounds INTEGER DEFAULT 1",
            ],
            'titles' => [
                'is_ai_generated' => "ALTER TABLE titles ADD COLUMN is_ai_generated BOOLEAN DEFAULT FALSE",
                'usage_count' => "ALTER TABLE titles ADD COLUMN usage_count INTEGER DEFAULT 0",
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
            'knowledge_bases' => [
                'description' => "ALTER TABLE knowledge_bases ADD COLUMN description TEXT DEFAULT ''",
                'file_type' => "ALTER TABLE knowledge_bases ADD COLUMN file_type VARCHAR(20) DEFAULT 'markdown'",
                'file_path' => "ALTER TABLE knowledge_bases ADD COLUMN file_path VARCHAR(500) DEFAULT ''",
                'word_count' => "ALTER TABLE knowledge_bases ADD COLUMN word_count INTEGER DEFAULT 0",
                'usage_count' => "ALTER TABLE knowledge_bases ADD COLUMN usage_count INTEGER DEFAULT 0",
            ],
            'articles' => [
                'is_featured' => "ALTER TABLE articles ADD COLUMN is_featured INTEGER DEFAULT 0",
                'like_count' => "ALTER TABLE articles ADD COLUMN like_count INTEGER DEFAULT 0",
                'comment_count' => "ALTER TABLE articles ADD COLUMN comment_count INTEGER DEFAULT 0",
                'featured_image' => "ALTER TABLE articles ADD COLUMN featured_image VARCHAR(500) DEFAULT ''",
            ],
            'authors' => [
                'email' => "ALTER TABLE authors ADD COLUMN email VARCHAR(100) DEFAULT ''",
                'updated_at' => "ALTER TABLE authors ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                'avatar' => "ALTER TABLE authors ADD COLUMN avatar VARCHAR(200) DEFAULT ''",
                'website' => "ALTER TABLE authors ADD COLUMN website VARCHAR(200) DEFAULT ''",
                'social_links' => "ALTER TABLE authors ADD COLUMN social_links TEXT DEFAULT ''",
            ],
        ];

        foreach ($columnsToAdd as $table => $definitions) {
            foreach ($definitions as $column => $sql) {
                if (!db_column_exists($this->pdo, $table, $column)) {
                    $this->pdo->exec($sql);
                }
            }
        }
    }

    private function ensureIndexes() {
        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_articles_category ON articles(category_id);
            CREATE INDEX IF NOT EXISTS idx_articles_status ON articles(status);
            CREATE INDEX IF NOT EXISTS idx_articles_featured ON articles(is_featured);
            CREATE INDEX IF NOT EXISTS idx_articles_published ON articles(published_at);
            CREATE INDEX IF NOT EXISTS idx_articles_created ON articles(created_at);
            CREATE INDEX IF NOT EXISTS idx_categories_sort ON categories(sort_order);
            CREATE INDEX IF NOT EXISTS idx_categories_slug ON categories(slug);
            CREATE INDEX IF NOT EXISTS idx_tags_slug ON tags(slug);
            CREATE INDEX IF NOT EXISTS idx_comments_article ON comments(article_id);
            CREATE INDEX IF NOT EXISTS idx_comments_status ON comments(status);
            CREATE INDEX IF NOT EXISTS idx_comments_created ON comments(created_at);
            CREATE INDEX IF NOT EXISTS idx_view_logs_article ON view_logs(article_id);
            CREATE INDEX IF NOT EXISTS idx_view_logs_created ON view_logs(created_at);
            CREATE INDEX IF NOT EXISTS idx_articles_task ON articles(task_id);
            CREATE INDEX IF NOT EXISTS idx_articles_review_status ON articles(review_status);
            CREATE INDEX IF NOT EXISTS idx_articles_ai_generated ON articles(is_ai_generated);
            CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status);
            CREATE INDEX IF NOT EXISTS idx_tasks_created ON tasks(created_at);
            CREATE INDEX IF NOT EXISTS idx_keywords_library ON keywords(library_id);
            CREATE INDEX IF NOT EXISTS idx_titles_library ON titles(library_id);
            CREATE INDEX IF NOT EXISTS idx_images_library ON images(library_id);
            CREATE INDEX IF NOT EXISTS idx_task_schedules_task ON task_schedules(task_id);
            CREATE INDEX IF NOT EXISTS idx_task_schedules_next_run ON task_schedules(next_run_time);
            CREATE INDEX IF NOT EXISTS idx_article_queue_task ON article_queue(task_id);
            CREATE INDEX IF NOT EXISTS idx_article_queue_status ON article_queue(status);
            CREATE INDEX IF NOT EXISTS idx_task_materials_task ON task_materials(task_id);
            CREATE INDEX IF NOT EXISTS idx_ai_models_status ON ai_models(status);
        ");
    }
    
    // 通用查询方法
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            write_log("Database query error: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    // 获取单条记录
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    // 获取多条记录
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    // 插入记录
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        $sql = "INSERT INTO {$table} (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        
        $stmt->execute();
        return db_last_insert_id($this->pdo, $table);
    }
    
    // 更新记录
    public function update($table, $data, $where, $whereParams = []) {
        $fields = [];
        $params = [];

        // 使用位置参数而不是命名参数
        foreach (array_keys($data) as $field) {
            $fields[] = "{$field} = ?";
            $params[] = $data[$field];
        }

        // 添加WHERE参数
        foreach ($whereParams as $param) {
            $params[] = $param;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $fields) . " WHERE {$where}";

        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($params);

        return $result;
    }
    
    // 删除记录
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
    
    // 获取记录总数
    public function count($table, $where = '1=1', $params = []) {
        $sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$where}";
        $result = $this->fetchOne($sql, $params);
        return $result['count'];
    }
}

// 全局数据库实例
$db = Database::getInstance()->getPDO();
