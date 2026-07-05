<?php

namespace App\Support\Site;

final class HomepageModuleBuilder
{
    public const MAX_MODULES = 30;

    public const TYPES = [
        'hero',
        'rich_text',
        'image_band',
        'metric_band',
        'chart_band',
        'feature_grid',
        'article_collection',
        'cta_band',
        'lead_form',
        'custom_html',
    ];

    public const LAYOUTS = [
        'single',
        'split',
        'grid',
        'compact',
    ];

    public const ARTICLE_SOURCES = [
        'featured',
        'hot',
        'latest',
    ];

    public const CONTAINER_WIDTHS = [
        'narrow',
        'default',
        'wide',
    ];

    public const SPACINGS = [
        'compact',
        'normal',
        'relaxed',
    ];

    public const RADII = [
        'none',
        'soft',
        'round',
    ];

    public const ALIGNMENTS = [
        'left',
        'center',
    ];

    public const PRESETS = [
        'enterprise_brand',
        'content_portal',
        'service_solution',
        'report_hub',
        'product_launch',
    ];

    public const PRESET_MODES = [
        'replace',
        'append',
    ];

    /**
     * @return array{
     *   accent_color:string,
     *   background_color:string,
     *   surface_color:string,
     *   text_color:string,
     *   muted_color:string,
     *   container_width:string,
     *   section_spacing:string,
     *   radius:string
     * }
     */
    public static function defaultStyle(): array
    {
        return [
            'accent_color' => '#2563eb',
            'background_color' => '#ffffff',
            'surface_color' => '#ffffff',
            'text_color' => '#111827',
            'muted_color' => '#6b7280',
            'container_width' => 'default',
            'section_spacing' => 'normal',
            'radius' => 'soft',
        ];
    }

    /**
     * @return list<string>
     */
    public static function presetIds(): array
    {
        return self::PRESETS;
    }

    /**
     * @return list<string>
     */
    public static function presetModes(): array
    {
        return self::PRESET_MODES;
    }

    /**
     * @return array{style:array<string,string>,modules:list<array<string,mixed>>}
     */
    public static function buildPreset(string $preset): array
    {
        return match ($preset) {
            'content_portal' => self::contentPortalPreset(),
            'service_solution' => self::serviceSolutionPreset(),
            'report_hub' => self::reportHubPreset(),
            'product_launch' => self::productLaunchPreset(),
            default => self::enterpriseBrandPreset(),
        };
    }

    /**
     * @return array{style:array<string,string>,modules:list<array<string,mixed>>}
     */
    private static function enterpriseBrandPreset(): array
    {
        return self::normalizePreset([
            'style' => self::whiteStyle([
                'accent_color' => '#2563eb',
                'container_width' => 'wide',
                'section_spacing' => 'relaxed',
                'radius' => 'soft',
            ]),
            'modules' => [
                self::presetModule('enterprise_brand', 'hero', 10, [
                    'layout' => 'split',
                    'title' => '把首页升级成业务增长入口',
                    'subtitle' => '企业品牌首页',
                    'body' => '集中展示业务价值、解决方案、客户证据和最新内容，让首页同时承担认知、信任和转化。',
                    'link_text' => '查看解决方案',
                    'link_url' => '/category/geo-growth',
                ]),
                self::presetModule('enterprise_brand', 'feature_grid', 20, [
                    'layout' => 'grid',
                    'title' => '核心能力',
                    'subtitle' => '用结构化模块讲清楚业务价值',
                    'body' => "GEO 诊断|围绕品牌可见度、引用概率和内容结构定位问题。|/category/geo-growth\n知识库沉淀|把业务资料、FAQ 和案例转化为可复用素材。|/category/ai-content-workflow\n多站分发|把文章按渠道策略同步到目标站点。|/category/tech-news",
                ]),
                self::presetModule('enterprise_brand', 'metric_band', 30, [
                    'layout' => 'grid',
                    'title' => '运营概览',
                    'body' => "内容资产|100+\n渠道站点|12\n知识片段|500+\n更新节奏|每日",
                ]),
                self::presetModule('enterprise_brand', 'article_collection', 40, [
                    'layout' => 'grid',
                    'data_source' => 'featured',
                    'title' => '精选内容',
                    'subtitle' => '把最值得阅读的内容前置展示',
                    'limit' => 6,
                ]),
                self::presetModule('enterprise_brand', 'cta_band', 50, [
                    'layout' => 'single',
                    'title' => '从一次诊断开始优化 GEO 内容体系',
                    'body' => '先确认品牌在 AI 搜索里的可见度，再决定内容、知识库和分发策略。',
                    'link_text' => '查看最新文章',
                    'link_url' => '/articles',
                ]),
            ],
        ]);
    }

