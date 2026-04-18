<?php
/**
 * GEO+AI内容生成系统 - 任务管理服务类
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-05
 */

// 防止直接访问
if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/ai_service.php';
require_once __DIR__ . '/job_queue_service.php';

class TaskService {
    private $db;
    private $ai_service;
    
    public function __construct() {
        global $db;
        $this->db = $db;
        $this->ai_service = new AIService();
    }
    
    /**
     * 创建新任务
     */
    public function createTask($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO tasks (
                    name, title_library_id, image_library_id, image_count, 
                    prompt_id, ai_model_id, author_id, need_review, 
                    publish_interval, auto_keywords, auto_description, 
                    draft_limit, is_loop, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $data['name'],
                $data['title_library_id'],
                $data['image_library_id'] ?? null,
                $data['image_count'] ?? 0,
                $data['prompt_id'],
                $data['ai_model_id'],
                $data['author_id'] ?? null,
                $data['need_review'] ?? 1,
                $data['publish_interval'] ?? 3600,
                $data['auto_keywords'] ?? 1,
                $data['auto_description'] ?? 1,
                $data['draft_limit'] ?? 10,
                $data['is_loop'] ?? 0,
                $data['status'] ?? 'active'
            ]);
            
                if ($result) {
                    $task_id = db_last_insert_id($this->db, 'tasks');
                    $queueService = new JobQueueService($this->db);
                    $queueService->initializeTaskSchedule((int) $task_id);
                    
                    // 创建任务调度
                    if (($data['status'] ?? 'active') === 'active') {
                        $this->createTaskSchedule($task_id);
                    } else {
                        $stmt = $this->db->prepare("
                            UPDATE tasks
                            SET schedule_enabled = 0,
                                next_run_at = NULL,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$task_id]);
                    }
                
