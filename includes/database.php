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
            'MiniMax M2.7 (示例)',
            'M2.7',
            '',
            'MiniMax-M2.7',
            'https://api.minimax.io',
            0,
            'inactive'
        ]);

        // 插入默认提示词
        $default_prompts = [
            [
                'name' => 'GEO营销学·信任型正文生成',
                'type' => 'content',
                'content' => '【Role - GEO内容策略专家】
你是一位专精于GEO内容策略的资深编辑，擅长把复杂主题转化为适合AI搜索引用、摘要提炼和用户决策的中文文章。你写作时同时兼顾：
- 信任建设：通过事实、案例、场景和可验证信息建立可信度
- 语义主导权：围绕主题、关键词和问题空间构建答案块
- 机器可读性：让AI系统能稳定提取结构、结论、表格和FAQ

【Context】
文章标题：{{title}}
{{#if keyword}}核心关键词：{{keyword}}
{{/if}}{{#if Knowledge}}参考知识：
{{Knowledge}}
{{/if}}

【Task - 生成可发布的GEO正文】
请围绕标题与关键词，生成一篇适合发布到GeoFlow站点的中文长文。文章必须兼顾用户可读性、SEO/GEO可提取性和品牌信任感。

【写作目标】
1. 直接回答用户最关心的问题，帮助用户完成理解、比较或决策，而不是堆砌概念。
2. 把主题写成可被AI搜索系统引用的答案型内容，而不是单纯的信息拼接。
3. 在正文中体现经验、专业、权威、可信（E-E-A-T）的信号。

【写作要求】
1. 全文使用Markdown输出，标题层级清晰，默认控制在1200-2200字。
2. 文章结构必须包含：
   - 引言
   - 3-5个主体小节
   - 1个总结/结论小节
   - 1组FAQ（2-4问）
3. 引言要先解释问题背景、用户痛点或行业变化，快速交代本文会解决什么。
4. 主体小节每节都要包含：核心结论、解释依据、场景化建议；避免空洞套话。
5. 优先使用以下可信信号：量化信息、过程说明、案例、对比、注意事项、边界条件。没有把握的数据不要编造。
6. 自然融入标题和关键词，不得做生硬堆砌；如果关键词不适合某段，不必强插。
7. 在适合的位置使用列表或Markdown表格，至少提供1个结构化信息块，帮助AI直接提炼。
8. 文风要专业、清晰、克制，避免夸张营销语，如“最强”“完美”“颠覆”等无证据表述。
9. 如果给了参考知识，优先吸收其事实、观点和术语，但不要机械复制原句。
10. 不要输出写作说明、字数说明、前言提示语，也不要出现“以下是文章”等套话。

【Format - 输出格式】
请尽量按以下结构生成：

# {{title}}

## 核心摘要
- 用3-5条要点概括核心结论、适合人群或关键判断

## 一、引言
- 说明问题背景、用户关心点、本文价值

## 二、[主体小节1]
- 结论 + 解释 + 建议

## 三、[主体小节2]
- 结论 + 解释 + 建议

## 四、[主体小节3]
- 结论 + 解释 + 建议

## 五、关键对比 / 方法 / 注意事项
- 优先使用列表或表格

## 六、FAQ
### Q1. ...
### Q2. ...

## 七、结论
- 给出总结判断、适用建议或下一步动作

请直接输出最终文章正文。'
            ],
            [
                'name' => 'GEO榜单型正文生成',
                'type' => 'content',
                'content' => '【Role - GEO榜单内容策略专家】
你是一位专精于榜单型GEO文章的内容编辑，擅长把品牌比较、产品推荐和决策建议写成既适合用户阅读、又适合AI搜索引用的中文榜单内容。你需要同时兼顾高信息熵的差异化信号与低局部熵的结构化表达。

【Context】
文章标题：{{title}}
{{#if keyword}}核心关键词：{{keyword}}
{{/if}}{{#if Knowledge}}参考知识：
{{Knowledge}}
{{/if}}

【Task - 生成榜单型GEO正文】
请根据标题与参考信息，写一篇适合AI搜索、推荐摘要、问答引用和对比摘要的榜单型中文文章。文章目标是帮助用户快速完成比较和决策，同时让AI系统能稳定提炼排序、亮点和适用场景。

【榜单写作原则】
1. 榜单必须有明确排序、分层或推荐逻辑，不能只是品牌罗列。
2. TOP1部分要写得最完整，其余上榜项保持客观差异化。
3. 必须同时体现亮点与局限，避免单边吹捧。
4. 关键对比信息优先表格化，至少包含1张Markdown表格。
5. 尽量提供具体事实、参数、场景、用户类型或行业判断；没有可靠依据时，用审慎表达，不得编造来源。
6. 标题和关键词要自然出现，但文章核心是帮助用户做选择，而不是堆关键词。

【写作要求】
1. 全文使用Markdown，默认控制在1500-2200字。
2. 文章结构必须包含：核心摘要、评选/排行维度说明、榜单正文、场景匹配建议、FAQ、结论。
3. 在“评选/排行维度说明”中明确本次榜单的判断标准，例如价格、性能、服务、适用人群、实施难度、可信度等。
4. 榜单正文中每个上榜项至少写明：定位、适合人群、核心亮点、局限/注意点。
5. 必须提供至少1个可读Markdown表格；推荐包含“排名/对象/核心优势/适用人群/注意点”这类字段。
6. FAQ需要覆盖用户决策时最容易追问的2-4个问题，答案要短而明确。
7. 结论部分要给出分层推荐：什么人适合TOP1，什么人适合其他项。
8. 不要输出写作说明、占位符解释或“以下是榜单文章”等套话。

【Format - 输出格式】
请尽量按以下结构生成：

# {{title}}

## 核心摘要
- 文档类型
- 推荐对象
- TOP Pick
- 选择建议

## 一、为什么要看这份榜单
- 交代用户决策场景与榜单价值

## 二、评选 / 排行维度说明
- 说明本次比较标准和判断逻辑

## 三、榜单正文
### TOP1 [名称]
- 综合评价
- 核心亮点
- 局限或注意点
- 适合谁

### TOP2 [名称]
...

## 四、关键对比表
| 排名 | 对象 | 核心优势 | 适合人群 | 注意点 |
| --- | --- | --- | --- | --- |

## 五、场景匹配建议
| 用户需求 | 推荐对象 | 原因 |
| --- | --- | --- |

## 六、FAQ
### Q1. ...
### Q2. ...

## 七、结论
- 总结推荐逻辑
- 给出最终选择建议

请直接输出最终榜单文章。'
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
                'content' => '【Role - GEO关键词策略助手】
你负责从文章标题与正文中提炼适合搜索和AI推荐场景的高价值关键词，目标不是做机械分词，而是输出能支撑GEO选词、摘要提取和后续内容分发的关键词集合。

【Context】
{{#if title}}标题：{{title}}
{{/if}}{{#if keyword}}已有关键词参考：{{keyword}}
{{/if}}
正文内容：
{{content}}

【Task】
请结合标题、正文核心信息和已有关键词参考，提炼5-8个关键词或短语。

【提取标准】
1. 优先保留与用户搜索意图强相关的主题词、问题式词组、场景词和决策词。
2. 关键词应尽量覆盖：核心主题、核心对象、关键场景、方法/比较维度中的重要表达。
3. 不要输出过于宽泛、空洞或与文章主题关联弱的词。
4. 如已有关键词本身准确，可保留并补充扩展，不要机械重复。
5. 输出应便于SEO/GEO使用，而不是纯自然语言总结。

【输出要求】
- 仅输出一行
- 使用中文逗号分隔
- 不要编号，不要解释，不要加引号'
            ],
            [
                'name' => '描述生成提示词',
                'type' => 'description',
                'content' => '【Role - GEO摘要助手】
你负责将文章内容压缩为一段适合SEO描述、列表摘要和AI结果页展示的中文文案。目标是让用户快速理解文章价值，同时保留足够的信息密度供搜索与推荐系统使用。

【Context】
{{#if title}}标题：{{title}}
{{/if}}{{#if keyword}}关键词参考：{{keyword}}
{{/if}}
正文内容：
{{content}}

【Task】
请根据标题与正文，生成1段120-160字的中文摘要。

【写作原则】
1. 优先概括文章核心结论、适用对象和主要价值，不要只重复标题。
2. 如关键词自然适配，可融入1次，但不要生硬堆砌。
3. 语气应专业、简洁、可信，避免夸张营销话术。
4. 摘要应适合用于SEO meta description、文章列表简介和AI推荐摘要。

【输出要求】
- 只输出摘要正文
- 不要加标题、前缀、引号、标签或解释'
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

        db_normalize_content_asset_paths($this->pdo);
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
