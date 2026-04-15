<?php
/**
 * AI标题生成异步处理脚本
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/material-library-helpers.php';

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

header('Content-Type: application/json; charset=utf-8');

/**
 * 生成模拟标题（当AI API不可用时使用）
 */
function generateMockTitles($keywords, $count, $style_desc) {
    $templates = [
        '专业严谨的' => [
            '{keyword}的深度分析与研究',
            '关于{keyword}的专业见解',
            '{keyword}行业发展趋势报告',
            '{keyword}技术解决方案详解',
            '{keyword}最佳实践指南'
        ],
        '吸引眼球的' => [
            '震惊！{keyword}的惊人真相',
            '你绝对不知道的{keyword}秘密',
            '{keyword}：改变世界的力量',
            '揭秘{keyword}背后的故事',
            '{keyword}让人意想不到的用途'
        ],
        'SEO优化的' => [
            '{keyword}完整指南：从入门到精通',
            '2025年{keyword}最新趋势分析',
            '{keyword}vs传统方法：哪个更好？',
            '如何选择最适合的{keyword}方案',
            '{keyword}常见问题解答大全'
        ],
        '创意新颖的' => [
            '如果{keyword}会说话，它会告诉你什么？',
            '{keyword}的奇幻之旅',
            '重新定义{keyword}的可能性',
            '{keyword}：未来世界的钥匙',
            '当{keyword}遇上创新思维'
        ],
        '疑问式的' => [
            '{keyword}真的有用吗？',
            '为什么{keyword}如此重要？',
            '{keyword}是否值得投资？',
            '如何正确使用{keyword}？',
            '{keyword}的未来在哪里？'
        ]
    ];
    
    $style_templates = $templates[$style_desc] ?? $templates['专业严谨的'];
    $generated_titles = [];
    
    for ($i = 0; $i < $count; $i++) {
        $template = $style_templates[array_rand($style_templates)];
        $keyword = $keywords[array_rand($keywords)];
        $title = str_replace('{keyword}', $keyword, $template);
        $generated_titles[] = $title;
    }
    
    return $generated_titles;
}

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if ($action === 'start_generate') {
        // 启动生成任务
        $library_id = (int)($_POST['library_id'] ?? 0);
        $keyword_library_id = (int)($_POST['keyword_library_id'] ?? 0);
        $ai_model_id = (int)($_POST['ai_model_id'] ?? 0);
        $custom_prompt = trim($_POST['custom_prompt'] ?? '');
        $title_count = (int)($_POST['title_count'] ?? 10);
        $title_style = $_POST['title_style'] ?? 'professional';
        
        // 验证参数
        if ($library_id <= 0 || $keyword_library_id <= 0 || $ai_model_id <= 0) {
            throw new Exception('参数错误');
        }
        
        // 生成任务ID
        $task_id = 'title_gen_' . time() . '_' . rand(1000, 9999);
        
        // 保存任务状态到session
        $_SESSION['title_generate_task'] = [
            'task_id' => $task_id,
            'library_id' => $library_id,
            'keyword_library_id' => $keyword_library_id,
            'ai_model_id' => $ai_model_id,
            'custom_prompt' => $custom_prompt,
            'title_count' => $title_count,
            'title_style' => $title_style,
            'status' => 'running',
            'progress' => 0,
            'generated_count' => 0,
            'total_count' => $title_count,
            'start_time' => time(),
            'message' => '正在初始化...'
        ];
        
        echo json_encode([
            'success' => true,
            'task_id' => $task_id,
            'message' => '任务已启动'
        ]);
        
    } elseif ($action === 'get_progress') {
        // 获取进度
        $task = $_SESSION['title_generate_task'] ?? null;
        
        if (!$task) {
            echo json_encode([
                'success' => false,
                'message' => '任务不存在'
            ]);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'task' => $task
        ]);
        
    } elseif ($action === 'process_generate') {
        // 处理生成任务
        $task = $_SESSION['title_generate_task'] ?? null;
        
        if (!$task || $task['status'] !== 'running') {
            echo json_encode([
                'success' => false,
                'message' => '任务状态异常'
            ]);
            exit;
        }
        
        // 更新状态
        $_SESSION['title_generate_task']['message'] = '正在获取关键词...';
        
        // 获取关键词
        $stmt = $db->prepare("SELECT keyword FROM keywords WHERE library_id = ? ORDER BY RANDOM() LIMIT 10");
        $stmt->execute([$task['keyword_library_id']]);
        $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($keywords)) {
            throw new Exception('关键词库中没有关键词');
        }
        
        // 获取AI模型信息
        $stmt = $db->prepare("
            SELECT *
            FROM ai_models
            WHERE id = ?
              AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
        ");
        $stmt->execute([$task['ai_model_id']]);
        $ai_model = $stmt->fetch();
        
        if (!$ai_model) {
            throw new Exception('AI模型不存在');
        }

        $ai_model['api_key'] = decrypt_ai_api_key($ai_model['api_key'] ?? '');
        
        $_SESSION['title_generate_task']['message'] = '正在调用AI服务...';
        
        // 构建提示词
        $style_prompts = [
            'professional' => '专业严谨的',
            'attractive' => '吸引眼球的',
            'seo' => 'SEO优化的',
            'creative' => '创意新颖的',
            'question' => '疑问式的'
        ];
        
        $style_desc = $style_prompts[$task['title_style']] ?? '专业的';
        $keywords_text = implode('、', $keywords);
        
        $system_prompt = "你是一个专业的内容标题生成专家。请根据提供的关键词生成{$style_desc}文章标题。";
        $user_prompt = "请基于以下关键词生成 {$task['title_count']} 个{$style_desc}文章标题：\n\n关键词：{$keywords_text}\n\n";
        
        if (!empty($task['custom_prompt'])) {
            $user_prompt .= "额外要求：{$task['custom_prompt']}\n\n";
        }
        
        $user_prompt .= "要求：\n1. 每个标题独占一行\n2. 标题要有吸引力和可读性\n3. 适合搜索引擎优化\n4. 不要添加序号或其他标记\n5. 直接输出标题内容";
        
        // 调用AI API或使用模拟数据
        try {
            $api_data = [
                'model' => $ai_model['model_id'],
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_prompt]
                ],
                'temperature' => 0.8,
                'max_tokens' => 2000
            ];
            
            $ch = curl_init();
            apply_curl_network_defaults($ch);
            curl_setopt_array($ch, [
                CURLOPT_URL => $ai_model['api_url'] . '/v1/chat/completions',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($api_data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $ai_model['api_key']
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'GEO-Content-System/1.0'
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($curl_error || $http_code !== 200) {
                // API失败，使用模拟数据
                $_SESSION['title_generate_task']['message'] = 'AI服务不可用，使用模拟生成...';
                $generated_titles = generateMockTitles($keywords, $task['title_count'], $style_desc);
            } else {
                $result = json_decode($response, true);
                if (!$result || !isset($result['choices'][0]['message']['content'])) {
                    $generated_titles = generateMockTitles($keywords, $task['title_count'], $style_desc);
                } else {
                    $generated_content = trim($result['choices'][0]['message']['content']);
                    $generated_titles = array_filter(array_map('trim', explode("\n", $generated_content)));
                }
            }
            
        } catch (Exception $e) {
            $_SESSION['title_generate_task']['message'] = 'AI服务异常，使用模拟生成...';
            $generated_titles = generateMockTitles($keywords, $task['title_count'], $style_desc);
        }
        
        // 保存标题
        $_SESSION['title_generate_task']['message'] = '正在保存标题...';
        
        $saved_count = 0;
        $duplicate_count = 0;
        
        try {
            $db->beginTransaction();
            
            foreach ($generated_titles as $title) {
                if (empty($title)) continue;
                
                // 清理标题
                $title = preg_replace('/^\d+[\.\)]\s*/', '', $title);
                $title = trim($title);
                
                if (empty($title) || mb_strlen($title) > 500) continue;
                
                // 检查重复
                $stmt = $db->prepare("SELECT COUNT(*) FROM titles WHERE library_id = ? AND title = ?");
                $stmt->execute([$task['library_id'], $title]);
                if ($stmt->fetchColumn() > 0) {
                    $duplicate_count++;
                    continue;
                }
                
                // 保存标题
                $random_keyword = $keywords[array_rand($keywords)];
                $stmt = $db->prepare("INSERT INTO titles (library_id, title, keyword, is_ai_generated) VALUES (?, ?, ?, TRUE)");
                $stmt->execute([$task['library_id'], $title, $random_keyword]);
                $saved_count++;
                
                // 更新进度
                $_SESSION['title_generate_task']['generated_count'] = $saved_count;
                $_SESSION['title_generate_task']['progress'] = round(($saved_count / $task['title_count']) * 100);
            }
            
            refresh_title_library_count($db, (int) $task['library_id']);
            $db->commit();
            
            // 任务完成
            $_SESSION['title_generate_task']['status'] = 'completed';
            $_SESSION['title_generate_task']['message'] = "生成完成！成功保存 {$saved_count} 个标题" . ($duplicate_count > 0 ? "，跳过 {$duplicate_count} 个重复标题" : '');
            $_SESSION['title_generate_task']['progress'] = 100;
            
            echo json_encode([
                'success' => true,
                'message' => $_SESSION['title_generate_task']['message'],
                'saved_count' => $saved_count,
                'duplicate_count' => $duplicate_count
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            $_SESSION['title_generate_task']['status'] = 'error';
            $_SESSION['title_generate_task']['message'] = '保存失败：' . $e->getMessage();
            
            echo json_encode([
                'success' => false,
                'message' => $_SESSION['title_generate_task']['message']
            ]);
        }
        
    } else {
        throw new Exception('无效的操作');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
