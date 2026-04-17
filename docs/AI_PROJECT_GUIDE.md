# GEO+AI 智能内容生成系统 - AI开发指南

> **文档目的**: 帮助AI助手快速理解项目架构、核心逻辑和开发规范，提高开发效率
> **最后更新**: 2026-01-31
> **项目版本**: 1.0

---

## 📋 项目概览

### 核心定位
这是一个**AI驱动的自动化内容生成与发布平台**，主要用于批量生成SEO优化的文章内容。

### 技术栈速查
```
后端: PHP 7.4+ (无框架，原生开发)
数据库: SQLite (文件数据库 /data/db/blog.db)
前端: TailwindCSS + 原生JavaScript + Lucide Icons
服务器: PHP内置开发服务器 (localhost:8080)
AI集成: DeepSeek API - OpenAI兼容接口
```

### 项目特点
- ✅ 无需安装复杂依赖，开箱即用
- ✅ 单文件数据库，易于备份和迁移
- ✅ 完整的后台管理系统
- ✅ 支持批量AI内容生成
- ✅ 进程级任务管理和监控
- ✅ 完善的安全机制（CSRF、XSS防护）

---

## 🏗️ 核心架构

### 1. 目录结构（关键文件）

```
GEO网站系统/
├── /includes/                    # 核心系统类库
│   ├── config.php               # 全局配置（常量定义）
│   ├── database.php             # 数据库类（单例模式）
│   ├── functions.php            # 工具函数库
│   ├── ai_engine.php            # AI内容生成引擎 ⭐核心
│   ├── ai_service.php           # AI API服务封装
│   ├── task_service.php         # 任务管理服务
│   ├── task_status_manager.php  # 进程生命周期管理 ⭐重要
│   ├── security.php             # 安全函数
│   ├── seo_functions.php        # SEO优化函数
│   ├── header.php / footer.php  # 前台布局模板
│
├── /geo_admin/                       # 后台管理系统
│   ├── dashboard.php            # 仪表盘（数据统计）
│   ├── tasks-new.php            # 任务管理主页
│   ├── articles-new.php         # 文章管理主页
│   ├── ai-configurator.php      # AI配置中心
│   ├── ai-models.php            # AI模型管理
│   ├── ai-prompts.php           # 提示词管理
│   ├── materials-new.php        # 素材库入口
│   ├── start_task_batch.php     # 批量执行启动器 ⭐核心
│   └── includes/header.php      # 后台布局模板
│
├── index.php                     # 前台首页
├── article.php                   # 文章详情页
├── bin/
│   ├── batch_execute_task.php    # 批量执行工作进程 ⭐核心
│   └── cron.php                  # 任务调度器
├── router.php                    # URL路由（开发环境）
├── install.php                   # 安装脚本
│
├── /data/db/blog.db             # SQLite数据库文件
├── /logs/                        # 日志目录
│   ├── batch_*.log              # 批量执行日志
│   ├── batch_*.pid              # 进程PID文件
│   └── task_manager_*.log       # 任务管理器日志
│
└── /uploads/                     # 上传文件
    ├── /images/                 # 文章图片
    └── /knowledge/              # 知识库文件
```

### 2. 核心类详解

#### Database 类 (includes/database.php)
```php
// 单例模式，全局唯一数据库连接
class Database {
    private static $instance = null;
    private $pdo;
    
    // 获取实例
    public static function getInstance()
    
    // 核心方法
    public function query($sql, $params = [])      // 执行查询
    public function fetchOne($sql, $params = [])   // 获取单条记录
    public function fetchAll($sql, $params = [])   // 获取多条记录
    public function insert($table, $data)          // 插入记录
    public function update($table, $data, $where, $params) // 更新记录
    public function delete($table, $where, $params) // 删除记录
    public function count($table, $where, $params)  // 统计记录
}

// 全局使用方式
$db = Database::getInstance()->getPDO();
```

