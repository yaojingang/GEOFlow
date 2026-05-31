<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Database\Seeder;

class FrontendDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSiteSettings();

        $author = Author::query()->updateOrCreate(
            ['email' => 'demo@geoflow.local'],
            [
                'name' => 'GEOFlow 编辑部',
                'bio' => '用于前台模板、分类页和文章页预览的示例作者。',
                'avatar' => '',
                'website' => '',
                'social_links' => '',
            ],
        );

        $categories = $this->seedCategories();

        foreach ($this->articles() as $index => $article) {
            $category = $categories[$article['category_slug']];
            Article::query()->updateOrCreate(
                ['slug' => $article['slug']],
                [
                    'title' => $article['title'],
                    'excerpt' => $article['excerpt'],
                    'content' => $article['content'],
                    'category_id' => $category->id,
                    'author_id' => $author->id,
                    'original_keyword' => $article['keyword'],
                    'keywords' => implode(',', $article['tags']),
                    'meta_description' => $article['excerpt'],
                    'status' => 'published',
                    'review_status' => 'approved',
                    'view_count' => 320 + ($index * 37),
                    'is_ai_generated' => 0,
                    'is_hot' => in_array($index, [0, 3, 6], true),
                    'is_featured' => in_array($index, [0, 1], true),
                    'published_at' => now()->subDays($index)->setTime(9, 30),
                ],
            );
        }

        SiteSettingsBag::forget();
    }

    private function seedSiteSettings(): void
    {
        $settings = [
            'site_name' => 'GEOFlow Support',
            'site_title' => 'GEOFlow Support',
            'site_subtitle' => '像 Apple Support 一样组织你的内容知识库',
            'site_description' => '用于预览 GEOFlow 前台模板的示例内容站，覆盖首页、分类列表页和文章详情页。',
            'site_keywords' => 'GEOFlow,Apple Support,帮助中心,内容知识库,GEO优化',
            'copyright_info' => '© '.date('Y').' GEOFlow Support Demo. All rights reserved.',
            'site_logo' => '',
            'site_favicon' => '',
            'analytics_code' => '',
            'seo_title_template' => '{title} - {site_name}',
            'seo_description_template' => '{description}',
            'featured_limit' => '6',
            'per_page' => '9',
            'home_carousel_slides' => (string) json_encode([
                [
                    'image_url' => 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?auto=format&fit=crop&w=1600&q=80',
                    'title' => 'Mac 支持内容演示',
                    'link_url' => '/category/mac',
                    'enabled' => true,
                ],
                [
                    'image_url' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1600&q=80',
                    'title' => 'AI 内容工作流演示',
                    'link_url' => '/category/ai-content-workflow',
                    'enabled' => true,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'article_detail_ads' => (string) json_encode([
                [
                    'id' => 'demo_support_cta',
                    'name' => '示例文章详情 CTA',
                    'badge' => 'Demo',
                    'title' => '把帮助中心换成你的品牌',
                    'copy' => '这组示例数据用于检查文章详情页的悬浮 CTA、相关阅读和长文排版。',
                    'button_text' => '查看 Mac 分类',
                    'button_url' => '/category/mac',
                    'enabled' => true,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        foreach ($settings as $key => $value) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value],
            );
        }
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $rows = [
            [
                'slug' => 'mac',
                'name' => 'Mac 支持',
                'description' => '围绕 macOS、备份、迁移、连接和性能维护的帮助文章。',
                'sort_order' => 10,
            ],
            [
                'slug' => 'iphone',
                'name' => 'iPhone 使用指南',
                'description' => '用于演示移动设备支持内容的分类页，包括存储空间、家庭共享和隐私设置。',
                'sort_order' => 20,
            ],
            [
                'slug' => 'ai-content-workflow',
                'name' => 'AI 内容工作流',
                'description' => '展示 GEOFlow 如何组织选题、生成、审核和发布 AI 内容。',
                'sort_order' => 30,
            ],
            [
                'slug' => 'geo-growth',
                'name' => 'GEO 增长知识库',
                'description' => '关于 GEO 诊断、AI 搜索可见性和内容结构优化的示例分类。',
                'sort_order' => 40,
            ],
        ];

        $categories = [];
        foreach ($rows as $row) {
            $categories[$row['slug']] = Category::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'sort_order' => $row['sort_order'],
                ],
            );
        }

        return $categories;
    }

    /**
     * @return list<array{
     *   category_slug:string,
     *   slug:string,
     *   title:string,
     *   excerpt:string,
     *   keyword:string,
     *   tags:list<string>,
     *   content:string
     * }>
     */
    private function articles(): array
    {
        return [
            [
                'category_slug' => 'mac',
                'slug' => 'how-to-reinstall-macos',
                'title' => '如何重新安装 macOS',
                'excerpt' => '使用恢复系统重新安装 Mac 操作系统，同时保留文章页的长文阅读结构。',
                'keyword' => '重新安装 macOS',
                'tags' => ['macOS', '恢复系统', '安装'],
                'content' => <<<'MD'
## 从恢复系统重新安装

重新安装之前，请确认网络连接稳定，并在可能的情况下先备份重要数据。这个示例文章用于检查文章详情页的标题、摘要、正文段落、列表、引用和表格样式。

1. 关闭 Mac。
2. 启动到恢复系统。
3. 选择重新安装 macOS，并按照屏幕提示操作。

> 重新安装通常不会移除个人数据，但备份仍然是更稳妥的选择。

## 安装哪个版本

系统会根据设备类型和最近安装的版本决定可用的 macOS 版本。

| 设备 | 默认行为 |
| --- | --- |
| Apple 芯片 Mac | 安装最近安装过的当前版本 |
| Intel Mac | 根据启动组合键决定版本 |

## 如果安装不成功

请检查网络、磁盘状态和启动安全设置，然后重新尝试。如果仍然失败，可以先记录错误提示，再联系技术支持。
MD,
            ],
            [
                'category_slug' => 'mac',
                'slug' => 'back-up-your-mac',
                'title' => '备份你的 Mac',
                'excerpt' => '连接外置存储设备并使用 Time Machine 保留文件、应用和系统设置副本。',
                'keyword' => 'Mac 备份',
                'tags' => ['Time Machine', '备份', '数据安全'],
                'content' => <<<'MD'
## 使用 Time Machine

Time Machine 可以自动保存文件、应用和系统设置副本。首次备份可能需要更长时间，后续备份通常只会记录变更内容。

### 开始之前

- 准备一个容量足够的外置磁盘。
- 保持 Mac 接入电源。
- 在系统设置中选择备份磁盘。

## 检查备份状态

完成备份后，可以从菜单栏或系统设置中查看最近一次备份时间。建议在重要系统更新前手动确认一次备份。

> 示例数据中的引用块用于检查文章页是否保留 Apple Support 风格的浅灰提示区。
MD,
            ],
            [
                'category_slug' => 'mac',
                'slug' => 'move-content-to-a-new-mac',
                'title' => '将内容迁移到新的 Mac',
                'excerpt' => '使用迁移助理复制文档、应用、用户账户和设置。',
                'keyword' => '迁移助理',
                'tags' => ['迁移助理', '新 Mac', '数据迁移'],
                'content' => <<<'MD'
## 使用迁移助理

在两台 Mac 上打开迁移助理，选择传输来源，然后按照屏幕提示继续。为了减少中断，迁移期间不要让任一台设备进入睡眠。

## 可以迁移的内容

- 用户账户和主文件夹
- 应用和系统设置
- 文档、图片和桌面文件
- 网络与辅助功能设置

## 迁移后检查

登录新 Mac 后，打开常用应用，确认文件路径、授权和同步状态。如果应用需要重新登录，请使用原有账号完成授权。
MD,
            ],
            [
                'category_slug' => 'mac',
                'slug' => 'fix-wifi-and-bluetooth-on-mac',
                'title' => '排查 Mac 的 Wi-Fi 和蓝牙连接问题',
                'excerpt' => '通过重启网络、移除旧设备和检查系统设置，恢复常见连接问题。',
                'keyword' => 'Mac 连接问题',
                'tags' => ['Wi-Fi', '蓝牙', '网络'],
                'content' => <<<'MD'
## 先确认范围

如果只有一台设备无法连接，问题通常在设备本身。如果所有设备都无法连接，先检查路由器或网络服务。

## Wi-Fi 排查步骤

1. 关闭 Wi-Fi 后重新打开。
2. 忘记当前网络并重新加入。
3. 重启路由器和 Mac。
4. 检查是否存在 VPN 或安全软件干扰。

## 蓝牙排查步骤

移除旧设备记录后重新配对。如果设备有实体电源按钮，请关闭后等待几秒再开启。
MD,
            ],
            [
                'category_slug' => 'iphone',
                'slug' => 'free-up-storage-on-iphone',
                'title' => '释放 iPhone 存储空间',
                'excerpt' => '查看存储空间建议，卸载不常用应用，并清理大文件和离线内容。',
                'keyword' => 'iPhone 存储空间',
                'tags' => ['iPhone', '存储空间', '设置'],
                'content' => <<<'MD'
## 查看存储空间建议

打开设置中的 iPhone 存储空间，可以看到系统按类别统计的使用情况。优先处理占用最大的应用和媒体文件。

## 常见清理方式

- 卸载不常用应用。
- 删除已下载的视频和播客。
- 清理聊天应用中的大文件。
- 使用云同步保存照片原件。

## 保留重要数据

清理前先确认照片、文件和聊天记录已经完成同步或备份。不要只根据文件大小删除内容。
MD,
            ],
            [
                'category_slug' => 'iphone',
                'slug' => 'set-up-family-sharing',
                'title' => '设置家庭共享和儿童账号',
                'excerpt' => '创建家庭组，管理购买项目、屏幕使用时间和儿童账号安全。',
                'keyword' => '家庭共享',
                'tags' => ['家庭共享', '儿童账号', '隐私'],
                'content' => <<<'MD'
## 创建家庭组

家庭组织者可以邀请成员加入家庭组，并为儿童创建受保护的账号。设置完成后，可以共享购买项目、订阅和定位。

## 建议开启的设置

1. 购买前询问。
2. 屏幕使用时间。
3. 内容和隐私访问限制。
4. 账号恢复联系人。

## 管理提醒

家庭共享适合长期使用，建议定期检查成员、设备和订阅状态。
MD,
            ],
            [
                'category_slug' => 'ai-content-workflow',
                'slug' => 'plan-weekly-content-with-keyword-library',
                'title' => '用关键词库规划一周内容',
                'excerpt' => '把关键词、标题库和分类策略组织成可执行的一周内容计划。',
                'keyword' => '关键词库内容规划',
                'tags' => ['关键词库', '内容计划', 'AI 工作流'],
                'content' => <<<'MD'
## 从关键词库开始

先把关键词按意图分组，再给每组匹配栏目、标题风格和目标读者。这样生成任务会更稳定，也更容易复盘效果。

## 一周计划结构

| 周期 | 重点 |
| --- | --- |
| 周一 | 选题和关键词清洗 |
| 周二到周四 | 批量生成和人工审核 |
| 周五 | 发布、内链和索引检查 |

## 复盘指标

记录标题通过率、人工修改原因、发布时间和页面表现。下一轮内容计划应优先修复重复问题。
MD,
            ],
            [
                'category_slug' => 'ai-content-workflow',
                'slug' => 'review-ai-generated-articles-checklist',
                'title' => '审核 AI 生成文章的清单',
                'excerpt' => '从事实、结构、可读性、品牌语气和 SEO 元信息五个维度检查文章。',
                'keyword' => 'AI 文章审核',
                'tags' => ['审核', 'AI 文章', '质量控制'],
                'content' => <<<'MD'
## 审核顺序

先看事实，再看结构，最后调整语气和格式。不要一开始就改句子，否则容易忽略方向性问题。

## 关键检查项

- 标题是否承诺了正文真正回答的问题。
- 摘要是否能独立说明文章价值。
- 正文是否有清晰层级。
- 是否存在未经验证的数据或过度夸张表述。
- Meta description 是否自然且不堆砌关键词。

## 发布前确认

确认分类、作者、发布时间和相关文章都已经设置，避免前台页面出现空状态。
MD,
            ],
            [
                'category_slug' => 'geo-growth',
                'slug' => 'make-articles-easier-for-ai-search-to-cite',
                'title' => '让文章更容易被 AI 搜索引用',
                'excerpt' => '通过明确答案、结构化小标题和可验证来源，提升文章被 AI 搜索理解和引用的概率。',
                'keyword' => 'AI 搜索引用',
                'tags' => ['GEO', 'AI 搜索', '内容结构'],
                'content' => <<<'MD'
## 先回答核心问题

AI 搜索更容易引用清晰、直接、可验证的答案。文章开头应该先给出结论，再展开条件和例外。

## 推荐结构

1. 用一句话回答问题。
2. 用小标题拆分场景。
3. 用表格承载比较信息。
4. 在结尾给出下一步行动。

## 避免的问题

不要把长段营销话术放在正文开头，也不要只罗列关键词。页面应该像帮助文档一样可扫描。
MD,
            ],
            [
                'category_slug' => 'geo-growth',
                'slug' => 'design-geo-diagnostic-report-structure',
                'title' => '设计 GEO 诊断报告的内容结构',
                'excerpt' => '把品牌可见性、竞品引用、内容缺口和行动建议组织成一份可交付报告。',
                'keyword' => 'GEO 诊断报告',
                'tags' => ['GEO 诊断', '报告结构', '交付'],
                'content' => <<<'MD'
## 报告应该回答什么

一份 GEO 诊断报告不只是截图合集，它应该回答品牌当前在哪里、为什么出现差距、下一步应该做什么。

## 建议章节

- 总览：一句话说明机会和风险。
- 品牌可见性：展示不同问题下的出现情况。
- 竞品对比：说明谁被引用以及为什么。
- 内容缺口：列出需要补齐的主题。
- 行动计划：按优先级安排执行。

## 交付标准

每个建议都应该能落到具体页面、具体内容类型或具体数据源，不要停留在抽象建议。
MD,
            ],
        ];
    }
}