    /**
     * @return array{style:array<string,string>,modules:list<array<string,mixed>>}
     */
    private static function contentPortalPreset(): array
    {
        return self::normalizePreset([
            'style' => self::whiteStyle([
                'accent_color' => '#0f766e',
                'container_width' => 'wide',
                'section_spacing' => 'normal',
                'radius' => 'soft',
            ]),
            'modules' => [
                self::presetModule('content_portal', 'hero', 10, [
                    'layout' => 'single',
                    'title' => '让内容成为持续增长资产',
                    'subtitle' => '内容门户首页',
                    'body' => '把专题、精选、最新和热门内容组织成一个可扫描、可复用、可持续更新的内容入口。',
                    'link_text' => '浏览内容',
                    'link_url' => '/articles',
                ]),
                self::presetModule('content_portal', 'article_collection', 20, [
                    'layout' => 'grid',
                    'data_source' => 'featured',
                    'title' => '编辑精选',
                    'subtitle' => '优先展示最能代表站点价值的内容',
                    'limit' => 6,
                ]),
                self::presetModule('content_portal', 'article_collection', 30, [
                    'layout' => 'compact',
                    'data_source' => 'hot',
                    'title' => '热门阅读',
                    'subtitle' => '用高关注内容承接用户兴趣',
                    'limit' => 8,
                ]),
                self::presetModule('content_portal', 'rich_text', 40, [
                    'layout' => 'single',
                    'title' => '内容组织原则',
                    'body' => '首页不只是文章列表，它需要表达主题、建立信任、引导阅读路径，并为后续搜索和 AI 引用沉淀稳定结构。',
                ]),
                self::presetModule('content_portal', 'article_collection', 50, [
                    'layout' => 'grid',
                    'data_source' => 'latest',
                    'title' => '最新发布',
                    'limit' => 6,
                ]),
            ],
        ]);
    }

    /**
     * @return array{style:array<string,string>,modules:list<array<string,mixed>>}
     */
    private static function serviceSolutionPreset(): array
    {
        return self::normalizePreset([
            'style' => self::whiteStyle([
                'accent_color' => '#7c3aed',
                'container_width' => 'default',
                'section_spacing' => 'relaxed',
                'radius' => 'soft',
            ]),
            'modules' => [
                self::presetModule('service_solution', 'hero', 10, [
                    'layout' => 'split',
                    'title' => '用结构化方案承接真实需求',
                    'subtitle' => '服务与解决方案首页',
                    'body' => '围绕客户问题、服务步骤、交付证据和内容案例，让首页变成清晰的方案入口。',
                    'link_text' => '了解服务流程',
                    'link_url' => '/category/ai-content-workflow',
                ]),
                self::presetModule('service_solution', 'feature_grid', 20, [
                    'layout' => 'grid',
                    'title' => '服务路径',
                    'body' => "诊断问题|判断品牌在 AI 搜索和内容结构中的短板。|/category/geo-growth\n建设知识库|沉淀事实、案例和 FAQ，形成可复用证据。|/category/ai-content-workflow\n持续分发|按渠道策略发布内容并观察效果。|/category/tech-news",
                ]),
                self::presetModule('service_solution', 'metric_band', 30, [
                    'layout' => 'grid',
                    'title' => '交付关注点',
                    'body' => "可见度|诊断\n内容质量|优化\n知识体系|沉淀\n多端分发|同步",
                ]),
                self::presetModule('service_solution', 'article_collection', 40, [
                    'layout' => 'grid',
                    'data_source' => 'latest',
                    'title' => '方案文章',
                    'limit' => 6,
                ]),
                self::presetModule('service_solution', 'cta_band', 50, [
                    'title' => '把分散资料整理成可执行的 GEO 方案',
                    'body' => '从知识库、提示词、任务到分发，形成一条可复盘的内容生产链路。',
                    'link_text' => '查看精选内容',
                    'link_url' => '/articles',
                ]),
            ],
        ]);
    }