#### AIEngine 类 (includes/ai_engine.php)
```php
// AI内容生成引擎 - 系统核心
class AIEngine {
    // 主要方法
    public function executeTask($task_id)  // 执行任务，生成一篇文章
    
    // 工作流程：
    // 1. 获取任务配置
    // 2. 检查草稿限制
    // 3. 从标题库获取未使用标题
    // 4. 调用AI生成内容
    // 5. 插入图片（如果配置）
    // 6. 生成关键词和描述
    // 7. 保存文章为草稿
    // 8. 更新统计数据
}
```

#### TaskStatusManager 类 (includes/task_status_manager.php)
```php
// 进程生命周期管理器 - 防止进程泄漏和状态不一致
class TaskStatusManager {
    // 状态常量
    const STATUS_IDLE = null;
    const STATUS_RUNNING = 'running';
    const STATUS_STOPPED = 'stopped';
    const STATUS_ERROR = 'error';
    const STATUS_COMPLETED = 'completed';
    
    // 核心方法
    public function atomicStatusUpdate($task_id, $new_status, $reason)
    public function cleanupOrphanedProcesses()  // 清理孤儿进程
    public function safeStopProcess($task_id)   // 安全停止进程（防止误杀服务器）
    public function performHealthCheck()        // 健康检查
}
```

---

## 🗄️ 数据库结构（核心表）

### 文章相关表
```sql
-- 文章表（核心内容表）
articles (
    id, title, slug, excerpt, content,
    category_id, author_id, task_id,        -- task_id关联AI生成任务
    keywords, meta_description,             -- SEO字段
    status,                                 -- draft/published/private/deleted
    review_status,                          -- pending/approved/rejected
    is_featured, view_count, like_count,
    created_at, updated_at, published_at
)

-- 分类表
categories (id, name, slug, description)

-- 标签表
tags (id, name, slug)

-- 文章标签关联表（多对多）
article_tags (article_id, tag_id)
```

### AI任务相关表
```sql
-- 任务表（核心配置表）
tasks (
    id, name,
    title_library_id,                       -- 标题库ID
    image_library_id, image_count,          -- 图片配置
    prompt_id, ai_model_id,                 -- AI配置
    author_id,                              -- 作者（NULL=随机）
    need_review,                            -- 是否需要审核
    publish_interval,                       -- 发布间隔（秒）
    draft_limit,                            -- 草稿数量限制
    is_loop,                                -- 是否循环生成
    status,                                 -- active/paused/completed
    batch_status,                           -- 批量执行状态
    batch_started_at, batch_stopped_at,     -- 批量执行时间
    created_count, published_count          -- 统计数据
)

-- 标题库集合
title_libraries (id, name, description)

-- 标题表
titles (
    id, library_id, title, keyword,
    is_used, used_count, used_at
)

-- AI模型配置表
ai_models (
    id, name, version, api_key, model_id,
    api_url,                                -- API端点
    daily_limit, used_today, total_used,    -- 使用限制
    status
)

-- 提示词模板表
prompts (
    id, name, type, content,
    variables                               -- 支持的变量
)
```

### 素材库表
```sql
-- 图片库集合
image_libraries (id, name, description)

-- 图片表
images (id, library_id, filename, url, alt_text)

-- 知识库表
knowledge_bases (id, name, content, type)

-- 作者表
authors (id, name, bio, avatar)
```

---

## 🔄 核心工作流程

### 1. AI内容生成流程（完整链路）

