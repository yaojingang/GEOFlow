<?php
/**
 * GEO+AI内容生成系统 - AI服务类
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-05
 */

// 防止直接访问
if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/knowledge-retrieval.php';

class AIService {
    private const AI_REQUEST_TIMEOUT_SECONDS = 180;
    private $db;
    
    public function __construct() {
        global $db;
        $this->db = $db;
    }
    
    /**
     * 调用AI API生成内容
     */
    public function generateContent($model_id, $prompt, $variables = []) {
        try {
            // 获取AI模型配置
            $model = $this->getAIModel($model_id);
            if (!$model) {
                throw new Exception('AI模型不存在');
            }
            
            // 检查每日调用限制
            if ($model['daily_limit'] > 0 && $model['used_today'] >= $model['daily_limit']) {
                throw new Exception('今日API调用次数已达上限');
            }
            
            // 替换提示词中的变量
            $processed_prompt = $this->processPromptVariables($prompt, $variables);
            
            // 调用API
            $response = $this->callAPI($model, $processed_prompt);
            
            // 更新使用次数
            $this->updateModelUsage($model_id);
            
            return [
                'success' => true,
                'content' => $response,
                'model' => $model['name']
            ];
            
        } catch (Exception $e) {
            write_log("AI生成内容失败: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 调用兔子API
     */
    private function callAPI($model, $prompt) {
        $url = ai_chat_endpoint_from_url((string) ($model['api_url'] ?? ''));
        
        $data = [
            'model' => $model['model_id'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => 4000,
            'temperature' => 0.7
        ];
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $model['api_key']
        ];
        
        $ch = curl_init();
        apply_curl_network_defaults($ch);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::AI_REQUEST_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('CURL错误: ' . $error);
        }
        
        if ($http_code !== 200) {
            throw new Exception('API调用失败，HTTP状态码: ' . $http_code);
        }
        
        $result = json_decode($response, true);
        if (!$result || !isset($result['choices'][0]['message']['content'])) {
            throw new Exception('API响应格式错误');
        }
        
        return trim($result['choices'][0]['message']['content']);
    }
    
    /**
     * 处理提示词变量替换
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
     * 获取AI模型配置
     */
    private function getAIModel($model_id) {
        $stmt = $this->db->prepare("
            SELECT *
            FROM ai_models
            WHERE id = ?
              AND status = 'active'
              AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
        ");
        $stmt->execute([$model_id]);
        $model = $stmt->fetch();
        if ($model) {
            $model['api_key'] = decrypt_ai_api_key($model['api_key'] ?? '');
        }
        return $model;
    }
    
    /**
     * 更新模型使用次数
     */
    private function updateModelUsage($model_id) {
        $today = date('Y-m-d');
        
        // 检查是否需要重置今日使用次数
        $stmt = $this->db->prepare("SELECT DATE(updated_at) as last_update FROM ai_models WHERE id = ?");
        $stmt->execute([$model_id]);
        $result = $stmt->fetch();
        
        if ($result && $result['last_update'] !== $today) {
            // 新的一天，重置今日使用次数
            $stmt = $this->db->prepare("UPDATE ai_models SET used_today = 1, total_used = total_used + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        } else {
            // 增加使用次数
            $stmt = $this->db->prepare("UPDATE ai_models SET used_today = used_today + 1, total_used = total_used + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        }
        
        $stmt->execute([$model_id]);
    }
    
    /**
     * 生成文章内容
     */
    public function generateArticle($task_id, $title, $keyword = '') {
        try {
            // 获取任务配置
            $task = $this->getTask($task_id);
            if (!$task) {
                throw new Exception('任务不存在');
            }
            
            // 获取提示词
            $prompt_content = $this->getPromptContent($task['prompt_id']);
            if (!$prompt_content) {
                throw new Exception('提示词不存在');
            }
            
            // 准备变量
            $variables = [
                'title' => $title,
                'keyword' => $keyword
            ];
            
            // 如果任务关联了知识库，添加知识库内容
            if (!empty($task['knowledge_base_id'])) {
                $knowledge = $this->getKnowledgeBase($task['knowledge_base_id']);
                if ($knowledge) {
                    $variables['Knowledge'] = $this->resolveKnowledgeContext(
                        (int) $task['knowledge_base_id'],
                        (string) ($knowledge['content'] ?? ''),
                        (string) $title,
                        (string) $keyword
                    );
                }
            }
            
            // 生成内容
            $result = $this->generateContent($task['ai_model_id'], $prompt_content, $variables);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            return [
                'success' => true,
                'content' => $result['content'],
                'model' => $result['model']
            ];
            
        } catch (Exception $e) {
            write_log("生成文章失败: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 提取关键词
     */
    public function extractKeywords($content) {
        try {
            // 获取关键词提示词
            $prompt = $this->getPromptByType('keyword');
            if (!$prompt) {
                throw new Exception('关键词提示词未配置');
            }
            
            // 获取默认AI模型
            $model = $this->getDefaultAIModel();
            if (!$model) {
                throw new Exception('未配置默认AI模型');
            }
            
            $variables = ['content' => $content];
            $result = $this->generateContent($model['id'], $prompt['content'], $variables);
            
            if ($result['success']) {
                return trim($result['content']);
            }
            
            return '';
            
        } catch (Exception $e) {
            write_log("提取关键词失败: " . $e->getMessage(), 'ERROR');
            return '';
        }
    }
    
    /**
     * 生成描述
     */
    public function generateDescription($content) {
        try {
            // 获取描述提示词
            $prompt = $this->getPromptByType('description');
            if (!$prompt) {
                throw new Exception('描述提示词未配置');
            }
            
            // 获取默认AI模型
            $model = $this->getDefaultAIModel();
            if (!$model) {
                throw new Exception('未配置默认AI模型');
            }
            
            $variables = ['content' => $content];
            $result = $this->generateContent($model['id'], $prompt['content'], $variables);
            
            if ($result['success']) {
                return trim($result['content']);
            }
            
            return '';
            
        } catch (Exception $e) {
            write_log("生成描述失败: " . $e->getMessage(), 'ERROR');
            return '';
        }
    }
    
    /**
     * 获取任务信息
     */
    private function getTask($task_id) {
        $stmt = $this->db->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        return $stmt->fetch();
    }
    
    /**
     * 获取提示词内容
     */
    private function getPromptContent($prompt_id) {
        $stmt = $this->db->prepare("SELECT content FROM prompts WHERE id = ?");
        $stmt->execute([$prompt_id]);
        $result = $stmt->fetch();
        return $result ? $result['content'] : null;
    }
    
    /**
     * 根据类型获取提示词
     */
    private function getPromptByType($type) {
        $stmt = $this->db->prepare("
            SELECT *
            FROM prompts
            WHERE type = ?
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$type]);
        return $stmt->fetch();
    }
    
    /**
     * 获取知识库内容
     */
    private function getKnowledgeBase($kb_id) {
        $stmt = $this->db->prepare("SELECT * FROM knowledge_bases WHERE id = ?");
        $stmt->execute([$kb_id]);
        return $stmt->fetch();
    }

    private function resolveKnowledgeContext(int $knowledgeBaseId, string $content, string $title, string $keyword): string {
        $content = trim($content);
        if ($knowledgeBaseId <= 0 || $content === '') {
            return $content;
        }

        try {
            knowledge_retrieval_ensure_chunks($this->db, $knowledgeBaseId, $content);
            $query = trim($title . "\n" . $keyword);
            $result = knowledge_retrieval_fetch_context($this->db, $knowledgeBaseId, $query, 4, 2400);
            if (!empty($result['context'])) {
                return $result['context'];
            }
        } catch (Throwable $e) {
            error_log('AIService 知识库检索失败: ' . $e->getMessage());
        }

        if (mb_strlen($content, 'UTF-8') > 2400) {
            return mb_substr($content, 0, 2400, 'UTF-8');
        }

        return $content;
    }
    
    /**
     * 获取默认AI模型
     */
    private function getDefaultAIModel() {
        $stmt = $this->db->prepare("
            SELECT *
            FROM ai_models
            WHERE status = 'active'
              AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute();
        return $stmt->fetch();
    }
    
    /**
     * 检查敏感词（不区分大小写，返回所有匹配的敏感词）
     */
    public function checkSensitiveWords($content) {
        $stmt = $this->db->prepare("SELECT word FROM sensitive_words ORDER BY LENGTH(word) DESC");
        $stmt->execute();
        $sensitive_words = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $found_words = [];
        $content_lower = mb_strtolower($content, 'UTF-8');

        foreach ($sensitive_words as $word) {
            $word_lower = mb_strtolower($word, 'UTF-8');
            // 使用 mb_strpos 支持中文，不区分大小写
            if (mb_strpos($content_lower, $word_lower, 0, 'UTF-8') !== false) {
                $found_words[] = $word;
            }
        }

        if (!empty($found_words)) {
            return [
                'has_sensitive' => true,
                'word' => $found_words[0], // 返回第一个匹配的敏感词（最长的）
                'all_words' => $found_words, // 返回所有匹配的敏感词
                'count' => count($found_words)
            ];
        }

        return [
            'has_sensitive' => false,
            'word' => '',
            'all_words' => [],
            'count' => 0
        ];
    }

    /**
     * 生成文章摘要
     */
    public function generateExcerpt($content, $max_length = 200) {
        try {
            // 获取默认AI模型
            $model = $this->getDefaultAIModel();
            if (!$model) {
                throw new Exception('没有可用的AI模型');
            }

            $prompt = "请为以下文章内容生成一个吸引人的摘要，长度控制在{$max_length}字符以内：\n\n" . substr($content, 0, 2000);

            $excerpt = $this->callAPI($model['model_name'], $prompt);

            // 确保长度不超过限制
            if (mb_strlen($excerpt) > $max_length) {
                $excerpt = mb_substr($excerpt, 0, $max_length - 3) . '...';
            }

            return trim($excerpt);

        } catch (Exception $e) {
            error_log('摘要生成失败: ' . $e->getMessage());
            return '';
        }
    }
}