    /**
     * @return array{style:array<string,string>,modules:list<array<string,mixed>>}
     */
    private static function reportHubPreset(): array
    {
        return self::normalizePreset([
            'style' => self::whiteStyle([
                'accent_color' => '#b45309',
                'container_width' => 'default',
                'section_spacing' => 'normal',
                'radius' => 'none',
            ]),
            'modules' => [
                self::presetModule('report_hub', 'hero', 10, [
                    'layout' => 'single',
                    'title' => '把诊断、报告和案例组织成入口',
                    'subtitle' => '报告中心首页',
                    'body' => '适合展示诊断报告、行业观察、案例拆解和方法论内容，强调证据、结构和可复查。',
                ]),
                self::presetModule('report_hub', 'rich_text', 20, [
                    'title' => '报告型首页的重点',
                    'body' => '报告中心需要让用户快速理解问题、依据、结论和行动建议。模块顺序建议从核心发现开始，再进入案例和方法论。',
                ]),
                self::presetModule('report_hub', 'metric_band', 30, [
                    'layout' => 'grid',
                    'title' => '诊断维度',
                    'body' => "可见度|监测\n引用质量|评估\n内容覆盖|盘点\n改进建议|输出",
                ]),
                self::presetModule('report_hub', 'article_collection', 40, [
                    'layout' => 'grid',
                    'data_source' => 'featured',
                    'title' => '重点报告',
                    'limit' => 6,
                ]),
                self::presetModule('report_hub', 'custom_html', 50, [
                    'title' => '报告说明',
                    'custom_html' => '<section><h3>如何阅读这些报告</h3><p>先看结论，再看证据来源和适用条件。涉及业务决策时，建议结合最新资料复核。</p></section>',
                ]),
            ],
        ]);
    }