```
用户操作: 后台创建任务
    ↓
配置任务参数:
    - 选择标题库（必选）
    - 选择AI模型和提示词（必选）
    - 选择图片库（可选）
    - 设置发布间隔、草稿限制等
    ↓
启动批量执行:
    用户点击"启动批量执行"按钮
    ↓
start_task_batch.php:
    1. 验证管理员权限
    2. 检查任务状态
    3. 清理孤儿进程
    4. 更新任务状态为 'running'
    5. 启动后台进程: php bin/batch_execute_task.php {task_id} &
    ↓
bin/batch_execute_task.php (后台进程):
    1. 记录进程PID到文件
    2. 进入无限循环
    3. 每次循环:
        a. 检查停止信号
        b. 检查草稿限制
        c. 调用 AIEngine::executeTask()
        d. 等待发布间隔时间
    ↓
AIEngine::executeTask():
    1. 获取任务配置
    2. 从标题库获取未使用标题
    3. 构建提示词（替换变量）
    4. 调用AI API生成内容
    5. 插入图片（如果配置）
    6. 生成关键词和描述（如果启用）
    7. 保存文章（status='draft', review_status='pending'）
    8. 更新统计数据
    ↓
文章审核（如果需要）:
    管理员在 articles-review.php 审核
    ↓
发布文章:
    - 自动发布: 审核通过后自动 status='published'
    - 手动发布: 管理员手动修改状态
    ↓
前台展示:
    index.php 显示已发布文章
```

### 2. 进程管理流程

```
启动进程:
    start_task_batch.php
    ↓
    创建PID文件: /logs/batch_{task_id}.pid
    创建信息文件: /logs/batch_{task_id}.pid.info
    更新数据库: batch_status='running'
    ↓
    后台进程运行中...
    
停止进程:
    用户点击"停止"按钮
    ↓
    创建停止标记: /logs/stop_{task_id}.flag
    ↓
    TaskStatusManager::safeStopProcess():
        1. 读取PID文件
        2. 验证进程类型（防止误杀服务器进程）
        3. 发送TERM信号
        4. 等待进程优雅退出
        5. 清理PID文件
    ↓
    更新数据库: batch_status='stopped'
    
健康检查（定期执行）:
    TaskStatusManager::performHealthCheck()
    ↓
    1. 清理孤儿进程（PID文件存在但进程不存在）
    2. 自动恢复错误任务
    3. 检查长时间运行任务（>2小时）
```

### 3. 定时任务调度流程

```
Cron任务: */5 * * * * php bin/cron.php
    ↓
bin/cron.php:
    1. 查询 task_schedules 表
    2. 找到 next_run_time <= 当前时间 的任务
    3. 执行 TaskService::executeTask()
    4. 更新 next_run_time = 当前时间 + publish_interval
    
注意: 
- bin/cron.php 用于单次执行（每次生成1篇）
- bin/batch_execute_task.php 用于批量执行（持续生成）
```

---

## 🔑 关键配置说明

### 1. 环境配置 (includes/config.php)
```php
// 数据库路径
define('DB_PATH', __DIR__ . '/../data/db/blog.db');

// 默认管理员账户
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', '$2y$12$...');  // 密码: admin888

// 安全配置
define('SESSION_TIMEOUT', 3600);  // 会话超时1小时
define('SECRET_KEY', 'your-secret-key-change-this-in-production');
```

### 2. AI API配置
```
支持服务商（任何提供 OpenAI 兼容 chat completions 接口的平台均可接入）：
- MiniMax:  https://api.minimax.io   （模型：MiniMax-M2.7、MiniMax-M2.7-highspeed）
- OpenAI:   https://api.openai.com   （模型：gpt-4o 等）
- DeepSeek: https://api.deepseek.com （模型：deepseek-chat 等）
- 智谱 GLM: https://open.bigmodel.cn/api/paas/v4 （模型：glm-4.6、glm-5 等）
- 火山方舟: https://ark.cn-beijing.volces.com/api/v3 （模型通常填写推理接入点 ID，如 ep-xxxx）

认证方式: Bearer Token
请求格式: OpenAI兼容
说明: 后台支持填写服务商基础地址或完整聊天接口 URL。系统默认补全 OpenAI 风格的 `/v1/chat/completions`，并会自动识别智谱 `/api/paas/v4` 与火山方舟 `/api/v3` 这类版本化基础路径。
```

### 3. 提示词变量系统
```
支持的变量:
- {title}      - 文章标题
- {keyword}    - 关键词
- {Knowledge}  - 知识库内容

使用示例:
"请根据标题《{title}》和关键词"{keyword}"撰写一篇文章..."
```

---

## 🛠️ 开发规范

