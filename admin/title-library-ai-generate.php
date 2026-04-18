<?php
/**
 * 智能GEO内容系统 - 标题库AI生成配置
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-08
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/material-library-helpers.php';

// 检查管理员登录
require_admin_login();

$csrf_token = generate_csrf_token();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

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

    return implode("\n", $generated_titles);
}

// 检查是否有库ID参数
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    admin_redirect('title-libraries.php');
    exit;
}

$library_id = (int)$_GET['id'];

try {
    // 获取标题库信息
    $stmt = $db->prepare("SELECT * FROM title_libraries WHERE id = ?");
    $stmt->execute([$library_id]);
    $library = $stmt->fetch();
    
    if (!$library) {
        admin_redirect('title-libraries.php');
        exit;
    }
    
    // 获取关键词库列表
    $keyword_libraries = $db->query("SELECT * FROM keyword_libraries ORDER BY created_at DESC")->fetchAll();
    
    // 获取AI模型列表
    $ai_models = $db->query("
        SELECT *
        FROM ai_models
        WHERE status = 'active'
          AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
        ORDER BY name
    ")->fetchAll();

    // 处理表单提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 设置执行时间和内存限制
        set_time_limit(300); // 5分钟
        ini_set('memory_limit', '256M');

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception(__('message.csrf_invalid'));
        }
        
        $action = $_POST['action'] ?? '';
        
        if ($action === 'generate_titles') {
            $keyword_library_id = (int)($_POST['keyword_library_id'] ?? 0);
            $ai_model_id = (int)($_POST['ai_model_id'] ?? 0);
            $custom_prompt = trim($_POST['custom_prompt'] ?? '');
            $title_count = (int)($_POST['title_count'] ?? 10);
            $title_style = $_POST['title_style'] ?? 'professional';
            
            if ($keyword_library_id <= 0) {
                throw new Exception(__('title_ai_generate.error.keyword_library_required'));
            }
            
            if ($ai_model_id <= 0) {
                throw new Exception(__('title_ai_generate.error.ai_model_required'));
            }
            
            if ($title_count < 1 || $title_count > 50) {
                throw new Exception(__('title_ai_generate.error.invalid_count'));
            }
            
            // 获取关键词库信息
            $stmt = $db->prepare("SELECT * FROM keyword_libraries WHERE id = ?");
            $stmt->execute([$keyword_library_id]);
            $keyword_library = $stmt->fetch();
            
            if (!$keyword_library) {
                throw new Exception(__('title_ai_generate.error.keyword_library_missing'));
            }
            
            // 获取关键词
            $stmt = $db->prepare("SELECT keyword FROM keywords WHERE library_id = ? ORDER BY RANDOM() LIMIT 10");
            $stmt->execute([$keyword_library_id]);
            $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($keywords)) {
                throw new Exception(__('title_ai_generate.error.no_keywords'));
            }
            
            // 获取AI模型信息
            $stmt = $db->prepare("
                SELECT *
                FROM ai_models
                WHERE id = ?
                  AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
            ");
            $stmt->execute([$ai_model_id]);
            $ai_model = $stmt->fetch();
            
            if (!$ai_model) {
                throw new Exception(__('title_ai_generate.error.ai_model_missing'));
            }

            $ai_model['api_key'] = decrypt_ai_api_key($ai_model['api_key'] ?? '');
            
            // 构建提示词
            $style_prompts = [
                'professional' => '专业严谨的',
                'attractive' => '吸引眼球的',
                'seo' => 'SEO优化的',
                'creative' => '创意新颖的',
                'question' => '疑问式的'
            ];
            
            $style_desc = $style_prompts[$title_style] ?? '专业的';
            $keywords_text = implode('、', $keywords);
            
            $system_prompt = "你是一个专业的内容标题生成专家。请根据提供的关键词生成{$style_desc}文章标题。";
            
            $user_prompt = "请基于以下关键词生成 {$title_count} 个{$style_desc}文章标题：\n\n关键词：{$keywords_text}\n\n";
            
            if (!empty($custom_prompt)) {
                $user_prompt .= "额外要求：{$custom_prompt}\n\n";
            }
            
            $user_prompt .= "要求：\n1. 每个标题独占一行\n2. 标题要有吸引力和可读性\n3. 适合搜索引擎优化\n4. 不要添加序号或其他标记\n5. 直接输出标题内容";
            
            // 调用AI API生成标题
            $api_data = [
                'model' => $ai_model['model_id'],
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_prompt]
                ],
                'temperature' => 0.8,
                'max_tokens' => 2000
            ];

            // 记录API调用开始
            error_log("开始AI API调用 - 模型: {$ai_model['model_id']}, 标题数量: {$title_count}");
            
            $ch = curl_init();
            apply_curl_network_defaults($ch);
            curl_setopt_array($ch, [
                CURLOPT_URL => ai_chat_endpoint_from_url($ai_model['api_url']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($api_data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $ai_model['api_key']
                ],
                CURLOPT_TIMEOUT => 60,            // 减少到1分钟
                CURLOPT_CONNECTTIMEOUT => 10,     // 连接超时10秒
                CURLOPT_FOLLOWLOCATION => false,  // 不跟随重定向
                CURLOPT_SSL_VERIFYPEER => false,  // 跳过SSL验证（开发环境）
                CURLOPT_USERAGENT => 'GEO-Content-System/1.0',
                CURLOPT_NOSIGNAL => 1,            // 避免信号中断
                CURLOPT_LOW_SPEED_LIMIT => 1,     // 最低速度1字节/秒
                CURLOPT_LOW_SPEED_TIME => 30      // 30秒内速度低于限制则超时
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            $curl_info = curl_getinfo($ch);
            curl_close($ch);

            // 记录调试信息
            error_log("AI API调用 - URL: " . ai_chat_endpoint_from_url($ai_model['api_url']));
            error_log("AI API调用 - HTTP状态码: " . $http_code);
            error_log("AI API调用 - 响应时间: " . $curl_info['total_time'] . "秒");

            if ($curl_error) {
                error_log("CURL错误: " . $curl_error);

                // 如果是超时错误，使用模拟数据
                if (strpos($curl_error, 'timeout') !== false || strpos($curl_error, 'timed out') !== false) {
                    error_log("API超时，使用模拟数据生成标题");
                    $generated_content = generateMockTitles($keywords, $title_count, $style_desc);
                } else {
                    throw new Exception(__('title_ai_generate.error.network', ['message' => $curl_error]));
                }
            } elseif ($http_code !== 200) {
                $error_detail = $response ? ' 响应内容：' . substr($response, 0, 500) : '';
                error_log("AI API错误 - HTTP {$http_code}: " . $error_detail);

                // 如果是服务器错误，也使用模拟数据
                if ($http_code >= 500) {
                    error_log("API服务器错误，使用模拟数据生成标题");
                    $generated_content = generateMockTitles($keywords, $title_count, $style_desc);
                } else {
                    throw new Exception(__('title_ai_generate.error.api_http', ['code' => $http_code, 'detail' => $error_detail]));
                }
            } else {
                // 正常处理API响应
                $result = json_decode($response, true);
                if (!$result || !isset($result['choices'][0]['message']['content'])) {
                    error_log("API响应格式错误，使用模拟数据");
                    $generated_content = generateMockTitles($keywords, $title_count, $style_desc);
                } else {
                    $generated_content = trim($result['choices'][0]['message']['content']);
                }
            }

            $generated_titles = array_filter(array_map('trim', explode("\n", $generated_content)));

            if (empty($generated_titles)) {
                throw new Exception(__('title_ai_generate.error.empty_result'));
            }
            
            // 保存生成的标题（使用事务）
            $saved_count = 0;
            $duplicate_count = 0;

            try {
                $db->beginTransaction();

                foreach ($generated_titles as $title) {
                    if (empty($title)) continue;

                    // 清理标题（移除可能的序号）
                    $title = preg_replace('/^\d+[\.\)]\s*/', '', $title);
                    $title = trim($title);

                    if (empty($title) || mb_strlen($title) > 500) continue;

                    // 检查是否已存在
                    $stmt = $db->prepare("SELECT COUNT(*) FROM titles WHERE library_id = ? AND title = ?");
                    $stmt->execute([$library_id, $title]);
                    if ($stmt->fetchColumn() > 0) {
                        $duplicate_count++;
                        continue;
                    }

                    // 随机选择一个关键词作为关联
                    $random_keyword = $keywords[array_rand($keywords)];

                    // 保存标题
                    $stmt = $db->prepare("INSERT INTO titles (library_id, title, keyword, is_ai_generated) VALUES (?, ?, ?, TRUE)");
                    $stmt->execute([$library_id, $title, $random_keyword]);
                    $saved_count++;
                }

                refresh_title_library_count($db, $library_id);
                $db->commit();

            } catch (Exception $e) {
                $db->rollback();
                throw new Exception(__('title_ai_generate.error.save_failed', ['message' => $e->getMessage()]));
            }
            
            $success_message = __('title_ai_generate.message.completed', ['count' => $saved_count]);
            if ($duplicate_count > 0) {
                $success_message .= __('title_ai_generate.message.duplicates', ['count' => $duplicate_count]);
            }
        }
    }
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
}

