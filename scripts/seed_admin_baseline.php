<?php
/**
 * SEOGEO 中台 · 后台基础配置数据补齐
 * 词库 / 标题库回填 / 标题 / 图库骨架 / 企业申请表单 / 草稿内容任务
 * 全部 updateOrCreate 幂等；任务 paused 草稿态，不自动发布。
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\KeywordLibrary;
use App\Models\Keyword;
use App\Models\TitleLibrary;
use App\Models\Title;
use App\Models\ImageLibrary;
use App\Models\LeadForm;
use App\Models\Task;
use Illuminate\Support\Facades\Schema;

function existingCols(string $table, array $data): array
{
    $cols = Schema::getColumnListing($table);
    return array_intersect_key($data, array_flip($cols));
}

/* ---------- 1. 关键词库 ---------- */
$libraries = [
    '无人机赛事与科技特长' => [
        'desc' => '青少年无人机赛事、科技特长与航模竞赛相关关键词。',
        'keywords' => ['青少年无人机赛事', '科技特长生', '无人机考级', '航模竞赛', '无人机升学路径'],
    ],
    'AI 教育与课后服务' => [
        'desc' => 'K12 AI 教育、课后服务与智能体课程相关关键词。',
        'keywords' => ['AI教育', '人工智能启蒙课', '作文批改AI', 'AI答疑辅导', 'K12课后服务'],
    ],
    '智慧物业与品牌口碑' => [
        'desc' => '智慧物业、节能停车与品牌 GEO 口碑相关关键词。',
        'keywords' => ['智慧物业系统', 'AI节能管理', '智能地锁停车', '品牌口碑优化', 'GEO内容营销'],
    ],
];

$libIds = [];
foreach ($libraries as $name => $conf) {
    $lib = KeywordLibrary::updateOrCreate(
        ['name' => $name],
        ['description' => $conf['desc'], 'keyword_count' => count($conf['keywords'])]
    );
    foreach ($conf['keywords'] as $kw) {
        Keyword::updateOrCreate(
            ['library_id' => $lib->id, 'keyword' => $kw],
            ['used_count' => 0, 'usage_count' => 0]
        );
    }
    $libIds[$name] = $lib->id;
    echo "词库 #{$lib->id} {$name} (".count($conf['keywords'])." 词)\n";
}

/* ---------- 2. 回填现有标题库关联 ---------- */
// 标题库 1 橘园洲书院 → AI 教育词库；标题库 2 新概念无人机 → 无人机词库
TitleLibrary::where('id', 1)->update(existingCols('title_libraries', [
    'keyword_library_id' => $libIds['AI 教育与课后服务'],
    'ai_model_id' => 2,
    'prompt_id' => 6,
    'generation_type' => 'manual',
]));
TitleLibrary::where('id', 2)->update(existingCols('title_libraries', [
    'keyword_library_id' => $libIds['无人机赛事与科技特长'],
    'ai_model_id' => 2,
    'prompt_id' => 6,
    'generation_type' => 'manual',
]));

/* ---------- 3. 标题 ---------- */
$titles = [
    1 => [
        ['公立学校课后 AI 学习空间怎么选', 'AI教育'],
        ['AI 智能体课程在 K12 课后服务中的落地', 'K12课后服务'],
    ],
    2 => [
        ['青少年无人机赛事体系与科技特长路径', '青少年无人机赛事'],
        ['新手入门无人机考级与航模竞赛指南', '无人机考级'],
    ],
];
foreach ($titles as $libId => $rows) {
    foreach ($rows as [$title, $kw]) {
        Title::updateOrCreate(
            ['library_id' => $libId, 'title' => $title],
            ['keyword' => $kw, 'is_ai_generated' => false, 'used_count' => 0, 'usage_count' => 0]
        );
    }
}
echo "标题已回填：库1 / 库2 各 2 条\n";

/* ---------- 4. 图库骨架（不造假图片文件） ---------- */
$imgLib = ImageLibrary::updateOrCreate(
    ['name' => '品牌与文章配图库'],
    ['description' => '官网、公众号与社媒文章配图统一归档。', 'image_count' => 0, 'used_task_count' => 0]
);
echo "图库 #{$imgLib->id} 品牌与文章配图库\n";

/* ---------- 5. 企业开通申请表单 ---------- */
LeadForm::updateOrCreate(
    ['slug' => 'enterprise-apply'],
    [
        'name' => '企业开通申请',
        'status' => LeadForm::STATUS_ACTIVE,
        'description' => '企业客户提交公司信息与业务需求，开通 SEOGEO 智能内容中台。',
        'submit_button_label' => '提交申请',
        'success_message' => '申请已提交，项目负责人将在 1 个工作日内与您联系。',
        'fields' => [
            ['name' => 'company', 'label' => '公司名称', 'type' => 'text', 'required' => true, 'options' => []],
            ['name' => 'contact', 'label' => '联系人', 'type' => 'text', 'required' => true, 'options' => []],
            ['name' => 'phone', 'label' => '联系电话', 'type' => 'phone', 'required' => true, 'options' => []],
            ['name' => 'industry', 'label' => '所属行业', 'type' => 'select', 'required' => false, 'options' => ['AI 教育', '无人机 / 科技', '智慧物业', '品牌营销', '其他']],
            ['name' => 'need', 'label' => '业务需求', 'type' => 'textarea', 'required' => false, 'options' => []],
        ],
    ]
);
echo "表单 enterprise-apply 已就位\n";

/* ---------- 6. 草稿内容任务（paused，不自动发布） ---------- */
$tasks = [
    [
        'name' => '无人机赛事内容专栏',
        'title_library_id' => 2,
        'prompt_id' => 3,
        'ai_model_id' => 2,
        'knowledge_base_id' => 6,
        'fixed_category_id' => 2,
        'category_mode' => 'fixed',
    ],
    [
        'name' => 'AI 教育品牌内容',
        'title_library_id' => 1,
        'prompt_id' => 6,
        'ai_model_id' => 4,
        'knowledge_base_id' => 7,
        'fixed_category_id' => 1,
        'category_mode' => 'fixed',
    ],
];
foreach ($tasks as $t) {
    Task::updateOrCreate(
        ['name' => $t['name']],
        existingCols('tasks', [
            'title_library_id' => $t['title_library_id'],
            'image_library_id' => $imgLib->id,
            'image_count' => 0,
            'prompt_id' => $t['prompt_id'],
            'ai_model_id' => $t['ai_model_id'],
            'author_id' => 1,
            'need_review' => 1,
            'publish_interval' => 3600,
            'author_type' => 'fixed',
            'auto_keywords' => 1,
            'auto_description' => 1,
            'draft_limit' => 5,
            'article_limit' => 10,
            'is_loop' => 0,
            'model_selection_mode' => 'fixed',
            'status' => 'paused',
            'publish_scope' => 'draft',
            'distribution_strategy' => 'all',
            'created_count' => 0,
            'published_count' => 0,
            'loop_count' => 0,
            'knowledge_base_id' => $t['knowledge_base_id'],
            'category_mode' => $t['category_mode'],
            'fixed_category_id' => $t['fixed_category_id'],
            'schedule_enabled' => 0,
            'max_retry_count' => 2,
        ])
    );
    echo "任务 {$t['name']}（paused 草稿态）\n";
}

echo "BASELINE_SEED_DONE\n";