### 1. 文件头部规范
```php
<?php
/**
 * 文件描述
 *
 * @author 姚金刚
 * @version 1.0
 * @date YYYY-MM-DD
 */

// 防止直接访问
if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}
```

### 2. 数据库操作规范
```php
// ✅ 正确：使用预处理语句
$stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
$stmt->execute([$id]);

// ❌ 错误：直接拼接SQL（SQL注入风险）
$sql = "SELECT * FROM articles WHERE id = $id";
```

### 3. 日志记录规范
```php
// 使用 write_log() 函数
write_log("操作描述", 'INFO');   // 级别: INFO, WARNING, ERROR, DEBUG
```

### 4. 安全规范
```php
// 输出到HTML时必须转义
echo htmlspecialchars($user_input);

// CSRF保护
generate_csrf_token();  // 生成令牌
verify_csrf_token();    // 验证令牌

// 输入验证
$clean_input = sanitize_input($_POST['data']);
```

---

## 🚀 快速开发指南

### 启动开发环境
```bash
# 1. 启动PHP服务器
./start-server.sh
# 或
php -S localhost:8080 router.php

# 2. 访问系统
前台: http://localhost:8080
后台: http://localhost:8080/geo_admin/
```

### 常见开发任务

#### 添加新的AI模型
```
1. 后台 → AI配置中心 → AI模型管理
2. 点击"添加模型"
3. 填写: 名称、API密钥、模型ID、API端点
4. 保存后即可在任务中使用
```

#### 创建新的提示词模板
```
1. 后台 → AI配置中心 → 提示词管理
2. 点击"添加提示词"
3. 填写: 名称、类型、内容
4. 使用变量: {title}, {keyword}, {Knowledge}
```

#### 调试批量执行
```bash
# 查看实时日志
tail -f logs/batch_{task_id}.log

# 查看任务管理器日志
tail -f logs/task_manager_$(date +%Y-%m-%d).log

# 检查进程状态
ps aux | grep batch_execute_task.php
```

---

## 🐛 常见问题排查

### 问题1: 批量执行无法启动
```
排查步骤:
1. 检查任务状态是否为 'active'
2. 检查是否有孤儿进程: 后台 → 系统诊断
3. 查看日志: logs/batch_{task_id}.log
4. 手动清理: 删除 logs/batch_{task_id}.pid
```

### 问题2: AI生成失败
```
排查步骤:
1. 检查AI模型配置（API密钥是否正确）
2. 检查每日调用限制
3. 查看错误日志
4. 测试API连接: admin/system_diagnostics.php
```

### 问题3: 文章不显示
```
排查步骤:
1. 检查文章状态: status='published'
2. 检查审核状态: review_status='approved'
3. 检查分类是否存在
4. 清除缓存（如果启用）
```

---

## 📝 重要注意事项

### 安全注意事项
1. ⚠️ 首次使用后立即修改管理员密码
2. ⚠️ 生产环境必须修改 SECRET_KEY
3. ⚠️ 定期备份数据库文件 /data/db/blog.db
4. ⚠️ 不要在生产环境使用 PHP 内置服务器

### 性能注意事项
1. SQLite适合中小型项目（<10万文章）
2. 批量执行时注意 publish_interval 设置（避免API限流）
3. 定期清理日志文件
4. 图片建议使用外部CDN

### 开发注意事项
1. 所有PHP文件必须包含 `define('FEISHU_TREASURE', true);` 检查
2. 数据库操作必须使用预处理语句
3. 修改核心类后需要重启批量执行进程
4. 测试文件（test-*.php）不要提交到生产环境

---

## 📚 扩展阅读

### 相关文件
- `系统说明文档.md` - 用户使用手册
- `install.php` - 安装向导
- `admin/system_diagnostics.php` - 系统诊断工具

### 技术文档
- PHP PDO: https://www.php.net/manual/zh/book.pdo.php
- SQLite: https://www.sqlite.org/docs.html
- TailwindCSS: https://tailwindcss.com/docs

---

**文档维护**: 每次重大更新后请同步更新本文档
**联系方式**: 项目作者 - 姚金刚
