<?php

namespace App\Services\AiWorkspace;

use App\Models\Admin;
use App\Support\AdminWeb;
use Illuminate\Support\Str;

final class AdminHelpKnowledgeCatalog
{
    /**
     * @return list<array{id:string,name:string,description:string,keywords:list<string>,steps:list<string>,route:string,icon:string,protected:bool,followups:list<string>,featured:bool}>
     */
    public function entries(): array
    {
        return [
            $this->entry('ai-workspace', 'AI 工作台', '通过对话查询 GEOFlow 后台功能、操作步骤、系统原理、故障排查和可信站内入口。', ['AI工作台', 'AI 工作台', '后台助手', '帮助助手', '对话问答'], ['打开 AI 工作台。', '输入需要了解的功能或遇到的问题。', '根据回答中的参考章节、相关截图和站内入口继续操作。'], 'admin.ai-workspace', 'sparkles', false, ['AI 工作台可以回答哪些问题？', '怎样获得更准确的后台操作指南？']),
            $this->entry('account', '账号设置', '查看当前后台账号资料、安全信息和浏览器运营助手连接状态。', ['账号', '我的账号', '个人资料', '账户设置', '浏览器客户端'], ['打开账号设置。', '检查当前账号资料和安全状态。', '按需管理浏览器运营助手连接。'], 'admin.account.show', 'circle-user-round', false, ['在哪里查看我的后台账号？', '如何管理浏览器运营助手连接？']),
            $this->entry('data-center', '数据中心', '查看访问、内容、分发和线索等核心经营数据，并按时间范围定位变化。', ['数据中心', '数据', '统计', '报表', '访问量', '趋势', '分析'], ['打开数据中心。', '选择需要查看的数据栏目。', '调整时间范围并查看趋势与明细。'], 'admin.analytics', 'chart-no-axes-combined', false, ['如何查看最近 30 天的数据趋势？', '数据中心各项指标分别代表什么？'], true),
            $this->entry('ai-visibility', 'AI 可见性', '查看品牌在生成式引擎中的可见性观测、引用线索和诊断结果。', ['AI可见性', 'AI 可见性', '品牌可见性', '生成式引擎', '引用', '诊断'], ['进入数据中心。', '切换到 AI 可见性栏目。', '按时间查看观测结果和变化。'], 'admin.analytics.ai-visibility', 'radar', false, ['如何查看品牌的 AI 可见性？', 'AI 可见性数据应该怎样解读？'], true),
            $this->entry('tasks', '任务管理', '创建和管理内容任务，查看运行状态、健康检查和执行结果。', ['任务', '采集任务', '运行任务', '任务状态', '健康检查', '定时'], ['打开任务管理。', '查看现有任务或创建新任务。', '在列表中检查状态并进入编辑页调整配置。'], 'admin.tasks.index', 'workflow', false, ['如何创建一个内容任务？', '任务没有运行时应该检查什么？'], true),
            $this->entry('articles', '文章管理', '创建、编辑、审核和管理文章，支持批量状态处理与风险扫描。', ['文章', '内容创作', '编辑器', '审核', '草稿', '标题', '风险扫描'], ['打开文章管理。', '新建文章或选择已有文章编辑。', '完成内容后检查审核与发布状态。'], 'admin.articles.index', 'file-text', false, ['如何创建并审核一篇文章？', '文章被标记风险时怎么处理？'], true),
            $this->entry('manual-publications', '手动发布', '管理需要人工发布到外部平台的内容、账号设置、执行状态和导出记录。', ['手动发布', '人工发布', '发布记录', '外部平台', '小红书', '知乎', '公众号'], ['打开手动发布。', '查看待处理记录或新建发布任务。', '按状态完成发布并回填结果。'], 'admin.manual-publications.index', 'send', false, ['如何创建一条手动发布任务？', '在哪里配置手动发布账号？']),
            $this->entry('materials', '内容资产', '统一进入分类、作者、关键词、标题、图片和知识库等内容资产。', ['素材', '内容资产', '分类', '作者', '关键词库', '标题库', '图片库'], ['打开内容资产。', '选择要维护的资产类型。', '进入列表新增、导入或编辑资产。'], 'admin.materials.index', 'database', false, ['内容资产包含哪些类型？', '如何导入关键词或标题资产？'], true),
            $this->entry('knowledge-bases', '知识库', '上传和维护用于内容生成与检索的知识资料，并管理切片。', ['知识库', '资料库', '文件上传', '切片', '知识检索', 'RAG'], ['打开知识库列表。', '创建知识库或上传资料。', '进入详情检查资料与切片状态。'], 'admin.knowledge-bases.index', 'library-big', false, ['如何上传资料到知识库？', '知识库切片需要什么时候刷新？']),
            $this->entry('distribution', '内容分发', '管理分发渠道、托管站点和分发任务，并查看同步与健康状态。', ['分发', '渠道', '发布渠道', '托管站点', '同步设置', '分发任务'], ['打开内容分发。', '选择渠道或托管站点。', '查看分发任务状态并处理失败记录。'], 'admin.distribution.index', 'radio-tower', true, ['如何配置一个分发渠道？', '分发任务失败时如何排查？']),
            $this->entry('ai-config', 'AI 配置', '配置对话、Embedding 等 AI 模型和提示词，并测试模型连接。', ['AI配置', 'AI 配置', '模型', '大模型', 'API Key', '提示词', 'Embedding'], ['打开 AI 配置。', '进入模型或提示词设置。', '保存配置后执行连接测试。'], 'admin.ai.configurator', 'network', false, ['如何新增并测试一个 AI 模型？', '在哪里设置系统提示词？']),
            $this->entry('site-settings', '网站设置', '维护站点名称、品牌信息、基础展示和文章详情相关配置。', ['网站设置', '站点设置', '品牌', '网站名称', 'Logo', '广告'], ['打开网站设置。', '修改对应的站点配置。', '保存后到前台检查展示结果。'], 'admin.site-settings.index', 'settings', false, ['如何修改网站名称和品牌信息？', '网站设置保存后没有生效怎么办？']),
            $this->entry('homepage-theme', '首页与主题', '配置首页模块、主题样式和页面复刻相关能力。', ['首页', '主题', '首页模块', '样式', '页面复刻', '模板'], ['进入网站设置。', '打开首页与主题。', '调整模块或主题并预览效果。'], 'admin.site-settings.homepage-modules.edit', 'palette', false, ['如何调整首页模块顺序？', '如何修改网站主题样式？']),
            $this->entry('lead-forms', '表单设置', '创建和管理前台线索表单，控制字段与启用状态。', ['表单', '线索表单', '咨询表单', '字段', '表单设置'], ['打开表单设置。', '创建或编辑表单。', '检查字段并确认表单处于启用状态。'], 'admin.lead-forms.index', 'notebook-tabs', false, ['如何创建一个线索表单？', '为什么前台没有显示表单？']),
            $this->entry('leads', '线索管理', '查看、筛选、更新和导出前台表单提交的客户线索。', ['线索', '客户线索', '表单提交', '咨询', '导出线索'], ['打开线索管理。', '按状态或时间筛选记录。', '进入详情更新处理状态，必要时导出。'], 'admin.leads.index', 'contact-round', false, ['如何查看和导出客户线索？', '如何更新线索处理状态？']),
            $this->entry('users-permissions', '用户与权限', '管理后台管理员账号、状态和 API Token，入口仅对超级管理员开放。', ['用户', '管理员', '权限', '账号', 'API Token', '令牌'], ['打开用户与权限。', '创建或选择管理员账号。', '调整状态或管理 API Token。'], 'admin.admin-users.index', 'users-round', true, ['如何新增后台管理员？', '如何创建或撤销 API Token？']),
            $this->entry('security', '安全设置', '维护密码策略、敏感词和后台安全相关配置。', ['安全', '安全设置', '密码', '敏感词', '风控'], ['打开安全设置。', '选择密码或敏感词配置。', '保存后检查对应规则。'], 'admin.security-settings.index', 'shield-check', false, ['如何维护敏感词？', '在哪里修改后台安全设置？']),
            $this->entry('activity-logs', '操作日志', '查看后台管理员操作记录，支持按操作人和时间定位变更，仅对超级管理员开放。', ['操作日志', '审计日志', '管理员日志', '谁修改', '变更记录'], ['打开操作日志。', '按管理员或时间筛选。', '查看对应操作的记录详情。'], 'admin.admin-activity-logs', 'scroll-text', true, ['如何查某个管理员的操作记录？', '在哪里查看最近的后台变更？']),
            $this->entry('system-updates', '系统更新', '检查和执行系统更新、备份与回滚，仅对超级管理员开放。', ['系统更新', '升级', '版本', '备份', '回滚'], ['打开系统更新。', '先检查更新并阅读更新计划。', '确认备份完成后再执行更新。'], 'admin.system-updates.index', 'refresh-cw', true, ['如何安全地执行系统更新？', '系统更新前需要做哪些准备？']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function search(Admin $admin, string $question, int $limit = 5): array
    {
        $normalizedQuestion = Str::of($question)->squish()->lower()->toString();
        $scored = collect($this->entries())
            ->filter(fn (array $entry): bool => $this->isAvailableTo($entry, $admin))
            ->map(function (array $entry, int $position) use ($normalizedQuestion): array {
                return [
                    'entry' => $entry,
                    'position' => $position,
                    'score' => $this->score($entry, $normalizedQuestion),
                ];
            });
        $matches = $scored->where('score', '>', 0);
        if ($matches->isEmpty()) {
            $matches = $scored->filter(static fn (array $item): bool => (bool) $item['entry']['featured']);
        }

        return $matches
            ->sortBy([['score', 'desc'], ['position', 'asc']])
            ->take(max(1, min(5, $limit)))
            ->pluck('entry')
            ->values()
            ->all();
    }

    /** @param list<array<string, mixed>> $entries @return list<array{id:string,title:string,description:string,icon:string,url:string}> */
    public function relatedFeatures(Admin $admin, array $entries, int $limit = 3): array
    {
        return collect($entries)
            ->filter(fn (array $entry): bool => $this->isAvailableTo($entry, $admin))
            ->unique('id')
            ->take(max(1, min(3, $limit)))
            ->map(static fn (array $entry): array => [
                'id' => (string) $entry['id'],
                'title' => (string) $entry['name'],
                'description' => (string) $entry['description'],
                'icon' => (string) $entry['icon'],
                'url' => AdminWeb::routePath((string) $entry['route']),
            ])
            ->values()
            ->all();
    }

    /** @param list<array<string, mixed>> $entries @return list<string> */
    public function suggestions(array $entries, string $currentQuestion = '', int $limit = 3): array
    {
        $normalizedCurrentQuestion = $this->normalizeQuestion($currentQuestion);
        $hasDirectMatch = collect($entries)->contains(
            fn (array $entry): bool => $this->score($entry, Str::lower(Str::squish($currentQuestion))) > 0,
        );
        $questions = collect($entries)->flatMap(static fn (array $entry): array => (array) $entry['followups']);
        $defaults = collect($this->entries())
            ->filter(static fn (array $entry): bool => (bool) $entry['featured'])
            ->flatMap(static fn (array $entry): array => (array) $entry['followups']);

        return ($hasDirectMatch ? $questions->concat($defaults) : $questions)
            ->map(static fn (mixed $question): string => trim((string) $question))
            ->filter(fn (string $question): bool => $question !== '' && $this->normalizeQuestion($question) !== $normalizedCurrentQuestion)
            ->unique()
            ->take($hasDirectMatch ? max(1, min(3, $limit)) : max(1, min(2, $limit)))
            ->values()
            ->all();
    }

    /** @param list<array<string, mixed>> $entries */
    public function context(array $entries): string
    {
        return collect($entries)->map(static function (array $entry): string {
            $separator = app()->getLocale() === 'zh_CN' ? '：' : ': ';
            $steps = collect((array) $entry['steps'])
                ->values()
                ->map(static fn (string $step, int $index): string => ($index + 1).'. '.$step)
                ->implode("\n");

            return "[{$entry['id']}] {$entry['name']}\n"
                .__('admin.ai_workspace.catalog_context_description').$separator."{$entry['description']}\n"
                .__('admin.ai_workspace.catalog_context_actions').$separator."\n{$steps}";
        })->implode("\n\n");
    }

    /** @return list<string> */
    public function starterQuestions(Admin $admin, int $limit = 6): array
    {
        return collect($this->entries())
            ->filter(fn (array $entry): bool => (bool) $entry['featured'] && $this->isAvailableTo($entry, $admin))
            ->map(static fn (array $entry): string => (string) $entry['followups'][0])
            ->take(max(1, min(6, $limit)))
            ->values()
            ->all();
    }

    /** @return list<array{id:string,name:string,icon:string,prompt:string}> */
    public function starterActions(Admin $admin, int $limit = 6): array
    {
        $priority = [
            'ai-visibility',
            'data-center',
            'tasks',
            'articles',
            'materials',
            'distribution',
            'knowledge-bases',
        ];

        return collect($this->entries())
            ->filter(fn (array $entry): bool => $this->isAvailableTo($entry, $admin))
            ->sortBy(static function (array $entry) use ($priority): int {
                $position = array_search($entry['id'], $priority, true);

                return $position === false ? count($priority) : $position;
            })
            ->take(max(1, min(6, $limit)))
            ->map(static fn (array $entry): array => [
                'id' => (string) $entry['id'],
                'name' => (string) $entry['name'],
                'icon' => (string) $entry['icon'],
                'prompt' => (string) $entry['followups'][0],
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $entry */
    private function score(array $entry, string $question): int
    {
        if ($question === '') {
            return 0;
        }

        $name = Str::lower((string) $entry['name']);
        $score = Str::contains($question, $name) ? 80 : 0;
        $keywords = collect((array) $entry['keywords'])
            ->map(static fn (mixed $keyword): string => Str::lower(trim((string) $keyword)))
            ->filter()
            ->unique();
        foreach ($keywords as $normalizedKeyword) {
            if ($this->containsKeyword($question, $normalizedKeyword)) {
                $score += 20 + min(20, Str::length($normalizedKeyword) * 2);
            }
        }
        if (Str::contains(Str::lower((string) $entry['description']), $question) && Str::length($question) >= 2) {
            $score += 12;
        }

        return $score;
    }

    private function containsKeyword(string $question, string $keyword): bool
    {
        if (! in_array($keyword, ['ai', 'ia', 'ии'], true)) {
            return Str::contains($question, $keyword);
        }

        return preg_match(
            '/(?<![\p{L}\p{N}])'.preg_quote($keyword, '/').'(?![\p{L}\p{N}])/u',
            $question,
        ) === 1;
    }

    /** @param array<string, mixed> $entry */
    private function isAvailableTo(array $entry, Admin $admin): bool
    {
        return app('router')->has((string) $entry['route'])
            && (! (bool) $entry['protected'] || $admin->canManageProtectedWorkflows());
    }

    private function normalizeQuestion(string $question): string
    {
        return Str::lower((string) preg_replace('/[\p{P}\p{S}\s]+/u', '', Str::squish($question)));
    }

    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $steps
     * @param  list<string>  $followups
     * @return array{id:string,name:string,description:string,keywords:list<string>,steps:list<string>,route:string,icon:string,protected:bool,followups:list<string>,featured:bool}
     */
    private function entry(
        string $id,
        string $name,
        string $description,
        array $keywords,
        array $steps,
        string $route,
        string $icon,
        bool $protected,
        array $followups,
        bool $featured = false,
    ): array {
        $keywords = array_values(array_unique([
            ...$keywords,
            ...$this->englishKeywords($id),
        ]));

        if (app()->getLocale() !== 'zh_CN') {
            $localizedName = (string) __('admin.ai_workspace.catalog_names.'.$id);
            $name = $localizedName;
            $translatedKeywords = __('admin.ai_workspace.catalog_keywords.'.$id);
            $keywords = array_values(array_unique([
                ...$keywords,
                ...$this->localizedKeywords($localizedName),
                ...(is_array($translatedKeywords) ? $translatedKeywords : []),
            ]));
            $description = (string) __('admin.ai_workspace.catalog_description', ['name' => $localizedName]);
            $steps = [
                (string) __('admin.ai_workspace.catalog_step_open', ['name' => $localizedName]),
                (string) __('admin.ai_workspace.catalog_step_review'),
                (string) __('admin.ai_workspace.catalog_step_continue'),
            ];
            $followups = [
                (string) __('admin.ai_workspace.catalog_followup_use', ['name' => $localizedName]),
                (string) __('admin.ai_workspace.catalog_followup_check', ['name' => $localizedName]),
            ];
        }

        return compact('id', 'name', 'description', 'keywords', 'steps', 'route', 'icon', 'protected', 'followups', 'featured');
    }

    /** @return list<string> */
    private function englishKeywords(string $id): array
    {
        return match ($id) {
            'ai-workspace' => ['AI workspace', 'admin assistant', 'help assistant', 'chat'],
            'account' => ['account', 'profile', 'browser client'],
            'data-center' => ['data', 'analytics', 'report', 'traffic', 'trend'],
            'ai-visibility' => ['AI visibility', 'citation', 'visibility'],
            'tasks' => ['task', 'job', 'schedule'],
            'articles' => ['article', 'content', 'draft', 'review'],
            'manual-publications' => ['manual publish', 'publishing'],
            'materials' => ['asset', 'material', 'category', 'author', 'keyword'],
            'knowledge-bases' => ['knowledge base', 'upload', 'chunk'],
            'distribution' => ['distribution', 'channel', 'hosted site'],
            'ai-config' => ['AI model', 'model', 'prompt'],
            'site-settings' => ['site settings', 'website', 'brand'],
            'homepage-theme' => ['homepage', 'theme', 'template'],
            'lead-forms' => ['form', 'lead form'],
            'leads' => ['lead', 'contact', 'export'],
            'users-permissions' => ['user', 'admin', 'permission', 'token'],
            'security' => ['security', 'password', 'blocked word'],
            'activity-logs' => ['activity log', 'audit log'],
            'system-updates' => ['system update', 'upgrade', 'backup', 'rollback'],
            default => [],
        };
    }

    /** @return list<string> */
    private function localizedKeywords(string $name): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', Str::lower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keywords = [];
        foreach ($tokens as $token) {
            if (Str::length($token) <= 2 && ! in_array($token, ['ai', 'ia', 'ии'], true)) {
                continue;
            }
            $keywords[] = $token;
            if (Str::length($token) >= 6) {
                $keywords[] = Str::substr($token, 0, 5);
            }
        }

        if (in_array(app()->getLocale(), ['ja'], true)) {
            $compact = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', Str::lower($name));
            for ($index = 0; $index <= Str::length($compact) - 2; $index++) {
                $keywords[] = Str::substr($compact, $index, 2);
            }
        }

        return array_values(array_unique($keywords));
    }
}
