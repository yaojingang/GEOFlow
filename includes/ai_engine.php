<?php
/**
 * GEO+AI内容生成系统 - AI内容生成引擎
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-06
 */

// 防止直接访问
if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/knowledge-retrieval.php';

class AIEngine {
    private const MIN_ARTICLE_TEXT_LENGTH = 200;
    private const AI_REQUEST_TIMEOUT_SECONDS = 180;
    private $db;
    private $heartbeatCallback = null;
    
    public function __construct($database) {
        $this->db = $database;
    }

    public function setHeartbeatCallback(?callable $callback): void {
        $this->heartbeatCallback = $callback;
    }

    private function touchHeartbeat(string $stage, array $context = []): void {
        if (is_callable($this->heartbeatCallback)) {
            call_user_func($this->heartbeatCallback, $stage, $context);
        }
    }
    
    /**
     * 执行任务 - 生成一篇文章
     */
    public function executeTask($task_id) {
        try {
            $this->touchHeartbeat('loading_task', ['task_id' => (int) $task_id]);

            // 获取任务信息
            $task = $this->getTaskInfo($task_id);
            if (!$task) {
                throw new Exception('任务不存在');
            }
            
            // 检查任务状态
            if ($task['status'] !== 'active') {
                throw new Exception('任务未激活');
            }
            
            // 检查草稿数量限制
            if ($this->checkDraftLimit($task_id, $task['draft_limit'])) {
                throw new Exception('草稿数量已达上限，暂停生成');
            }
            
            // 检查AI模型可用性
            if (!$this->checkAIModelAvailable($task['ai_model_id'])) {
                throw new Exception('AI模型不可用或已达每日限制');
            }
            
            $this->touchHeartbeat('selecting_title', ['task_id' => (int) $task_id]);
            // 获取下一个标题
            $title_info = $this->getNextTitle($task['title_library_id'], $task['is_loop'], $task['loop_count']);
            if (!$title_info) {
                if ($task['is_loop']) {
                    // 重置循环
                    $this->resetTitleUsage($task['title_library_id']);
                    $this->updateTaskLoopCount($task_id);
                    $title_info = $this->getNextTitle($task['title_library_id'], $task['is_loop'], $task['loop_count']);
                }
                
                if (!$title_info) {
                    throw new Exception('没有可用的标题');
                }
            }
            
            $this->touchHeartbeat('generating_content', [
                'task_id' => (int) $task_id,
                'title_id' => (int) $title_info['id']
            ]);
            // 生成文章内容
            $article_data = $this->generateArticleContent($task, $title_info);
            
            $this->touchHeartbeat('saving_article', ['task_id' => (int) $task_id]);
            // 保存文章
            $article_id = $this->saveArticle($task, $title_info, $article_data);
            
            $this->touchHeartbeat('updating_stats', [
                'task_id' => (int) $task_id,
                'article_id' => (int) $article_id
            ]);
            // 更新统计
            $this->updateTaskStats($task_id);
            $this->updateTitleUsage($title_info['id']);
            $this->updateAIModelUsage($task['ai_model_id']);
            
            // 记录日志
            $this->logTaskExecution($task_id, $article_id, 'success', '文章生成成功');
            
            return [
                'success' => true,
                'article_id' => $article_id,
                'title' => $title_info['title'],
                'message' => '文章生成成功'
            ];
            
        } catch (Exception $e) {
            // 记录错误日志
            $this->logTaskExecution($task_id, null, 'error', $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取任务信息
     */
    private function getTaskInfo($task_id) {
        $sql = "
            SELECT t.*, 
                   am.api_key, am.model_id, am.api_url, am.daily_limit, am.used_today,
                   p.content as prompt_content,
                   kb.content as knowledge_content
            FROM tasks t
            LEFT JOIN ai_models am ON t.ai_model_id = am.id
            LEFT JOIN prompts p ON t.prompt_id = p.id
            LEFT JOIN knowledge_bases kb ON t.knowledge_base_id = kb.id
            WHERE t.id = ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$task_id]);
        $task = $stmt->fetch();
        if ($task) {
            $task['api_key'] = decrypt_ai_api_key($task['api_key'] ?? '');
        }
        return $task;
    }
    
    /**
     * 检查草稿数量限制
     */
    private function checkDraftLimit($task_id, $draft_limit) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM articles 
            WHERE task_id = ? AND status = 'draft' AND deleted_at IS NULL
        ");
        $stmt->execute([$task_id]);
        $count = $stmt->fetch()['count'];
        
        return $count >= $draft_limit;
    }
    