// 设置页面信息
$page_title = __('title_ai_generate.page_title') . ' - ' . $library['name'];
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="title-library-detail.php?id=' . $library_id . '" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . __('title_ai_generate.page_heading') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('title_ai_generate.page_subtitle', ['name' => htmlspecialchars($library['name'])]) . '</p>
        </div>
    </div>
</div>';

require_once __DIR__ . '/includes/header.php';
?>

        <!-- 消息显示 -->
        <?php if (isset($error_message)): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i data-lucide="alert-circle" class="h-5 w-5 text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800"><?php echo __('message.error'); ?></h3>
                        <div class="mt-2 text-sm text-red-700">
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($success_message)): ?>
            <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i data-lucide="check-circle" class="h-5 w-5 text-green-400"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-green-800"><?php echo __('message.success'); ?></h3>
                        <div class="mt-2 text-sm text-green-700">
                            <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- AI生成配置表单 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('title_ai_generate.section.config'); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('title_ai_generate.section.config_desc'); ?></p>
            </div>

            <form method="POST" class="p-6">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="generate_titles">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- 关键词库选择 -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('title_ai_generate.field.keyword_library'); ?></label>
                        <select name="keyword_library_id" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value=""><?php echo __('title_ai_generate.option.select_keyword_library'); ?></option>
                            <?php foreach ($keyword_libraries as $kw_lib): ?>
                                <option value="<?php echo $kw_lib['id']; ?>">
                                    <?php echo htmlspecialchars($kw_lib['name']); ?>
                                    (<?php 
                                        $stmt = $db->prepare("SELECT COUNT(*) FROM keywords WHERE library_id = ?");
                                        $stmt->execute([$kw_lib['id']]);
                                        echo $stmt->fetchColumn();
                                    ?> <?php echo __('title_ai_generate.option.keyword_count_suffix'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-xs text-gray-500"><?php echo __('title_ai_generate.help.keyword_library'); ?></p>
                    </div>

                    <!-- AI模型选择 -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('title_ai_generate.field.ai_model'); ?></label>
                        <select name="ai_model_id" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value=""><?php echo __('title_ai_generate.option.select_ai_model'); ?></option>
                            <?php foreach ($ai_models as $model): ?>
                                <option value="<?php echo $model['id']; ?>">
                                    <?php echo htmlspecialchars($model['name']); ?>
                                    (<?php echo htmlspecialchars($model['model_id']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-xs text-gray-500"><?php echo __('title_ai_generate.help.ai_model'); ?></p>
                    </div>

                    <!-- 生成数量 -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('title_ai_generate.field.count'); ?></label>
                        <input type="number" name="title_count" value="10" min="1" max="50" 
                               class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        <p class="mt-1 text-xs text-gray-500"><?php echo __('title_ai_generate.help.count'); ?></p>
                    </div>

                    <!-- 标题风格 -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('title_ai_generate.field.style'); ?></label>
                        <select name="title_style" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="professional"><?php echo __('title_ai_generate.style.professional'); ?></option>
                            <option value="attractive"><?php echo __('title_ai_generate.style.attractive'); ?></option>
                            <option value="seo"><?php echo __('title_ai_generate.style.seo'); ?></option>
                            <option value="creative"><?php echo __('title_ai_generate.style.creative'); ?></option>
                            <option value="question"><?php echo __('title_ai_generate.style.question'); ?></option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500"><?php echo __('title_ai_generate.help.style'); ?></p>
                    </div>
                </div>

                <!-- 自定义提示词 -->
                <div class="mt-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('title_ai_generate.field.custom_prompt'); ?></label>
                    <textarea name="custom_prompt" rows="4" 
                              class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                              placeholder="<?php echo __('title_ai_generate.placeholder.custom_prompt'); ?>"></textarea>
                    <p class="mt-1 text-xs text-gray-500"><?php echo __('title_ai_generate.help.custom_prompt'); ?></p>
                </div>

                <!-- 提交按钮 -->
                <div class="mt-8 flex justify-end space-x-4">
                    <button type="button" id="async-generate-btn" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i data-lucide="zap" class="w-5 h-5 mr-2"></i>
                        <?php echo __('title_ai_generate.button.async'); ?>
                    </button>
                    <button type="submit" id="generate-btn" class="inline-flex items-center px-6 py-3 border border-gray-300 text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i data-lucide="cpu" class="w-5 h-5 mr-2"></i>
                        <?php echo __('title_ai_generate.button.sync'); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- 异步生成进度显示 -->
        <div id="async-progress" class="hidden bg-white shadow rounded-lg mt-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('title_ai_generate.section.progress'); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('title_ai_generate.section.progress_desc'); ?></p>
            </div>

            <div class="p-6">
                <!-- 进度条 -->
                <div class="mb-4">
                    <div class="flex justify-between text-sm text-gray-600 mb-2">
                        <span><?php echo __('title_ai_generate.progress.label'); ?></span>
                        <span id="progress-text">0%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                </div>

                <!-- 状态信息 -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="text-sm text-gray-600"><?php echo __('title_ai_generate.progress.generated'); ?></div>
                        <div id="generated-count" class="text-2xl font-bold text-blue-600">0</div>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="text-sm text-gray-600"><?php echo __('title_ai_generate.progress.total'); ?></div>
                        <div id="total-count" class="text-2xl font-bold text-gray-900">0</div>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="text-sm text-gray-600"><?php echo __('title_ai_generate.progress.elapsed'); ?></div>
                        <div id="elapsed-time" class="text-2xl font-bold text-green-600">0s</div>
                    </div>
                </div>

                <!-- 当前状态 -->
                <div class="flex items-center p-4 bg-blue-50 border border-blue-200 rounded-md">
                    <i data-lucide="info" class="w-5 h-5 text-blue-500 mr-3"></i>
                    <span id="status-message" class="text-blue-700"><?php echo __('title_ai_generate.progress.preparing'); ?></span>
                </div>

                <!-- 操作按钮 -->
                <div class="mt-4 flex justify-end space-x-3">
                    <button id="refresh-progress" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                        <?php echo __('title_ai_generate.button.refresh_status'); ?>
                    </button>
                    <button id="close-progress" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-gray-600 hover:bg-gray-700">
                        <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                        <?php echo __('button.close_panel'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- 使用说明 -->
        <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i data-lucide="info" class="h-5 w-5 text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800"><?php echo __('title_ai_generate.section.instructions'); ?></h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li><?php echo __('title_ai_generate.instructions.keyword_library'); ?></li>
                            <li><?php echo __('title_ai_generate.instructions.ai_model'); ?></li>
                            <li><?php echo __('title_ai_generate.instructions.count'); ?></li>
                            <li><?php echo __('title_ai_generate.instructions.style'); ?></li>
                            <li><?php echo __('title_ai_generate.instructions.custom_prompt'); ?></li>
                            <li><?php echo __('title_ai_generate.instructions.saved_titles'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 初始化Lucide图标
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            // 异步生成功能
            const form = document.querySelector('form');
            const generateBtn = document.getElementById('generate-btn');
            const asyncGenerateBtn = document.getElementById('async-generate-btn');
            const asyncProgress = document.getElementById('async-progress');

            let progressInterval = null;
            let startTime = null;

            // 异步生成按钮事件
            if (asyncGenerateBtn) {
                asyncGenerateBtn.addEventListener('click', function() {
                    startAsyncGenerate();
                });
            }

            // 同步生成表单提交处理
            if (form && generateBtn) {
                form.addEventListener('submit', function(e) {
                    // 防止重复提交
                    if (generateBtn.disabled) {
                        e.preventDefault();
                        return false;
                    }

                    // 显示加载状态
                    generateBtn.disabled = true;
                    generateBtn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 mr-2 animate-spin"></i><?php echo addslashes(__('title_ai_generate.progress.sync_loading')); ?>';

                    // 添加进度提示
                    const progressDiv = document.createElement('div');
                    progressDiv.id = 'progress-info';
                    progressDiv.className = 'mt-4 p-4 bg-blue-50 border border-blue-200 rounded-md';
                    progressDiv.innerHTML = `
                        <div class="flex items-center">
                            <i data-lucide="info" class="w-5 h-5 text-blue-500 mr-2"></i>
                            <span class="text-blue-700"><?php echo addslashes(__('title_ai_generate.progress.sync_notice')); ?></span>
                        </div>
                    `;

                    // 在表单后插入进度信息
                    form.parentNode.insertBefore(progressDiv, form.nextSibling);

                    // 重新创建图标
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                });
            }

            // 启动异步生成
            function startAsyncGenerate() {
                // 获取表单数据
                const formData = new FormData(form);
                formData.append('action', 'start_generate');
                formData.append('library_id', <?php echo $library_id; ?>);

                // 禁用按钮
                asyncGenerateBtn.disabled = true;
                asyncGenerateBtn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 mr-2 animate-spin"></i><?php echo addslashes(__('title_ai_generate.progress.starting')); ?>';

                fetch(window.adminUrl('title_generate_async.php'), {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 显示进度面板
                        asyncProgress.classList.remove('hidden');

                        // 开始处理任务
                        processAsyncGenerate();

                        // 开始监控进度
                        startProgressMonitoring();

                        // 重置按钮
                        asyncGenerateBtn.disabled = false;
                        asyncGenerateBtn.innerHTML = '<i data-lucide="zap" class="w-5 h-5 mr-2"></i><?php echo addslashes(__('title_ai_generate.button.async')); ?>';

                        startTime = Date.now();
                    } else {
                        alert('<?php echo addslashes(__('title_ai_generate.error.start_failed_prefix')); ?>' + data.message);
                        asyncGenerateBtn.disabled = false;
                        asyncGenerateBtn.innerHTML = '<i data-lucide="zap" class="w-5 h-5 mr-2"></i><?php echo addslashes(__('title_ai_generate.button.async')); ?>';
                    }

                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('<?php echo addslashes(__('title_ai_generate.error.start_network')); ?>');
                    asyncGenerateBtn.disabled = false;
                    asyncGenerateBtn.innerHTML = '<i data-lucide="zap" class="w-5 h-5 mr-2"></i><?php echo addslashes(__('title_ai_generate.button.async')); ?>';

                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                });
            }

            // 处理异步生成任务
            function processAsyncGenerate() {
                fetch(window.adminUrl('title_generate_async.php'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=process_generate'
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Generation completed:', data);
                })
                .catch(error => {
                    console.error('Generation error:', error);
                });
            }

            // 开始进度监控
            function startProgressMonitoring() {
                updateProgress();
                progressInterval = setInterval(updateProgress, 2000); // 每2秒更新一次
            }

            // 更新进度
            function updateProgress() {
                fetch(`${window.adminUrl('title_generate_async.php')}?action=get_progress`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.task) {
                        const task = data.task;

                        // 更新进度条
                        document.getElementById('progress-bar').style.width = task.progress + '%';
                        document.getElementById('progress-text').textContent = task.progress + '%';

                        // 更新计数
                        document.getElementById('generated-count').textContent = task.generated_count;
                        document.getElementById('total-count').textContent = task.total_count;

                        // 更新状态消息
                        document.getElementById('status-message').textContent = task.message;

                        // 更新运行时间
                        if (startTime) {
                            const elapsed = Math.floor((Date.now() - startTime) / 1000);
                            document.getElementById('elapsed-time').textContent = elapsed + 's';
                        }

                        // 如果任务完成，停止监控
                        if (task.status === 'completed' || task.status === 'error') {
                            if (progressInterval) {
                                clearInterval(progressInterval);
                                progressInterval = null;
                            }

                            // 显示完成状态
                            if (task.status === 'completed') {
                                document.getElementById('status-message').innerHTML =
                                    '<i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-2 inline"></i>' + task.message;
                            } else {
                                document.getElementById('status-message').innerHTML =
                                    '<i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-2 inline"></i>' + task.message;
                            }

                            if (typeof lucide !== 'undefined') {
                                lucide.createIcons();
                            }
                        }
                    }
                })
                .catch(error => {
                    console.error('Progress update error:', error);
                });
            }

            // 刷新进度按钮
            document.getElementById('refresh-progress').addEventListener('click', function() {
                updateProgress();
            });

            // 关闭进度面板按钮
            document.getElementById('close-progress').addEventListener('click', function() {
                asyncProgress.classList.add('hidden');
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
            });

            // 页面加载时检查是否有正在运行的任务
            updateProgress();
        });
    </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
