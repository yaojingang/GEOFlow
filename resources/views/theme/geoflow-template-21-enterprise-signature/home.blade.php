@extends('theme.geoflow-template-21-enterprise-signature.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaItems = [];
        foreach ((is_object($articles ?? null) && method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles ?? []))->take(10) as $schemaArticle) {
            $schemaItems[] = [
                $schemaAtType => 'ListItem',
                'position' => count($schemaItems) + 1,
                'url' => route('site.article', $schemaArticle->slug),
                'name' => $schemaArticle->title,
            ];
        }
        $collectionSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'CollectionPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.home'),
            'mainEntity' => [
                $schemaAtType => 'ItemList',
                'itemListElement' => $schemaItems,
            ],
        ];
    @endphp
    <x-json-ld :data="$collectionSchema" />
@endpush

@section('content')
    @php
        $homeArticles = is_object($articles ?? null) && method_exists($articles, 'getCollection')
            ? $articles->getCollection()
            : collect($articles ?? []);
        $isDefaultHome = (bool) ($showHomepageModules ?? false);
        $activeLeadForms = collect($leadForms ?? []);
        $homepageModuleCollection = collect($homepageModules ?? []);
        $contactLeadFormModule = $homepageModuleCollection->first(
            fn ($module) => is_array($module)
                && !empty($module['enabled'])
                && ($module['type'] ?? '') === 'lead_form'
                && trim((string) ($module['lead_form_slug'] ?? '')) !== ''
        );
        $contactLeadFormSlug = is_array($contactLeadFormModule)
            ? trim((string) ($contactLeadFormModule['lead_form_slug'] ?? ''))
            : '';
        $primaryLeadForm = $contactLeadFormSlug !== ''
            ? $activeLeadForms->get($contactLeadFormSlug)
            : null;
        $leadFormSelectionInvalid = $contactLeadFormSlug !== '' && !$primaryLeadForm;
        $leadFormNeedsSelection = $activeLeadForms->isNotEmpty() && $contactLeadFormSlug === '';
        $themeHomepageModules = $homepageModuleCollection
            ->reject(fn ($module) => is_array($module) && ($module['type'] ?? '') === 'lead_form')
            ->values()
            ->all();
        $configuredModules = collect($themeHomepageModules)
            ->filter(fn ($module) => is_array($module) && !empty($module['enabled']));
        $featuredResources = collect($featuredArticles ?? [])->take(3);
    @endphp

    @if($isDefaultHome)
        <section class="ent-hero">
            <div class="ent-shell ent-hero__grid">
                <div class="ent-hero__content ent-reveal">
                    <div class="ent-eyebrow">
                        <span>Global GEO Open Source Ecosystem</span>
                        <span class="ent-badge ent-badge--outline">演示官网</span>
                    </div>
                    <h1>让全球知识成为 <span class="ent-no-break">AI 引用的可信答案</span></h1>
                    <p class="ent-hero__lead">
                        GEOFlow 将企业知识、内容工作流、AI 可见性观测和多渠道分发连接在一个开放系统中，
                        帮助全球团队建立持续增长的 GEO 能力。
                    </p>
                    @if($siteDescription !== '')
                        <p class="ent-hero__site-copy">{{ $siteDescription }}</p>
                    @endif
                    <div class="ent-hero__actions">
                        <a href="#contact" class="ent-button ent-button--primary">
                            预约 GEO 方案交流
                            <i data-lucide="arrow-right" aria-hidden="true"></i>
                        </a>
                        <a href="https://github.com/yaojingang/GEOFlow" class="ent-text-link" target="_blank" rel="noopener noreferrer">
                            查看开源仓库
                            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                        </a>
                    </div>
                    <div class="ent-hero__proof">
                        <span class="ent-proof-mark" aria-hidden="true"><i data-lucide="globe-2"></i></span>
                        <p><strong>全球协作界面演示</strong><br>组织与数据将在正式发布前替换</p>
                    </div>
                </div>

                <div class="ent-hero__visual ent-reveal" data-ent-reveal-delay="1">
                    <div class="ent-control-plane">
                        <div class="ent-control-plane__top">
                            <div class="ent-window-dots" aria-hidden="true"><span></span><span></span><span></span></div>
                            <span>GEOFlow Control Plane</span>
                            <span class="ent-live-state"><i></i> Live Demo</span>
                        </div>
                        <div class="ent-control-plane__body">
                            <aside class="ent-control-plane__rail" aria-label="产品模块">
                                <span class="is-active"><i data-lucide="layout-dashboard" aria-hidden="true"></i></span>
                                <span><i data-lucide="database" aria-hidden="true"></i></span>
                                <span><i data-lucide="workflow" aria-hidden="true"></i></span>
                                <span><i data-lucide="radar" aria-hidden="true"></i></span>
                                <span><i data-lucide="send" aria-hidden="true"></i></span>
                            </aside>
                            <div class="ent-control-plane__workspace">
                                <div class="ent-console-heading">
                                    <div>
                                        <small>AI VISIBILITY</small>
                                        <strong>Global answer presence</strong>
                                    </div>
                                    <span>Last 30 days</span>
                                </div>
                                <div class="ent-console-metric">
                                    <strong>74.8</strong>
                                    <span><i data-lucide="trending-up" aria-hidden="true"></i> +12.6%</span>
                                    <small>演示指数</small>
                                </div>
                                <div class="ent-console-chart" aria-label="演示趋势图">
                                    <div class="ent-chart-bars" aria-hidden="true">
                                        <i class="is-38"></i><i class="is-46"></i><i class="is-42"></i><i class="is-57"></i>
                                        <i class="is-53"></i><i class="is-65"></i><i class="is-72"></i><i class="is-78"></i>
                                        <i class="is-69"></i><i class="is-84"></i><i class="is-88"></i><i class="is-94"></i>
                                    </div>
                                    <div class="ent-chart-axis"><span>01</span><span>10</span><span>20</span><span>30</span></div>
                                </div>
                                <div class="ent-console-sources">
                                    <div><span>01</span><p>Knowledge evidence<strong>2,418</strong></p></div>
                                    <div><span>02</span><p>Published answers<strong>684</strong></p></div>
                                    <div><span>03</span><p>Source mentions<strong>1,092</strong></p></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="ent-trust-strip" aria-label="演示合作组织">
            <div class="ent-shell">
                <div class="ent-section-marker"><span>Demo ecosystem</span><span>以下组织名称均为虚构演示</span></div>
                <div class="ent-logo-ledger">
                    <span>NORTHSTAR INDUSTRIAL</span>
                    <span>KINTSUGI ROBOTICS</span>
                    <span>ALDER BIOWORKS</span>
                    <span>MERIDIAN COMMERCE</span>
                    <span>ATLAS CLOUD SYSTEMS</span>
                </div>
            </div>
        </section>

        <section class="ent-section ent-metrics ent-reveal" aria-labelledby="metrics-title">
            <div class="ent-shell">
                <div class="ent-section-heading ent-section-heading--split">
                    <div>
                        <span class="ent-kicker">Ecosystem at a glance</span>
                        <h2 id="metrics-title">一个开放系统，连接全球 <span class="ent-no-break">GEO 协作</span></h2>
                    </div>
                    <p>本组指标用于展示企业官网的数据表达方式，所有数值均为演示数据。</p>
                </div>
                <div class="ent-metric-grid">
                    <article><span>01</span><strong>41</strong><h3>社区协作节点</h3><p>演示数据，覆盖亚太、欧洲和北美。</p></article>
                    <article><span>02</span><strong>18</strong><h3>内容语言</h3><p>演示数据，呈现全球内容运营能力。</p></article>
                    <article><span>03</span><strong>6,420</strong><h3>知识证据片段</h3><p>演示数据，说明知识结构化规模。</p></article>
                    <article><span>04</span><strong>99.94%</strong><h3>交付可观测率</h3><p>演示数据，呈现任务与分发的透明度。</p></article>
                </div>
            </div>
        </section>

        <section id="platform" class="ent-section ent-platform" aria-labelledby="platform-title">
            <div class="ent-shell">
                <div class="ent-section-heading ent-section-heading--split ent-reveal">
                    <div>
                        <span class="ent-kicker">The GEO operating system</span>
                        <h2 id="platform-title">把 GEO 的每个关键环节连接起来</h2>
                    </div>
                    <p>从企业知识到可引用答案，每一步都有清晰的数据、流程和反馈。</p>
                </div>

                <div class="ent-platform-grid">
                    <article class="ent-platform-card ent-platform-card--wide ent-reveal">
                        <div class="ent-platform-card__head">
                            <span>01 · Knowledge Evidence</span>
                            <i data-lucide="database-zap" aria-hidden="true"></i>
                        </div>
                        <h3>企业知识证据库</h3>
                        <p>汇集网页、资料和企业知识项目，将内容拆分为可检索、可追溯的证据单元。</p>
                        <div class="ent-evidence-board" aria-label="知识证据界面演示">
                            <div class="ent-evidence-board__nav"><span class="is-active">Source map</span><span>Chunks</span><span>Retrieval</span></div>
                            <div class="ent-evidence-board__rows">
                                <div><i></i><span>Global product documentation</span><strong>842 chunks</strong><em>Ready</em></div>
                                <div><i></i><span>Enterprise policy center</span><strong>316 chunks</strong><em>Synced</em></div>
                                <div><i></i><span>Regional solution library</span><strong>1,260 chunks</strong><em>Ready</em></div>
                            </div>
                            <small>界面与数据均为演示</small>
                        </div>
                    </article>

                    <article class="ent-platform-card ent-platform-card--tall ent-reveal">
                        <div class="ent-platform-card__head">
                            <span>02 · Workflow</span>
                            <i data-lucide="workflow" aria-hidden="true"></i>
                        </div>
                        <h3>内容任务工作流</h3>
                        <p>从策略、生成、审核到发布，建立可复用的企业级内容生产路径。</p>
                        <ol class="ent-workflow-list">
                            <li class="is-complete"><span>01</span><p>Strategy brief<small>策略与目标</small></p><i data-lucide="check" aria-hidden="true"></i></li>
                            <li class="is-complete"><span>02</span><p>Evidence retrieval<small>知识检索</small></p><i data-lucide="check" aria-hidden="true"></i></li>
                            <li class="is-active"><span>03</span><p>Editorial review<small>质量与风险审核</small></p><i data-lucide="loader-circle" aria-hidden="true"></i></li>
                            <li><span>04</span><p>Distribution<small>多渠道发布</small></p><i data-lucide="circle" aria-hidden="true"></i></li>
                        </ol>
                    </article>

                    <article class="ent-platform-card ent-reveal">
                        <div class="ent-platform-card__head">
                            <span>03 · AI Visibility</span>
                            <i data-lucide="radar" aria-hidden="true"></i>
                        </div>
                        <h3>AI 可见性观测</h3>
                        <p>追踪重点问题、回答来源和品牌提及，持续优化 GEO 可见性。</p>
                        <div class="ent-radar-mock" aria-hidden="true">
                            <span class="is-a"></span><span class="is-b"></span><span class="is-c"></span>
                            <i></i><b></b><em></em>
                        </div>
                        <small class="ent-demo-caption">静态界面演示</small>
                    </article>

                    <article class="ent-platform-card ent-reveal">
                        <div class="ent-platform-card__head">
                            <span>04 · Distribution</span>
                            <i data-lucide="send" aria-hidden="true"></i>
                        </div>
                        <h3>多渠道分发网络</h3>
                        <p>用签名请求、失败重试和状态回读，确保内容安全到达目标站点。</p>
                        <div class="ent-channel-mock">
                            <span>Official Site<i>Synced</i></span>
                            <span>WordPress<i>Synced</i></span>
                            <span>GeoFlow Agent<i>Ready</i></span>
                            <span>Custom API<i>Queue</i></span>
                        </div>
                        <small class="ent-demo-caption">通道状态为演示数据</small>
                    </article>
                </div>
            </div>
        </section>

        @if($configuredModules->isNotEmpty())
            <section class="ent-section ent-builder-section" aria-labelledby="builder-title">
                <div class="ent-shell">
                    <div class="ent-section-heading ent-section-heading--split">
                        <div>
                            <span class="ent-kicker">Managed content</span>
                            <h2 id="builder-title">由后台持续运营的官网模块</h2>
                        </div>
                        <p>以下内容来自当前后台首页模块配置，并继承企业模板的纯白视觉规则。</p>
                    </div>
                </div>
                @include('site.partials.homepage-modules', [
                    'homepageModules' => $themeHomepageModules,
                    'homepageStyle' => $homepageStyle ?? [],
                    'showHomepageModules' => true,
                    'articles' => $articles ?? collect(),
                    'featuredArticles' => $featuredArticles ?? collect(),
                    'hotArticles' => $hotArticles ?? collect(),
                    'leadForms' => $leadForms ?? collect(),
                ])
            </section>
        @endif

        <section id="ecosystem" class="ent-section ent-open-source" aria-labelledby="ecosystem-title">
            <div class="ent-shell ent-open-source__grid">
                <div class="ent-open-source__content ent-reveal">
                    <span class="ent-kicker">Open architecture</span>
                    <h2 id="ecosystem-title">企业能力与<span class="ent-no-break">开源生态</span>共同演进</h2>
                    <p>GEOFlow 以开放代码、可扩展通道和清晰工作流支持团队构建自己的 GEO 基础设施。</p>
                    <div class="ent-code-card">
                        <div><span></span><span></span><span></span><small>terminal</small></div>
                        <code><b>$</b> git clone https://github.com/yaojingang/GEOFlow.git</code>
                        <code><b>$</b> cd GEOFlow</code>
                        <code><b>$</b> composer install</code>
                    </div>
                    <a href="https://github.com/yaojingang/GEOFlow" class="ent-button ent-button--dark" target="_blank" rel="noopener noreferrer">
                        <i data-lucide="code-2" aria-hidden="true"></i>
                        访问 GitHub
                    </a>
                </div>

                <div class="ent-architecture ent-reveal" data-ent-reveal-delay="1">
                    <div class="ent-architecture__line" aria-hidden="true"></div>
                    <article><span>01</span><div><small>INGEST</small><h3>接入企业知识</h3><p>URL、资料库、企业知识项目</p></div></article>
                    <article><span>02</span><div><small>ORCHESTRATE</small><h3>编排 GEO 任务</h3><p>策略、内容、审核和状态</p></div></article>
                    <article><span>03</span><div><small>DISTRIBUTE</small><h3>连接发布通道</h3><p>官网、WordPress、Agent 与 API</p></div></article>
                    <article><span>04</span><div><small>OBSERVE</small><h3>观测 AI 可见性</h3><p>问题、来源、提及和优化闭环</p></div></article>
                </div>
            </div>
        </section>

        <section id="solutions" class="ent-section ent-solutions" aria-labelledby="solutions-title">
            <div class="ent-shell">
                <div class="ent-section-heading ent-section-heading--split ent-reveal">
                    <div>
                        <span class="ent-kicker">Solutions by context</span>
                        <h2 id="solutions-title">面向各增长阶段的 <span class="ent-no-break">GEO 方案</span></h2>
                    </div>
                    <p>三个场景均用于演示行业方案模块的表达方式，企业名称和业务结果为虚构信息。</p>
                </div>

                <div class="ent-solution-tabs ent-reveal" data-ent-tabs>
                    <div class="ent-tab-list" role="tablist" aria-label="行业方案">
                        <button type="button" role="tab" id="tab-global-brand" aria-controls="panel-global-brand" aria-selected="true" data-ent-tab="global-brand">全球品牌集团</button>
                        <button type="button" role="tab" id="tab-cross-border" aria-controls="panel-cross-border" aria-selected="false" tabindex="-1" data-ent-tab="cross-border">跨境 SaaS</button>
                        <button type="button" role="tab" id="tab-professional" aria-controls="panel-professional" aria-selected="false" tabindex="-1" data-ent-tab="professional">专业服务机构</button>
                    </div>

                    <div class="ent-tab-panel is-active" role="tabpanel" id="panel-global-brand" aria-labelledby="tab-global-brand" data-ent-panel="global-brand">
                        <div>
                            <span class="ent-badge ent-badge--outline">演示方案 01</span>
                            <h3>统一全球知识标准，保留区域真实语境</h3>
                            <p>适合拥有多品牌、多国家站点和复杂审批链路的全球组织。</p>
                            <ul><li>全球知识证据库</li><li>区域任务与审核策略</li><li>多站点分发和状态回读</li></ul>
                        </div>
                        <div class="ent-solution-score">
                            <span>Demo impact model</span>
                            <strong>+38%</strong>
                            <p>重点问题覆盖率，演示结果</p>
                            <small>Northstar Industrial Systems · 虚构企业</small>
                        </div>
                    </div>

                    <div class="ent-tab-panel" role="tabpanel" id="panel-cross-border" aria-labelledby="tab-cross-border" data-ent-panel="cross-border" hidden>
                        <div>
                            <span class="ent-badge ent-badge--outline">演示方案 02</span>
                            <h3>用产品证据支撑多语言内容与 AI 回答</h3>
                            <p>适合正在扩张国际市场、需要稳定输出产品知识的 SaaS 团队。</p>
                            <ul><li>产品文档结构化</li><li>多语言内容路径</li><li>来源与品牌提及观测</li></ul>
                        </div>
                        <div class="ent-solution-score">
                            <span>Demo impact model</span>
                            <strong>18</strong>
                            <p>支持语言，演示数据</p>
                            <small>Atlas Cloud Systems · 虚构企业</small>
                        </div>
                    </div>

                    <div class="ent-tab-panel" role="tabpanel" id="panel-professional" aria-labelledby="tab-professional" data-ent-panel="professional" hidden>
                        <div>
                            <span class="ent-badge ent-badge--outline">演示方案 03</span>
                            <h3>让专家经验成为高可信行业知识资产</h3>
                            <p>适合咨询、研究、法律和医疗科技等重视证据质量的团队。</p>
                            <ul><li>专家知识项目</li><li>内容风险审核</li><li>主题洞察与资料归档</li></ul>
                        </div>
                        <div class="ent-solution-score">
                            <span>Demo impact model</span>
                            <strong>2.7×</strong>
                            <p>知识复用效率，演示结果</p>
                            <small>Alder Bioworks · 虚构企业</small>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="case-study" class="ent-section ent-case-study" aria-labelledby="case-title">
            <div class="ent-shell">
                <div class="ent-case-card ent-reveal">
                    <div class="ent-case-card__visual">
                        <div class="ent-case-card__brand">
                            <span>N</span>
                            <p>NORTHSTAR<br>INDUSTRIAL SYSTEMS</p>
                        </div>
                        <div class="ent-case-card__mesh" aria-hidden="true">
                            <i></i><i></i><i></i><i></i><i></i><i></i>
                        </div>
                        <small>DEMO CASE · 虚构企业与结果</small>
                    </div>
                    <div class="ent-case-card__content">
                        <span class="ent-kicker">Enterprise story · Demo</span>
                        <h2 id="case-title">让 7 个区域团队的产品知识进入统一 <span class="ent-no-break">GEO 工作流</span></h2>
                        <p>Northstar Industrial Systems 为模板演示创建的虚构企业。案例用于呈现官网的客户证据、成果指标和行动按钮设计。</p>
                        <div class="ent-case-metrics">
                            <div><strong>7</strong><span>区域团队</span></div>
                            <div><strong>+38%</strong><span>重点问题覆盖</span></div>
                            <div><strong>4.2h</strong><span>平均交付时间</span></div>
                        </div>
                        <p class="ent-demo-caption">以上结果均为演示数据</p>
                        <a href="#contact" class="ent-text-link">讨论相似场景 <i data-lucide="arrow-right" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
        </section>

        <section class="ent-section ent-footprint" aria-labelledby="footprint-title">
            <div class="ent-shell ent-footprint__grid">
                <div class="ent-section-heading ent-reveal">
                    <span class="ent-kicker">Global collaboration · Demo</span>
                    <h2 id="footprint-title">让跨时区团队共享 <span class="ent-no-break">GEO 协作语言</span></h2>
                    <p>节点名称、覆盖范围和响应数据用于展示全球官网模块，当前均为演示信息。</p>
                    <a href="#contact" class="ent-text-link">建立企业协作网络 <i data-lucide="arrow-right" aria-hidden="true"></i></a>
                </div>
                <div class="ent-region-board ent-reveal" data-ent-reveal-delay="1">
                    <div class="ent-region-board__header"><span>REGION NETWORK</span><small>DEMO DATA</small></div>
                    <div class="ent-region-board__canvas" aria-hidden="true">
                        <i class="is-singapore"></i><i class="is-shanghai"></i><i class="is-frankfurt"></i><i class="is-london"></i><i class="is-virginia"></i>
                        <span class="is-line-a"></span><span class="is-line-b"></span><span class="is-line-c"></span>
                    </div>
                    <div class="ent-region-board__list">
                        <div><span>APAC</span><strong>Singapore · Shanghai</strong><small>12 nodes</small></div>
                        <div><span>EMEA</span><strong>Frankfurt · London</strong><small>15 nodes</small></div>
                        <div><span>AMER</span><strong>Virginia · Toronto</strong><small>14 nodes</small></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="ent-section ent-insights" aria-labelledby="insights-title">
            <div class="ent-shell">
                <div class="ent-section-heading ent-section-heading--split ent-reveal">
                    <div>
                        <span class="ent-kicker">Ideas and evidence</span>
                        <h2 id="insights-title">GEOFlow 实践与洞察</h2>
                    </div>
                    <a href="{{ route('site.archive') }}" class="ent-text-link">查看全部归档 <i data-lucide="arrow-right" aria-hidden="true"></i></a>
                </div>

                <div class="ent-featured-grid">
                    @forelse($featuredResources as $article)
                        @include('theme.geoflow-template-21-enterprise-signature.partials.article-card', ['article' => $article, 'showFeaturedBadge' => true])
                    @empty
                        <article class="ent-demo-resource">
                            <span>DEMO RESOURCE 01</span><h3>全球企业如何建立 GEO 知识证据体系</h3><p>演示资源，用于表现深度报告卡片。</p><a href="{{ route('site.archive') }}">浏览内容归档</a>
                        </article>
                        <article class="ent-demo-resource">
                            <span>DEMO RESOURCE 02</span><h3>从内容发布到 AI 来源观测的完整闭环</h3><p>演示资源，用于表现产品实践卡片。</p><a href="{{ route('site.archive') }}">浏览内容归档</a>
                        </article>
                        <article class="ent-demo-resource">
                            <span>DEMO RESOURCE 03</span><h3>开源架构如何支撑企业级 GEO 协作</h3><p>演示资源，用于表现技术洞察卡片。</p><a href="{{ route('site.archive') }}">浏览内容归档</a>
                        </article>
                    @endforelse
                </div>

                <div class="ent-latest">
                    <div class="ent-latest__heading">
                        <h3>{{ __('site.home_latest') }}</h3>
                        <span>{{ $homeArticles->count() }} resources</span>
                    </div>
                    <div class="ent-latest__list">
                        @forelse($homeArticles->take(5) as $article)
                            <a href="{{ route('site.article', $article->slug) }}">
                                <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <div>
                                    <small>{{ $article->category?->name ?? __('front.nav.all_articles') }}</small>
                                    <strong>{{ $article->title }}</strong>
                                </div>
                                <time datetime="{{ ($article->published_at ?? $article->created_at)?->toAtomString() }}">{{ ($article->published_at ?? $article->created_at)?->format('Y.m.d') }}</time>
                                <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                            </a>
                        @empty
                            <div class="ent-empty-state">
                                <span>Content runway</span>
                                <h3>在后台发布文章后，最新洞察会自动出现在这里</h3>
                                <p>当前主题已经接入 GEOFlow 的文章、分类和归档数据。</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section id="contact" class="ent-section ent-conversion" aria-labelledby="contact-title">
            <div class="ent-shell ent-conversion__grid">
                <div class="ent-conversion__content ent-reveal">
                    <span class="ent-kicker">Start with one GEO challenge</span>
                    <h2 id="contact-title">定义企业的下一条 <span class="ent-no-break">GEO 增长路径</span></h2>
                    <p>
                        留下团队目标、市场和当前挑战。
                        {{ $primaryLeadForm
                            ? '提交内容会安全进入 GEOFlow 增长中心。'
                            : '在后台启用并指定表单后，提交内容会进入 GEOFlow 增长中心。' }}
                    </p>
                    <div class="ent-contact-points">
                        <div><span>01</span><p><strong>30 分钟需求交流</strong><small>梳理当前 GEO 场景</small></p></div>
                        <div><span>02</span><p><strong>能力与数据映射</strong><small>识别知识、流程和通道</small></p></div>
                        <div><span>03</span><p><strong>首阶段实施建议</strong><small>形成可执行的试点范围</small></p></div>
                    </div>
                </div>
                <div class="ent-conversion__form ent-reveal" data-ent-reveal-delay="1">
                    @if($primaryLeadForm)
                        <div class="ent-form-status ent-form-status--live"><i></i> 已连接后台表单</div>
                        @include('site.partials.lead-form', [
                            'leadForm' => $primaryLeadForm,
                            'embedded' => true,
                            'title' => trim((string) ($contactLeadFormModule['title'] ?? '')) !== ''
                                ? $contactLeadFormModule['title']
                                : '预约企业 GEO 方案交流',
                            'description' => trim((string) ($contactLeadFormModule['body'] ?? '')) !== ''
                                ? $contactLeadFormModule['body']
                                : '提交后将进入后台线索管理。'
                        ])
                    @else
                        <div class="ent-form-status">
                            <i></i>
                            @if($leadFormSelectionInvalid)
                                演示表单 · 后台指定的表单不可用
                            @elseif($leadFormNeedsSelection)
                                演示表单 · 请在首页模块指定表单
                            @else
                                演示表单 · 当前未连接数据提交
                            @endif
                        </div>
                        <div class="ent-demo-form" aria-label="企业联系演示表单">
                            <div class="ent-demo-form__heading">
                                <h3>预约企业 GEO 方案交流</h3>
                                <p>
                                    @if($leadFormSelectionInvalid)
                                        后台首页模块指定的表单当前未启用，请检查表单状态。
                                    @elseif($leadFormNeedsSelection)
                                        请在后台首页模块中添加线索表单模块，并指定官网使用的表单。
                                    @else
                                        在后台增长中心启用表单后，再通过首页模块指定官网表单。
                                    @endif
                                </p>
                            </div>
                            <div class="ent-form-row">
                                <label>姓名<input type="text" placeholder="例如：林清" disabled></label>
                                <label>工作邮箱<input type="email" placeholder="name@company.com" disabled></label>
                            </div>
                            <label>企业名称<input type="text" placeholder="例如：Northstar Industrial" disabled></label>
                            <label>希望讨论的方向
                                <select disabled>
                                    <option>企业知识与 GEO 策略</option>
                                    <option>AI 可见性观测</option>
                                    <option>多渠道内容分发</option>
                                </select>
                            </label>
                            <label>当前挑战<textarea rows="4" placeholder="简单描述团队、市场和目标" disabled></textarea></label>
                            <button type="button" class="ent-button ent-button--primary ent-button--full" disabled>
                                提交需求
                                <i data-lucide="arrow-right" aria-hidden="true"></i>
                            </button>
                            <small>演示状态不会发送或保存任何信息</small>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @else
        <section class="ent-results">
            <div class="ent-shell">
                <div class="ent-results__header">
                    <span class="ent-kicker">
                        @if($search !== '')
                            Search results
                        @elseif($categoryMissing)
                            Category status
                        @elseif($category)
                            Category
                        @else
                            Resource library
                        @endif
                    </span>
                    <h1>{{ $viewTitle }}</h1>
                    <p>{{ $pageDescription }}</p>
                    <form method="get" action="{{ route('site.home') }}" class="ent-results__search" role="search">
                        <label class="sr-only" for="ent-result-search">{{ __('site.search_placeholder') }}</label>
                        <input id="ent-result-search" type="search" name="search" value="{{ $search }}" placeholder="{{ __('site.search_placeholder') }}">
                        <button type="submit">{{ __('site.search_button') }}</button>
                    </form>
                </div>

                <div class="ent-resource-grid">
                    @forelse($articles as $article)
                        @include('theme.geoflow-template-21-enterprise-signature.partials.article-card', ['article' => $article])
                    @empty
                        <div class="ent-empty-state ent-empty-state--wide">
                            <span>0 results</span>
                            <h2>{{ __('site.home_empty_title') }}</h2>
                            <p>尝试使用更简短的关键词，或返回内容归档继续浏览。</p>
                            <a href="{{ route('site.archive') }}" class="ent-text-link">查看归档 <i data-lucide="arrow-right" aria-hidden="true"></i></a>
                        </div>
                    @endforelse
                </div>

                <div class="ent-pagination">{{ $articles->links() }}</div>
            </div>
        </section>
    @endif
@endsection