    /**
     * 检查AI模型可用性
     */
    private function checkAIModelAvailable($model_id) {
        $stmt = $this->db->prepare("
            SELECT daily_limit, used_today, status
            FROM ai_models 
            WHERE id = ?
              AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
        ");
        $stmt->execute([$model_id]);
        $model = $stmt->fetch();
        
        if (!$model || $model['status'] !== 'active') {
            return false;
        }
        
        // 检查每日限制
        if ($model['daily_limit'] > 0 && $model['used_today'] >= $model['daily_limit']) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取下一个标题
     */
    private function getNextTitle($library_id, $is_loop, $loop_count) {
        // 优先获取未使用的标题
        $stmt = $this->db->prepare("
            SELECT * FROM titles
            WHERE library_id = ? AND used_count = 0
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$library_id]);
        $title = $stmt->fetch();

        if ($title) {
            return $title;
        }

        // 如果没有未使用的标题
        if ($is_loop) {
            // 循环模式：获取使用次数最少的标题
            $stmt = $this->db->prepare("
                SELECT * FROM titles
                WHERE library_id = ?
                ORDER BY used_count ASC, id ASC
                LIMIT 1
            ");
            $stmt->execute([$library_id]);
            $title = $stmt->fetch();

            if ($title) {
                return $title;
            }
        }

        return false;
    }
    
    /**
     * 重置标题使用状态（用于循环）
     */
    private function resetTitleUsage($library_id) {
        $stmt = $this->db->prepare("UPDATE titles SET used_count = 0 WHERE library_id = ?");
        $stmt->execute([$library_id]);
    }
    
    /**
     * 更新任务循环次数
     */
    private function updateTaskLoopCount($task_id) {
        $stmt = $this->db->prepare("UPDATE tasks SET loop_count = loop_count + 1 WHERE id = ?");
        $stmt->execute([$task_id]);
    }
    
    /**
     * 生成文章内容
     */
    private function generateArticleContent($task, $title_info) {
        $this->touchHeartbeat('preparing_prompt', ['task_id' => (int) $task['id']]);

        // 准备提示词变量
        $variables = [
            'title' => $title_info['title'],
            'keyword' => $title_info['keyword'] ?: '',
        ];
        
        // 添加知识库内容
        $knowledgeContext = $this->resolveKnowledgeContext($task, $title_info);
        if ($knowledgeContext !== '') {
            $variables['Knowledge'] = $knowledgeContext;
            $task['resolved_knowledge_context'] = $knowledgeContext;
        }
        
        // 处理提示词
        $processed_prompt = $this->processPromptVariables($task['prompt_content'], $variables);
        
        // 调用AI生成内容
        $this->touchHeartbeat('calling_ai_content', ['task_id' => (int) $task['id']]);
        $content = $this->callAI($task, $processed_prompt);
        $this->assertGeneratedContentIsValid($content, (string) ($title_info['title'] ?? ''));
        
        // 处理图片插入
        $article_images = [];
        if ($task['image_library_id'] && $task['image_count'] > 0) {
            $this->touchHeartbeat('inserting_images', ['task_id' => (int) $task['id']]);
            $image_result = $this->insertImages($content, $task['image_library_id'], $task['image_count']);
            $content = $image_result['content'];
            $article_images = $image_result['images'];
        }
        $this->assertGeneratedContentIsValid($content, (string) ($title_info['title'] ?? ''));
        
        // 生成关键词和描述
        $keywords = '';
        $description = '';
        
        if ($task['auto_keywords']) {
            $this->touchHeartbeat('generating_keywords', ['task_id' => (int) $task['id']]);
            $keywords = $this->generateKeywords($task, $content, $title_info);
        }
        
        if ($task['auto_description']) {
            $this->touchHeartbeat('generating_description', ['task_id' => (int) $task['id']]);
            $description = $this->generateDescription($task, $content, $title_info);
        }
        
        return [
            'content' => $content,
            'keywords' => $keywords,
            'description' => $description,
            'excerpt' => $this->generateExcerpt($content),
            'images' => $article_images
        ];
    }
    
    /**
     * 处理提示词变量
     */
    private function processPromptVariables($prompt, $variables) {
        $processed = $prompt;
        
        // 替换基本变量
        foreach ($variables as $key => $value) {
            $processed = str_replace('{{' . $key . '}}', $value, $processed);
        }
        
        // 处理条件语句 {{#if variable}}...{{/if}}
        $processed = preg_replace_callback('/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s', function($matches) use ($variables) {
            $variable = $matches[1];
            $content = $matches[2];
            return isset($variables[$variable]) && !empty($variables[$variable]) ? $content : '';
        }, $processed);
        
        return $processed;
    }
    
    /**
     * 调用AI生成内容
     */
    public function callAI($task, $prompt) {
        $api_url = ai_chat_endpoint_from_url((string) ($task['api_url'] ?? ''));
        
        $data = [
            'model' => $task['model_id'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => 4000,
            'temperature' => 0.7
        ];
        
        $ch = curl_init();
        apply_curl_network_defaults($ch);
        curl_setopt_array($ch, [
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $task['api_key']
            ],
            CURLOPT_TIMEOUT => self::AI_REQUEST_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('CURL错误: ' . $error);
        }
        
        if ($http_code !== 200) {
            throw new Exception('API调用失败，HTTP状态码: ' . $http_code . ', 响应: ' . $response);
        }
        
        $result = json_decode($response, true);
        if (!$result || !isset($result['choices'][0]['message']['content'])) {
            throw new Exception('API响应格式错误：' . $this->buildAIResponseDiagnostic($response, $http_code, $result));
        }
        
        $content = trim((string) $result['choices'][0]['message']['content']);
        if ($content === '') {
            throw new Exception('AI返回空正文：' . $this->buildAIResponseDiagnostic($response, $http_code, $result));
        }

        return $content;
    }

    private function buildAIResponseDiagnostic(string $rawResponse, int $httpCode, $result): string {
        $diagnostic = [
            'http' => $httpCode,
        ];

        if (is_array($result)) {
            $diagnostic['model'] = (string) ($result['model'] ?? '');
            $diagnostic['finish_reason'] = (string) ($result['choices'][0]['finish_reason'] ?? '');
            $diagnostic['completion_tokens'] = (int) ($result['usage']['completion_tokens'] ?? 0);
            $diagnostic['prompt_tokens'] = (int) ($result['usage']['prompt_tokens'] ?? 0);
            $diagnostic['content_length'] = mb_strlen((string) ($result['choices'][0]['message']['content'] ?? ''), 'UTF-8');
        }

        $bodyPreview = trim(preg_replace('/\s+/u', ' ', $rawResponse));
        if (mb_strlen($bodyPreview, 'UTF-8') > 240) {
            $bodyPreview = mb_substr($bodyPreview, 0, 239, 'UTF-8') . '…';
        }
        $diagnostic['body'] = $bodyPreview;

        return json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * 插入图片到内容中
     */
    private function insertImages($content, $image_library_id, $image_count) {
        // 获取随机图片
        $stmt = $this->db->prepare("
            SELECT * FROM images 
            WHERE library_id = ? 
            ORDER BY RANDOM() 
            LIMIT ?
        ");
        $stmt->execute([$image_library_id, $image_count]);
        $images = $stmt->fetchAll();
        
        if (empty($images)) {
            return [
                'content' => $content,
                'images' => []
            ];
        }
        
        // 查找二级和三级标题位置
        $lines = explode("\n", $content);
        $insert_positions = [];
        
        foreach ($lines as $index => $line) {
            if (preg_match('/^##\s+/', $line) || preg_match('/^###\s+/', $line)) {
                $insert_positions[] = $index + 1; // 在标题后插入
            }
        }
        
        // 如果没有找到合适的位置，在内容中间插入
        if (empty($insert_positions)) {
            $middle = intval(count($lines) / 2);
            $insert_positions[] = $middle;
        }
        
        // 插入图片
        $image_index = 0;
        $used_images = [];
        foreach ($insert_positions as $pos) {
            if ($image_index >= count($images)) break;
            
            $image = $images[$image_index];
            $imagePath = $this->normalizeImageAssetPath((string) ($image['file_path'] ?? ''));
            $image_markdown = "\n![" . $image['original_name'] . "](" . $imagePath . ")\n";
            
            if ($pos < count($lines)) {
                array_splice($lines, $pos + $image_index, 0, $image_markdown);
                $used_images[] = [
                    'image_id' => $image['id'],
                    'position' => count($used_images) + 1
                ];
            }
            
            $image_index++;
        }
        
        return [
            'content' => implode("\n", $lines),
            'images' => $used_images
        ];
    }

    private function normalizeImageAssetPath(string $path): string {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (preg_match('#^(https?:)?//#i', $trimmed) || str_starts_with($trimmed, 'data:') || str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        if (preg_match('#^(uploads|assets)/#i', $trimmed)) {
            return '/' . ltrim($trimmed, '/');
        }

        return $trimmed;
    }
    
    /**
     * 生成关键词
     */
    private function generateKeywords($task, $content, array $titleInfo = []) {
        // 获取关键词提示词
        $stmt = $this->db->prepare("
            SELECT content
            FROM prompts
            WHERE type = 'keyword'
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute();
        $prompt_data = $stmt->fetch();
        
        if (!$prompt_data) {
            return '';
        }
        
        $variables = [
            'content' => mb_substr($content, 0, 1000),
            'title' => $titleInfo['title'] ?? '',
            'keyword' => $titleInfo['keyword'] ?? '',
        ];

        if (!empty($task['resolved_knowledge_context'])) {
            $variables['Knowledge'] = $task['resolved_knowledge_context'];
        }

        $prompt = $this->processPromptVariables($prompt_data['content'], $variables);
        
        try {
            $keywords = $this->callAI($task, $prompt);
            return trim($keywords);
        } catch (Exception $e) {
            return '';
        }
    }
    
    /**
     * 生成描述
     */
    private function generateDescription($task, $content, array $titleInfo = []) {
        // 获取描述提示词
        $stmt = $this->db->prepare("
            SELECT content
            FROM prompts
            WHERE type = 'description'
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute();
        $prompt_data = $stmt->fetch();
        
        if (!$prompt_data) {
            return '';
        }
        
        $variables = [
            'content' => mb_substr($content, 0, 1000),
            'title' => $titleInfo['title'] ?? '',
            'keyword' => $titleInfo['keyword'] ?? '',
        ];

        if (!empty($task['resolved_knowledge_context'])) {
            $variables['Knowledge'] = $task['resolved_knowledge_context'];
        }

        $prompt = $this->processPromptVariables($prompt_data['content'], $variables);
        
        try {
            $description = $this->callAI($task, $prompt);
            return trim($description);
        } catch (Exception $e) {
            return '';
        }
    }
    
    /**
     * 生成摘要
     */
    private function generateExcerpt($content) {
        // 简单提取前200个字符作为摘要
        $content = preg_replace('/!\[[^\]]*\]\(([^)]+)\)/u', '', $content);
        $text = strip_tags($content);
        $text = preg_replace('/\s+/', ' ', $text);
        
        if (mb_strlen($text) > 200) {
            return mb_substr($text, 0, 200) . '...';
        }
        
        return $text;
    }

    private function resolveKnowledgeContext(array $task, array $titleInfo): string {
        $knowledgeBaseId = (int) ($task['knowledge_base_id'] ?? 0);
        $knowledgeContent = trim((string) ($task['knowledge_content'] ?? ''));
        if ($knowledgeBaseId <= 0 || $knowledgeContent === '') {
            return $knowledgeContent;
        }

        try {
            knowledge_retrieval_ensure_chunks($this->db, $knowledgeBaseId, $knowledgeContent);

            $query = trim(
                (string) ($titleInfo['title'] ?? '') . "\n" .
                (string) ($titleInfo['keyword'] ?? '')
            );
            $result = knowledge_retrieval_fetch_context($this->db, $knowledgeBaseId, $query, 4, 2400);
            if (!empty($result['context'])) {
                return $result['context'];
            }
        } catch (Throwable $e) {
            error_log('知识库检索失败: ' . $e->getMessage());
        }

        if (mb_strlen($knowledgeContent, 'UTF-8') > 2400) {
            return mb_substr($knowledgeContent, 0, 2400, 'UTF-8');
        }

        return $knowledgeContent;
    }
    
    /**
     * 保存文章
     */
    private function saveArticle($task, $title_info, $article_data) {
        $this->assertGeneratedContentIsValid((string) ($article_data['content'] ?? ''), (string) ($title_info['title'] ?? ''));

        // 生成slug
        $slug = $this->generateSlug($title_info['title']);
        
        // 选择作者
        $author_id = $this->selectAuthor($task);

        // 根据任务配置选择分类
        $category_id = $this->selectCategory($task);
        
        // 确定审核状态
        $review_status = $task['need_review'] ? 'pending' : 'auto_approved';
        $status = $task['need_review'] ? 'draft' : 'published';
        $published_at = $task['need_review'] ? null : date('Y-m-d H:i:s');
        
        // 插入文章
        $stmt = $this->db->prepare("
            INSERT INTO articles (
                title, slug, excerpt, content, category_id, author_id, task_id,
                original_keyword, keywords, meta_description, status, review_status,
                is_ai_generated, published_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");
        
        $stmt->execute([
            $title_info['title'],
            $slug,
            $article_data['excerpt'],
            $article_data['content'],
            $category_id,
            $author_id,
            $task['id'],
            $title_info['keyword'],
            $article_data['keywords'],
            $article_data['description'],
            $status,
            $review_status,
            $published_at
        ]);
        
        $article_id = db_last_insert_id($this->db, 'articles');

        if (!empty($article_data['images'])) {
            $stmt = $this->db->prepare("
                INSERT INTO article_images (article_id, image_id, position, created_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ");

            foreach ($article_data['images'] as $image_ref) {
                $stmt->execute([
                    $article_id,
                    $image_ref['image_id'],
                    $image_ref['position']
                ]);
            }
        }

        return $article_id;
    }

    private function assertGeneratedContentIsValid(string $content, string $title = ''): void {
        $textLength = $this->getMeaningfulContentLength($content);
        $titlePrefix = $title !== '' ? "《{$title}》" : '当前文章';

        if ($textLength <= 0) {
            throw new Exception($titlePrefix . ' 生成失败：AI返回空正文');
        }

        if ($textLength < self::MIN_ARTICLE_TEXT_LENGTH) {
            throw new Exception($titlePrefix . " 生成失败：正文过短（当前仅 {$textLength} 字，至少需要 " . self::MIN_ARTICLE_TEXT_LENGTH . ' 字）');
        }
    }

    private function getMeaningfulContentLength(string $content): int {
        $text = preg_replace('/!\[[^\]]*\]\(([^)]+)\)/u', ' ', $content);
        $text = preg_replace('/```[\s\S]*?```/u', ' ', (string) $text);
        $text = preg_replace('/`[^`]*`/u', ' ', (string) $text);
        $text = preg_replace('/^#{1,6}\s+/mu', '', (string) $text);
        $text = preg_replace('/^\s*[-*+]\s+/mu', '', (string) $text);
        $text = strip_tags((string) $text);
        $text = preg_replace('/\s+/u', '', (string) $text);

        return mb_strlen((string) $text, 'UTF-8');
    }

    /**
     * 根据任务配置选择分类。
     */
    private function selectCategory($task) {
        if (($task['category_mode'] ?? 'smart') === 'fixed' && !empty($task['fixed_category_id'])) {
            $stmt = $this->db->prepare("SELECT id FROM categories WHERE id = ? LIMIT 1");
            $stmt->execute([$task['fixed_category_id']]);
            $category = $stmt->fetch();
            if ($category) {
                return (int) $category['id'];
            }
        }

        if (($task['category_mode'] ?? '') === 'random') {
            $stmt = $this->db->prepare("SELECT id FROM categories ORDER BY RANDOM() LIMIT 1");
            $stmt->execute();
            $category = $stmt->fetch();
            if ($category) {
                return (int) $category['id'];
            }
        }

        // smart 模式当前使用默认兜底策略，后续可扩展为智能分类器。
        $stmt = $this->db->prepare("SELECT id FROM categories ORDER BY id LIMIT 1");
        $stmt->execute();
        $category = $stmt->fetch();

        return $category ? (int) $category['id'] : 1;
    }
    
    /**
     * 生成URL slug - 使用随机字符串
     */
    private function generateSlug($title) {
        // 使用functions.php中的函数生成唯一slug
        return generate_unique_article_slug($title);
    }
    
    /**
     * 选择作者
     */
    private function selectAuthor($task) {
        if ($task['author_type'] === 'custom' && $task['custom_author_id']) {
            return $task['custom_author_id'];
        }
        
        // 随机选择作者
        $stmt = $this->db->prepare("SELECT id FROM authors ORDER BY RANDOM() LIMIT 1");
        $stmt->execute();
        $author = $stmt->fetch();
        
        return $author ? $author['id'] : 1;
    }
    
    /**
     * 更新任务统计
     */
    private function updateTaskStats($task_id) {
        $stmt = $this->db->prepare("
            UPDATE tasks SET 
                created_count = created_count + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$task_id]);
    }
    
    /**
     * 更新标题使用次数
     */
    private function updateTitleUsage($title_id) {
        $stmt = $this->db->prepare("UPDATE titles SET used_count = used_count + 1 WHERE id = ?");
        $stmt->execute([$title_id]);
    }
    
    /**
     * 更新AI模型使用次数
     */
    private function updateAIModelUsage($model_id) {
        $today = date('Y-m-d');
        
        // 检查是否需要重置今日使用次数
        $stmt = $this->db->prepare("SELECT DATE(updated_at) as last_update FROM ai_models WHERE id = ?");
        $stmt->execute([$model_id]);
        $result = $stmt->fetch();
        
        if ($result && $result['last_update'] !== $today) {
            // 新的一天，重置今日使用次数
            $stmt = $this->db->prepare("
                UPDATE ai_models SET 
                    used_today = 1, 
                    total_used = total_used + 1, 
                    updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
        } else {
            // 增加使用次数
            $stmt = $this->db->prepare("
                UPDATE ai_models SET 
                    used_today = used_today + 1, 
                    total_used = total_used + 1, 
                    updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
        }
        
        $stmt->execute([$model_id]);
    }
    
    /**
     * 记录任务执行日志
     */
    private function logTaskExecution($task_id, $article_id, $type, $message) {
        $data = json_encode([
            'task_id' => $task_id,
            'article_id' => $article_id
        ]);
        
        $stmt = $this->db->prepare("
            INSERT INTO system_logs (type, message, data) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute(['task', $message, $data]);
    }
    
    /**
     * 检查敏感词
     */
    public function checkSensitiveWords($content) {
        $stmt = $this->db->prepare("SELECT word FROM sensitive_words");
        $stmt->execute();
        $sensitive_words = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($sensitive_words as $word) {
            if (strpos($content, $word) !== false) {
                return [
                    'has_sensitive' => true,
                    'word' => $word
                ];
            }
        }
        
        return ['has_sensitive' => false];
    }
}
?>