                write_log("创建任务成功: {$data['name']} (ID: $task_id)", 'INFO');
                return ['success' => true, 'task_id' => $task_id];
            }
            
            return ['success' => false, 'error' => '创建任务失败'];
            
        } catch (Exception $e) {
            write_log("创建任务失败: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 更新任务
     */
    public function updateTask($task_id, $data) {
        try {
            $fields = [];
            $values = [];
            
            $allowed_fields = [
                'name', 'title_library_id', 'image_library_id', 'image_count',
                'prompt_id', 'ai_model_id', 'author_id', 'need_review',
                'publish_interval', 'auto_keywords', 'auto_description',
                'draft_limit', 'is_loop', 'status'
            ];
            
            foreach ($allowed_fields as $field) {
                if (isset($data[$field])) {
                    $fields[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($fields)) {
                return ['success' => false, 'error' => '没有要更新的字段'];
            }
            
            $fields[] = "updated_at = CURRENT_TIMESTAMP";
            $values[] = $task_id;
            
            $sql = "UPDATE tasks SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($values);
            
            if ($result) {
                write_log("更新任务成功: ID $task_id", 'INFO');
                return ['success' => true];
            }
            
            return ['success' => false, 'error' => '更新任务失败'];
            
        } catch (Exception $e) {
            write_log("更新任务失败: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 获取任务列表
     */
    public function getTaskList($page = 1, $per_page = 20, $filters = []) {
        try {
            $where_conditions = [];
            $params = [];
            
            // 处理筛选条件
            if (!empty($filters['status'])) {
                $where_conditions[] = "t.status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['name'])) {
                $where_conditions[] = "t.name LIKE ?";
                $params[] = '%' . $filters['name'] . '%';
            }
            
            $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);
            
            // 获取总数
            $count_sql = "SELECT COUNT(*) as total FROM tasks t $where_clause";
            $stmt = $this->db->prepare($count_sql);
            $stmt->execute($params);
            $total = $stmt->fetch()['total'];
            
            // 获取列表
            $offset = ($page - 1) * $per_page;
            $list_sql = "
                SELECT 
                    t.*,
                    tl.name as title_library_name,
                    il.name as image_library_name,
                    p.name as prompt_name,
                    am.name as ai_model_name,
                    a.name as author_name
                FROM tasks t
                LEFT JOIN title_libraries tl ON t.title_library_id = tl.id
                LEFT JOIN image_libraries il ON t.image_library_id = il.id
                LEFT JOIN prompts p ON t.prompt_id = p.id
                LEFT JOIN ai_models am ON t.ai_model_id = am.id
                LEFT JOIN authors a ON t.author_id = a.id
                $where_clause
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $params[] = $per_page;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($list_sql);
            $stmt->execute($params);
            $tasks = $stmt->fetchAll();
            
            return [
                'success' => true,
                'data' => $tasks,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page)
            ];
            
        } catch (Exception $e) {
            write_log("获取任务列表失败: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 获取任务详情
     */
    public function getTask($task_id) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    t.*,
                    tl.name as title_library_name,
                    il.name as image_library_name,
                    p.name as prompt_name,
                    am.name as ai_model_name,
                    a.name as author_name
                FROM tasks t
                LEFT JOIN title_libraries tl ON t.title_library_id = tl.id
                LEFT JOIN image_libraries il ON t.image_library_id = il.id
                LEFT JOIN prompts p ON t.prompt_id = p.id
                LEFT JOIN ai_models am ON t.ai_model_id = am.id
                LEFT JOIN authors a ON t.author_id = a.id
                WHERE t.id = ?
            ");
            
            $stmt->execute([$task_id]);
            $task = $stmt->fetch();
            
            if ($task) {
                return ['success' => true, 'data' => $task];
            }
            
            return ['success' => false, 'error' => '任务不存在'];
            
        } catch (Exception $e) {
            write_log("获取任务详情失败: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 删除任务
     */
    public function deleteTask($task_id) {
        try {
            $this->db->beginTransaction();
            
            // 删除任务调度
            $stmt = $this->db->prepare("DELETE FROM task_schedules WHERE task_id = ?");
            $stmt->execute([$task_id]);
            
            // 删除文章队列
            $stmt = $this->db->prepare("DELETE FROM article_queue WHERE task_id = ?");
            $stmt->execute([$task_id]);
            
            // 删除任务素材关联
            $stmt = $this->db->prepare("DELETE FROM task_materials WHERE task_id = ?");
            $stmt->execute([$task_id]);
            
            // 将相关文章的task_id设为NULL
            $stmt = $this->db->prepare("UPDATE articles SET task_id = NULL WHERE task_id = ?");
            $stmt->execute([$task_id]);
            
            // 删除任务
            $stmt = $this->db->prepare("DELETE FROM tasks WHERE id = ?");
            $result = $stmt->execute([$task_id]);
            
            if ($result) {
                $this->db->commit();
                write_log("删除任务成功: ID $task_id", 'INFO');
                return ['success' => true];
            }
            
            $this->db->rollBack();
            return ['success' => false, 'error' => '删除任务失败'];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            write_log("删除任务失败: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 暂停/启动任务
     */
    public function toggleTaskStatus($task_id) {
        try {
            $stmt = $this->db->prepare("SELECT status FROM tasks WHERE id = ?");
            $stmt->execute([$task_id]);
            $task = $stmt->fetch();
            
            if (!$task) {
                return ['success' => false, 'error' => '任务不存在'];
            }
            
            $new_status = $task['status'] === 'active' ? 'paused' : 'active';
            
            $stmt = $this->db->prepare("UPDATE tasks SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $result = $stmt->execute([$new_status, $task_id]);
            
            if ($result) {
                if ($new_status === 'active') {
                    $queueService = new JobQueueService($this->db);
                    $queueService->initializeTaskSchedule((int) $task_id);
                }
                write_log("切换任务状态成功: ID $task_id, 新状态: $new_status", 'INFO');
                return ['success' => true, 'status' => $new_status];
            }
            
            return ['success' => false, 'error' => '切换任务状态失败'];
            
        } catch (Exception $e) {
            write_log("切换任务状态失败: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 创建任务调度
     */
    private function createTaskSchedule($task_id) {
        try {
            $next_run = date('Y-m-d H:i:s', time() + 60); // 1分钟后开始执行
            
            $stmt = $this->db->prepare("
                INSERT INTO task_schedules (task_id, next_run_time, status) 
                VALUES (?, ?, 'pending')
            ");
            
            $stmt->execute([$task_id, $next_run]);
            
        } catch (Exception $e) {
            write_log("创建任务调度失败: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 执行任务 - 生成文章
     */
    public function executeTask($task_id) {
        try {
            // 获取任务信息
            $task_result = $this->getTask($task_id);
            if (!$task_result['success']) {
                throw new Exception('任务不存在');
            }
            
            $task = $task_result['data'];
            
            // 检查任务状态
            if ($task['status'] !== 'active') {
                return ['success' => false, 'error' => '任务未激活'];
            }
            
            // 检查草稿数量限制
            $draft_count = $this->getDraftCount($task_id);
            if ($draft_count >= $task['draft_limit']) {
                return ['success' => false, 'error' => '草稿数量已达上限'];
            }
            
            // 获取下一个标题
            $title_result = $this->getNextTitle($task['title_library_id'], $task['is_loop'], $task['loop_count']);
            if (!$title_result['success']) {
                return $title_result;
            }
            
            $title_data = $title_result['data'];
            
            // 生成文章
            $article_result = $this->ai_service->generateArticle(
                $task_id, 
                $title_data['title'], 
                $title_data['keyword']
            );
            
            if (!$article_result['success']) {
                return $article_result;
            }
            
            // 保存文章
            $save_result = $this->saveGeneratedArticle($task, $title_data, $article_result['content']);
            
            if ($save_result['success']) {
                // 更新任务统计
                $this->updateTaskStats($task_id, empty($task['need_review']));
                
                // 标记标题已使用
                $this->markTitleUsed($title_data['id']);
            }
            
            return $save_result;
            
        } catch (Exception $e) {
            write_log("执行任务失败: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 获取草稿数量
     */
    private function getDraftCount($task_id) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM articles WHERE task_id = ? AND status = 'draft'");
        $stmt->execute([$task_id]);
        return $stmt->fetch()['count'];
    }
    
    /**
     * 获取下一个标题
     */
    private function getNextTitle($library_id, $is_loop, $loop_count) {
        try {
            // 优先获取未使用的标题
            $stmt = $this->db->prepare("
                SELECT * FROM titles 
                WHERE library_id = ? AND used_count = 0 
                ORDER BY id ASC LIMIT 1
            ");
            $stmt->execute([$library_id]);
            $title = $stmt->fetch();
            
            if ($title) {
                return ['success' => true, 'data' => $title];
            }
            
            // 如果没有未使用的标题
            if ($is_loop) {
                // 循环模式：获取使用次数最少的标题
                $stmt = $this->db->prepare("
                    SELECT * FROM titles 
                    WHERE library_id = ? 
                    ORDER BY used_count ASC, id ASC LIMIT 1
                ");
                $stmt->execute([$library_id]);
                $title = $stmt->fetch();
                
                if ($title) {
                    return ['success' => true, 'data' => $title];
                }
            }
            
            return ['success' => false, 'error' => '没有可用的标题'];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 保存生成的文章
     */
    private function saveGeneratedArticle($task, $title_data, $content) {
        try {
            // 生成slug
            $slug = $this->generateSlug($title_data['title']);

            // 选择作者
            $author_id = $task['author_id'] ?? $this->getRandomAuthor();

            // 选择分类（随机选择一个）
            $category_id = $this->getRandomCategory();

            // 自动提取关键词和描述
            $keywords = '';
            $description = '';

            if ($task['auto_keywords']) {
                $keywords = $this->ai_service->extractKeywords($content);
            }

            if ($task['auto_description']) {
                $description = $this->ai_service->generateDescription($content);
            }

            // 检查敏感词（同时检测标题和内容）
            $title_check = $this->ai_service->checkSensitiveWords($title_data['title']);
            $content_check = $this->ai_service->checkSensitiveWords($content);

            $has_sensitive = $title_check['has_sensitive'] || $content_check['has_sensitive'];
            $sensitive_word = '';

            if ($has_sensitive) {
                $sensitive_word = $title_check['has_sensitive'] ? $title_check['word'] : $content_check['word'];
                write_log("文章包含敏感词: {$sensitive_word}，标题: {$title_data['title']}", 'WARNING');
            }

            // 确定审核状态和发布状态
            if ($has_sensitive) {
                // 发现敏感词：强制设为草稿，审核状态为 sensitive_word
                $review_status = 'sensitive_word';
                $status = 'draft';
                $published_at = null;
            } else {
                // 未发现敏感词：按任务配置决定
                $review_status = $task['need_review'] ? 'pending' : 'auto_approved';
                $status = $task['need_review'] ? 'draft' : 'published';
                $published_at = $task['need_review'] ? null : date('Y-m-d H:i:s');
            }
            
            // 准备excerpt（摘要）字段
            $excerpt = '';
            if ($has_sensitive) {
                // 如果包含敏感词，在摘要中标注
                $excerpt = "⚠️ 敏感词触发：{$sensitive_word}";
            }

            // 保存文章
            $stmt = $this->db->prepare("
                INSERT INTO articles (
                    title, slug, content, excerpt, category_id, author_id, task_id,
                    original_keyword, keywords, meta_description, status,
                    review_status, is_ai_generated, published_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ");

            $result = $stmt->execute([
                $title_data['title'],
                $slug,
                $content,
                $excerpt,
                $category_id,
                $author_id,
                $task['id'],
                $title_data['keyword'],
                $keywords,
                $description,
                $status,
                $review_status,
                $published_at
            ]);

            if ($result) {
                $article_id = db_last_insert_id($this->db, 'articles');
                if ($has_sensitive) {
                    write_log("保存AI生成文章成功（包含敏感词 '{$sensitive_word}'）: {$title_data['title']} (ID: $article_id)", 'WARNING');
                } else {
                    write_log("保存AI生成文章成功: {$title_data['title']} (ID: $article_id)", 'INFO');
                }
                return ['success' => true, 'article_id' => $article_id, 'has_sensitive' => $has_sensitive];
            }

            return ['success' => false, 'error' => '保存文章失败'];
            
        } catch (Exception $e) {
            write_log("保存生成文章失败: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 生成文章slug
     */
    private function generateSlug($title) {
        return generate_unique_article_slug($this->db, (string) $title);
    }
    
    /**
     * 获取随机作者
     */
    private function getRandomAuthor() {
        $stmt = $this->db->prepare("SELECT id FROM authors ORDER BY RANDOM() LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['id'] : 1;
    }
    
    /**
     * 获取随机分类
     */
    private function getRandomCategory() {
        $stmt = $this->db->prepare("SELECT id FROM categories ORDER BY RANDOM() LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['id'] : 1;
    }
    
    /**
     * 更新任务统计
     */
    private function updateTaskStats($task_id, bool $isPublished = false) {
        $stmt = $this->db->prepare("
            UPDATE tasks SET 
                created_count = created_count + 1,
                published_count = published_count + ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$isPublished ? 1 : 0, $task_id]);
    }
    
    /**
     * 标记标题已使用
     */
    private function markTitleUsed($title_id) {
        $stmt = $this->db->prepare("UPDATE titles SET used_count = used_count + 1 WHERE id = ?");
        $stmt->execute([$title_id]);
    }
}