    /**
     * @return array{style:array<string,string>,modules:list<array<string,mixed>>}
     */
    private static function productLaunchPreset(): array
    {
        return self::normalizePreset([
            'style' => self::whiteStyle([
                'accent_color' => '#db2777',
                'container_width' => 'wide',
                'section_spacing' => 'relaxed',
                'radius' => 'round',
            ]),
            'modules' => [
                self::presetModule('product_launch', 'hero', 10, [
                    'layout' => 'split',
                    'title' => '为新产品搭建发布首页',
                    'subtitle' => '产品发布首页',
                    'body' => '通过主视觉、能力介绍、关键指标和发布文章，让用户在一个页面里理解产品定位和使用路径。',
                    'link_text' => '查看发布内容',
                    'link_url' => '/articles',
                ]),
                self::presetModule('product_launch', 'image_band', 20, [
                    'layout' => 'split',
                    'title' => '用一个明确场景承接产品价值',
                    'body' => '把产品适合谁、解决什么问题、为什么现在需要讲清楚，再把用户导向更详细的文章或案例。',
                    'link_text' => '查看案例',
                    'link_url' => '/category/tech-news',
                ]),
                self::presetModule('product_launch', 'feature_grid', 30, [
                    'layout' => 'grid',
                    'title' => '产品亮点',
                    'body' => "更快上线|用模块化首页减少重复设计成本。|/articles\n更易维护|后台配置模块，前台模板自动渲染。|/articles\n更适合增长|内容、转化和 SEO 信息可以统一组织。|/articles",
                ]),
                self::presetModule('product_launch', 'metric_band', 40, [
                    'layout' => 'grid',
                    'title' => '发布节奏',
                    'body' => "预热|内容准备\n上线|模板发布\n分发|多站同步\n复盘|数据分析",
                ]),
                self::presetModule('product_launch', 'article_collection', 50, [
                    'layout' => 'grid',
                    'data_source' => 'latest',
                    'title' => '发布动态',
                    'limit' => 6,
                ]),
            ],
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function fromRaw(string $raw, bool $enabledOnly = true, int $max = self::MAX_MODULES): array
    {
        $decoded = json_decode($raw, true);

        return self::normalizeModules($decoded, $enabledOnly, $max);
    }

    /**
     * @return array<string,string>
     */
    public static function styleFromRaw(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return self::normalizeStyle($decoded);
    }

    /**
     * Normalize JSON payloads exported by design agents or manual tools into
     * GEOFlow homepage style tokens and module records.
     *
     * @return array{style:array<string,string>,modules:list<array<string,mixed>>}
     */
    public static function normalizeDesignPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [
                'style' => self::defaultStyle(),
                'modules' => [],
            ];
        }

        if (array_is_list($payload)) {
            return [
                'style' => self::defaultStyle(),
                'modules' => self::normalizeModules(array_map(
                    static fn (mixed $module): mixed => is_array($module) ? self::mapModuleAliases($module) : $module,
                    $payload
                ), false, self::MAX_MODULES),
            ];
        }

        $rawStyle = $payload['style']
            ?? $payload['homepage_style']
            ?? $payload['style_tokens']
            ?? $payload['tokens']
            ?? [];

        $rawModules = $payload['modules']
            ?? $payload['homepage_modules']
            ?? $payload['sections']
            ?? $payload['blocks']
            ?? [];

        if (! is_array($rawModules)) {
            $rawModules = [];
        }

        return [
            'style' => self::normalizeStyle(self::mapStyleAliases(is_array($rawStyle) ? $rawStyle : [])),
            'modules' => self::normalizeModules(array_map(
                static fn (mixed $module): mixed => is_array($module) ? self::mapModuleAliases($module) : $module,
                $rawModules
            ), false, self::MAX_MODULES),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function normalizeModules(mixed $value, bool $enabledOnly = false, int $max = self::MAX_MODULES): array
    {
        if (! is_array($value)) {
            return [];
        }

        $modules = [];
        foreach (array_values($value) as $item) {
            if (! is_array($item) || count($modules) >= $max) {
                continue;
            }

            $title = self::limitText(trim((string) ($item['title'] ?? '')), 120);
            $subtitle = self::limitText(trim((string) ($item['subtitle'] ?? '')), 180);
            $body = self::limitText(trim((string) ($item['body'] ?? '')), 1200);
            $customHtml = self::sanitizeCustomHtml((string) ($item['custom_html'] ?? ''));
            $imageUrl = self::normalizeUrl((string) ($item['image_url'] ?? ''), allowRelative: true);
            $linkText = self::limitText(trim((string) ($item['link_text'] ?? '')), 80);
            $linkUrl = self::normalizeUrl((string) ($item['link_url'] ?? ''), allowRelative: true);
            $leadFormSlug = self::normalizeSlug((string) ($item['lead_form_slug'] ?? ''));
            $accentColor = self::normalizeHexColor((string) ($item['accent_color'] ?? ''));
            $surfaceColor = self::normalizeHexColor((string) ($item['surface_color'] ?? ''));
            $textColor = self::normalizeHexColor((string) ($item['text_color'] ?? ''));
            $mutedColor = self::normalizeHexColor((string) ($item['muted_color'] ?? ''));

            if ($title === '' && $subtitle === '' && $body === '' && $customHtml === '' && $imageUrl === '' && $linkText === '' && $linkUrl === '' && $leadFormSlug === '') {
                continue;
            }

            $enabled = ! empty($item['enabled']);
            if ($enabledOnly && ! $enabled) {
                continue;
            }

            $type = (string) ($item['type'] ?? 'rich_text');
            if (! in_array($type, self::TYPES, true)) {
                $type = 'rich_text';
            }

            $layout = (string) ($item['layout'] ?? 'single');
            if (! in_array($layout, self::LAYOUTS, true)) {
                $layout = 'single';
            }

            $dataSource = (string) ($item['data_source'] ?? 'latest');
            if (! in_array($dataSource, self::ARTICLE_SOURCES, true)) {
                $dataSource = 'latest';
            }

            $alignment = (string) ($item['alignment'] ?? 'left');
            if (! in_array($alignment, self::ALIGNMENTS, true)) {
                $alignment = 'left';
            }

            $limit = filter_var($item['limit'] ?? null, FILTER_VALIDATE_INT);
            if ($limit === false) {
                $limit = 4;
            }

            $sortOrder = filter_var($item['sort_order'] ?? null, FILTER_VALIDATE_INT);
            if ($sortOrder === false) {
                $sortOrder = (count($modules) + 1) * 10;
            }

            $modules[] = [
                'id' => trim((string) ($item['id'] ?? '')) ?: uniqid('home_module_', true),
                'type' => $type,
                'layout' => $layout,
                'data_source' => $dataSource,
                'enabled' => $enabled,
                'sort_order' => max(0, min(10000, (int) $sortOrder)),
                'title' => $title,
                'subtitle' => $subtitle,
                'body' => $body,
                'image_url' => $imageUrl,
                'link_text' => $linkText,
                'link_url' => $linkUrl,
                'lead_form_slug' => $leadFormSlug,
                'limit' => max(1, min(12, (int) $limit)),
                'custom_html' => $customHtml,
                'accent_color' => $accentColor,
                'surface_color' => $surfaceColor,
                'text_color' => $textColor,
                'muted_color' => $mutedColor,
                'alignment' => $alignment,
            ];
        }

        usort($modules, static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)));

        return $modules;
    }

    /**
     * @return array<string,string>
     */
    public static function normalizeStyle(mixed $value): array
    {
        $style = self::defaultStyle();
        if (! is_array($value)) {
            return $style;
        }

        foreach (['accent_color', 'background_color', 'surface_color', 'text_color', 'muted_color'] as $field) {
            $raw = trim((string) ($value[$field] ?? ''));
            if ($raw !== '') {
                $normalized = self::normalizeHexColor($raw);
                if ($normalized !== '') {
                    $style[$field] = $normalized;
                }
            }
        }

        $containerWidth = (string) ($value['container_width'] ?? '');
        if (in_array($containerWidth, self::CONTAINER_WIDTHS, true)) {
            $style['container_width'] = $containerWidth;
        }

        $spacing = (string) ($value['section_spacing'] ?? '');
        if (in_array($spacing, self::SPACINGS, true)) {
            $style['section_spacing'] = $spacing;
        }

        $radius = (string) ($value['radius'] ?? '');
        if (in_array($radius, self::RADII, true)) {
            $style['radius'] = $radius;
        }

        return $style;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private static function mapModuleAliases(array $item): array
    {
        $mapped = $item;
        $aliases = [
            'type' => ['kind', 'module_type', 'section_type', 'block_type'],
            'layout' => ['variant', 'display', 'template'],
            'data_source' => ['source', 'article_source', 'feed'],
            'title' => ['headline', 'heading', 'name'],
            'subtitle' => ['eyebrow', 'label', 'kicker', 'tagline'],
            'body' => ['copy', 'description', 'content', 'text'],
            'image_url' => ['image', 'imageUrl', 'media_url', 'media', 'cover', 'cover_url'],
            'link_text' => ['cta_label', 'button_text', 'linkLabel', 'action_text'],
            'link_url' => ['cta_url', 'button_url', 'url', 'href', 'action_url'],
            'lead_form_slug' => ['form_slug', 'lead_form', 'form', 'conversion_form'],
            'limit' => ['count', 'article_limit', 'items'],
            'sort_order' => ['order', 'position', 'sort'],
            'custom_html' => ['html', 'markup'],
            'accent_color' => ['accent', 'primary', 'primary_color', 'brand_color'],
            'surface_color' => ['surface', 'card_background', 'module_background'],
            'text_color' => ['text_color', 'foreground', 'font_color'],
            'muted_color' => ['muted', 'secondary_text', 'subtle_text'],
            'alignment' => ['align', 'text_align'],
            'enabled' => ['is_enabled', 'active'],
        ];

        foreach ($aliases as $target => $sources) {
            if (array_key_exists($target, $mapped) && $mapped[$target] !== '' && $mapped[$target] !== null) {
                continue;
            }

            foreach ($sources as $source) {
                if (array_key_exists($source, $mapped) && $mapped[$source] !== '' && $mapped[$source] !== null) {
                    $mapped[$target] = $mapped[$source];
                    break;
                }
            }
        }

        $mapped['type'] = self::mapModuleTypeAlias((string) ($mapped['type'] ?? 'rich_text'));

        if (array_key_exists('enabled', $mapped)) {
            $mapped['enabled'] = filter_var($mapped['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ! empty($mapped['enabled']);
        } else {
            $mapped['enabled'] = true;
        }

        foreach (['title', 'subtitle', 'body', 'image_url', 'link_text', 'link_url', 'lead_form_slug', 'custom_html', 'accent_color', 'surface_color', 'text_color', 'muted_color', 'alignment'] as $textField) {
            if (array_key_exists($textField, $mapped) && is_array($mapped[$textField])) {
                $mapped[$textField] = self::stringifyDesignValue($mapped[$textField]);
            }
        }

        return $mapped;
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function stringifyDesignValue(array $value): string
    {
        $lines = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $lines[] = trim((string) $item);

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? $item['heading'] ?? $item['name'] ?? $item['label'] ?? ''));
            $body = trim((string) ($item['body'] ?? $item['description'] ?? $item['copy'] ?? $item['text'] ?? $item['value'] ?? $item['number'] ?? $item['count'] ?? ''));
            $url = trim((string) ($item['url'] ?? $item['href'] ?? $item['link_url'] ?? $item['cta_url'] ?? $item['note'] ?? $item['caption'] ?? $item['meta'] ?? ''));

            $line = implode('|', array_values(array_filter([$title, $body, $url], static fn (string $part): bool => $part !== '')));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", array_values(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    private static function mapModuleTypeAlias(string $type): string
    {
        $normalized = strtolower(trim(str_replace(['-', ' '], '_', $type)));

        return match ($normalized) {
            'hero_section', 'banner', 'jumbotron' => 'hero',
            'text', 'richtext', 'markdown', 'copy_block', 'content_block' => 'rich_text',
            'image', 'media_band', 'image_block', 'visual_band' => 'image_band',
            'metric', 'metrics', 'stats', 'stat_band', 'numbers' => 'metric_band',
            'chart', 'charts', 'chart_band', 'bar_chart', 'data_viz', 'dataviz', 'visualization' => 'chart_band',
            'feature', 'features', 'cards', 'feature_cards' => 'feature_grid',
            'article', 'articles', 'post_list', 'feed', 'collection', 'article_list' => 'article_collection',
            'cta', 'call_to_action', 'conversion' => 'cta_band',
            'form', 'lead', 'lead_form', 'conversion_form', 'contact_form' => 'lead_form',
            'html', 'custom', 'raw_html' => 'custom_html',
            default => $normalized,
        };
    }

    /**
     * @param  array<string,mixed>  $style
     * @return array<string,mixed>
     */
    private static function mapStyleAliases(array $style): array
    {
        $mapped = $style;
        $aliases = [
            'accent_color' => ['accent', 'primary', 'primary_color', 'brand_color'],
            'background_color' => ['background', 'page_background', 'bg_color'],
            'surface_color' => ['surface', 'card_background', 'module_background'],
            'text_color' => ['text', 'foreground', 'font_color'],
            'muted_color' => ['muted', 'secondary_text', 'subtle_text'],
            'container_width' => ['width', 'container', 'max_width'],
            'section_spacing' => ['spacing', 'section_gap', 'gap'],
            'radius' => ['border_radius', 'corner_radius', 'rounding'],
        ];

        foreach ($aliases as $target => $sources) {
            if (array_key_exists($target, $mapped) && $mapped[$target] !== '' && $mapped[$target] !== null) {
                continue;
            }

            foreach ($sources as $source) {
                if (array_key_exists($source, $mapped) && $mapped[$source] !== '' && $mapped[$source] !== null) {
                    $mapped[$target] = $mapped[$source];
                    break;
                }
            }
        }

        return $mapped;
    }

    public static function normalizeUrl(string $url, bool $allowRelative = true): string
    {
        $normalized = trim($url);
        if ($normalized === '' || str_starts_with($normalized, '//')) {
            return '';
        }

        if ($allowRelative && str_starts_with($normalized, '/')) {
            return $normalized;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $normalized) === 1) {
            return '';
        }

        return $allowRelative ? '/'.ltrim($normalized, '/') : '';
    }

    public static function normalizeSlug(string $slug): string
    {
        $normalized = strtolower(trim($slug));
        $normalized = preg_replace('/[^a-z0-9_-]+/', '-', $normalized) ?? '';

        return trim($normalized, '-_');
    }

    public static function normalizeHexColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) !== 1) {
            return '';
        }

        $hex = ltrim(strtolower($color), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    /**
     * @param  array{style:array<string,mixed>,modules:list<array<string,mixed>>}  $preset
     * @return array{style:array<string,string>,modules:list<array<string,mixed>>}
     */
    private static function normalizePreset(array $preset): array
    {
        return [
            'style' => self::normalizeStyle($preset['style'] ?? []),
            'modules' => self::normalizeModules($preset['modules'] ?? [], false, self::MAX_MODULES),
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function whiteStyle(array $overrides = []): array
    {
        return array_merge(self::defaultStyle(), [
            'background_color' => '#ffffff',
            'surface_color' => '#ffffff',
            'text_color' => '#111827',
            'muted_color' => '#6b7280',
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private static function presetModule(string $preset, string $type, int $sortOrder, array $payload): array
    {
        $prefix = preg_replace('/[^a-z0-9_]+/', '_', strtolower($preset)) ?: 'preset';

        return array_merge([
            'id' => 'preset_'.$prefix.'_'.str_replace('.', '_', uniqid('', true)),
            'type' => $type,
            'layout' => 'single',
            'data_source' => 'latest',
            'enabled' => true,
            'sort_order' => $sortOrder,
            'title' => '',
            'subtitle' => '',
            'body' => '',
            'image_url' => '',
            'link_text' => '',
            'link_url' => '',
            'limit' => 4,
            'custom_html' => '',
            'accent_color' => '',
            'surface_color' => '',
            'text_color' => '',
            'muted_color' => '',
            'alignment' => 'left',
        ], $payload);
    }

    public static function sanitizeCustomHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><blockquote><a><span><div><section><h2><h3><h4>');
        $html = preg_replace_callback('/<([a-z0-9]+)(\s[^>]*)?>/i', static function (array $matches): string {
            $tag = strtolower((string) ($matches[1] ?? ''));
            if ($tag !== 'a') {
                return '<'.$tag.'>';
            }

            return self::sanitizeCustomHtmlAnchor((string) ($matches[2] ?? ''));
        }, $html) ?? '';

        return self::limitText($html, 5000);
    }

    private static function sanitizeCustomHtmlAnchor(string $attributes): string
    {
        $safeAttributes = [];

        if (preg_match('/\shref\s*=\s*([\'"])(.*?)\1/i', $attributes, $match) === 1) {
            $url = self::normalizeUrl(html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url !== '') {
                $safeAttributes[] = 'href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            }
        }

        $target = '';
        if (preg_match('/\starget\s*=\s*([\'"])(.*?)\1/i', $attributes, $match) === 1) {
            $candidate = strtolower(trim((string) $match[2]));
            if (in_array($candidate, ['_blank', '_self'], true)) {
                $target = $candidate;
                $safeAttributes[] = 'target="'.$candidate.'"';
            }
        }

        if ($target === '_blank') {
            $safeAttributes[] = 'rel="noopener nofollow"';
        }

        if (preg_match('/\stitle\s*=\s*([\'"])(.*?)\1/i', $attributes, $match) === 1) {
            $title = self::limitText(trim((string) $match[2]), 120);
            if ($title !== '') {
                $safeAttributes[] = 'title="'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            }
        }

        return '<a'.($safeAttributes === [] ? '' : ' '.implode(' ', $safeAttributes)).'>';
    }

    private static function limitText(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit);
    }
}
