@extends('theme.apihot-recommend-20260623.layout')

@push('head')
    @php
        $recommendSchemaAtContext = chr(64).'context';
        $recommendSchemaAtType = chr(64).'type';
        $recommendSchemaItems = [];
        foreach ((is_object($articles ?? null) && method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles ?? []))->take(10) as $schemaArticle) {
            $recommendSchemaItems[] = [
                $recommendSchemaAtType => 'ListItem',
                'position' => count($recommendSchemaItems) + 1,
                'url' => route('site.article', $schemaArticle->slug),
                'name' => $schemaArticle->title,
            ];
        }
        $recommendCollectionSchema = [
            $recommendSchemaAtContext => 'https://schema.org',
            $recommendSchemaAtType => 'CollectionPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.home'),
            'mainEntity' => [
                $recommendSchemaAtType => 'ItemList',
                'itemListElement' => $recommendSchemaItems,
            ],
        ];
    @endphp
    <script type="application/ld+json">
        {!! json_encode($recommendCollectionSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
@endpush

@section('content')
    @php
        $recommendSearch = (string) ($search ?? '');
        $recommendDefinedVars = get_defined_vars();
        $recommendShowHomepage = array_key_exists('showHomepageModules', $recommendDefinedVars)
            ? (bool) $showHomepageModules
            : (($activeNav ?? '') === 'home');
        $isRecommendationHome = $recommendSearch === '' && !($categoryMissing ?? false) && $recommendShowHomepage;
        $latestRecommendationArticles = is_object($articles ?? null) && method_exists($articles, 'getCollection') ? $articles->getCollection()->take(10) : collect($articles ?? [])->take(10);
    @endphp

    @if(! $isRecommendationHome)
@include("site.partials.homepage-modules", ["homepageModules" => $homepageModules ?? [], "homepageStyle" => $homepageStyle ?? [], "showHomepageModules" => $showHomepageModules ?? false, "articles" => $articles ?? collect(), "featuredArticles" => $featuredArticles ?? collect(), "hotArticles" => $hotArticles ?? collect()])

@php
        $homeArticles = is_object($articles ?? null) && method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles ?? []);
        $homepageHotArticles = collect($hotArticles ?? []);
        $isDefaultHome = $search === '' && !$category && !$categoryMissing;
        $leadArticle = $isDefaultHome ? ($featuredArticles->first() ?: $homeArticles->first()) : null;
        $leadSummary = $leadArticle ? trim((string) ($cardSummaries[$leadArticle->id] ?? '')) : '';
        $headlineArticles = $isDefaultHome
            ? collect($featuredArticles)->concat($homeArticles)->filter()->unique(fn ($item) => $item->id)->reject(fn ($item) => $leadArticle && $item->id === $leadArticle->id)->take(4)
            : collect();
    @endphp
    <div class="ne-shell ne-layout">
        <section class="ne-feed">
            @if($search !== '')
                <div class="ne-page-head">
                    <div class="ne-page-kicker">{{ __('site.search_button') }}</div>
                    <h1 class="ne-page-title">{{ __('site.search_breadcrumb', ['term' => $search]) }}</h1>
                    <p class="ne-page-desc">{{ $pageDescription }}</p>
                </div>
            @elseif($categoryMissing)
                <div class="ne-page-head">
                    <div class="ne-page-kicker">{{ __('site.category_not_found') }}</div>
                    <h1 class="ne-page-title">{{ __('site.category_not_found') }}</h1>
                    <p class="ne-page-desc">{{ $pageDescription }}</p>
                </div>
            @else
                @if($leadArticle)
                    <section class="ne-home-lead">
                        <div class="ne-home-lead-main">
                            <div class="ne-page-kicker">{{ $siteSubtitle !== '' ? $siteSubtitle : $siteTitle }}</div>
                            <h1>
                                <a href="{{ route('site.article', $leadArticle->slug) }}">{{ $leadArticle->title }}</a>
                            </h1>
                            @if($leadSummary !== '')
                                <p>{{ $leadSummary }}</p>
                            @elseif($siteDescription !== '')
                                <p>{{ $siteDescription }}</p>
                            @endif
                            <a href="{{ route('site.article', $leadArticle->slug) }}" class="ne-card-action">{{ __('site.home_read_more') }}</a>
                        </div>
                        <div class="ne-home-headlines">
                            <div class="ne-mini-title">{{ __('site.home_featured') }}</div>
                            @forelse($headlineArticles as $headlineArticle)
                                <a href="{{ route('site.article', $headlineArticle->slug) }}">{{ $headlineArticle->title }}</a>
                            @empty
                                <span>{{ $siteDescription !== '' ? $siteDescription : __('site.home_hero_fallback') }}</span>
                            @endforelse
                        </div>
                    </section>
                @endif
                @if($homepageHotArticles->isNotEmpty())
                    <div class="ne-hot-carousel" data-hot-carousel>
                        @foreach($homepageHotArticles as $hotArticle)
                            <a href="{{ route('site.article', $hotArticle->slug) }}" class="ne-breaking {{ $loop->first ? 'is-active' : '' }}" data-hot-slide>
                                <strong>{{ __('site.home_hot_badge') }}</strong>
                                <span>{{ $hotArticle->title }}</span>
                            </a>
                        @endforeach
                        @if($homepageHotArticles->count() > 1)
                            <div class="ne-hot-dots" aria-hidden="true">
                                @foreach($homepageHotArticles as $hotArticle)
                                    <button type="button" class="{{ $loop->first ? 'is-active' : '' }}" data-hot-dot></button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            @endif

            @if($featuredArticles->isNotEmpty() && $search === '' && !$category)
                <section class="ne-feed-card">
                    <div class="ne-section-title">
                        <span class="ne-title-row">{{ __('site.home_featured') }}</span>
                    </div>
                    <div class="ne-feed">
                        @foreach($featuredArticles->take(5) as $article)
                            @include('theme.apihot-recommend-20260623.partials.article-card', ['article' => $article, 'showFeaturedBadge' => true])
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="ne-feed-card">
                <div class="ne-section-title">
                    <span class="ne-title-row">{{ $viewTitle }}</span>
                </div>
                <div class="ne-feed">
                    @forelse($articles as $article)
                        @include('theme.apihot-recommend-20260623.partials.article-card', ['article' => $article])
                    @empty
                        <div class="rounded-2xl border border-dashed border-gray-200 bg-white p-10 text-center text-gray-500">
                            {{ __('site.home_empty_title') }}
                        </div>
                    @endforelse
                </div>
            </section>

            <div class="mt-3">
                {{ $articles->links() }}
            </div>
        </section>

        @include('theme.apihot-recommend-20260623.partials.sidebar', ['showFeedPanel' => $isDefaultHome])
    </div>
    @else
        <div class="tk-recommend-home">
<nav aria-label="API热点网 主导航" class="nav">
<div class="nav-inner">
<a class="logo" href="{{ route('site.home') }}">
<span class="logo-mark">T</span>
<span class="logo-text">
<b>API热点网</b>
<small>API 中转站资讯与评测</small>
</span>
</a>
<div class="nav-links">
<a href="{{ route('site.home') }}">监控总览</a>
<a class="active" href="{{ route('site.home') }}">精选推荐</a>
<a href="#ranks">通道明细</a>
<a href="#picks">供应商</a>
<a href="#certify">监控策略</a>
</div>
<div class="nav-right">
<span class="live-dot"><i></i>静态精选版</span>
<a class="btn btn-ghost btn-sm" href="#picks">查看推荐</a>
<a class="avatar" href="#quick-try" title="精选入口">L</a>
</div>
</div>
</nav><section class="rec-hero">
<div class="rec-hero-inner">
<div class="rec-hero-left">
<span class="rec-tag-pill"><i></i> 编辑精选 · 每周更新 <b>·</b> 06/22 已刷新</span>
<h1>
            不踩坑的<span class="hl">中转站</span>，
            <br/>
            都在这里。
          </h1>
<p class="rec-lead">
            API热点网用 <b>官网公开信息</b> + <b>场景化推荐</b> 先筛出值得关注的 AI API 与开发者服务。这一静态页可直接部署，后续接入探测数据后可继续扩展为实时榜单。
          </p>
<div class="rec-cta-row">
<a class="btn btn-primary" href="#picks">查看本周精选 →</a>
<a class="btn btn-ghost" href="#quick-try">创建个人网关</a>
<span class="trust-mini">
<span class="ts-av">A</span>
<span class="ts-av">P</span>
<span class="ts-av">L</span>
<span class="ts-av">+</span>
<b>3</b> 个官网来源已收录
            </span>
</div>
</div>
<div class="rec-hero-right">
<div class="trust-card">
<div class="trust-h">
<span class="trust-ico">✓</span>
<b>靠谱认证体系</b>
</div>
<div class="trust-row"><span class="trust-k">连续监控</span><span class="trust-v">静态收录</span></div>
<div class="trust-row"><span class="trust-k">综合评分</span><span class="trust-v">平均 95</span></div>
<div class="trust-row"><span class="trust-k">官网可访问</span><span class="trust-v">3 / 3</span></div>
<div class="trust-row"><span class="trust-k">接入方向</span><span class="trust-v">API / Agent</span></div>
<div class="trust-row"><span class="trust-k">福利与场景</span><span class="trust-v">3 项</span></div>
<div class="trust-foot">内容基于官网公开资料整理 · <a href="#certify">了解认证逻辑</a></div>
</div>
</div>
</div>
</section><main class="page rec-page" itemscope="" itemtype="https://schema.org/CollectionPage">
<section aria-label="推荐页统计" class="rec-stats">
<div class="rs"><div class="rs-v">3</div><div class="rs-l">编辑精选位</div></div>
<div class="rs"><div class="rs-v">3</div><div class="rs-l">上榜中转站</div></div>
<div class="rs"><div class="rs-v">0</div><div class="rs-l">累计推荐点击</div></div>
<div class="rs"><div class="rs-v">95</div><div class="rs-l">精选平均评分</div></div>
<div class="rs"><div class="rs-v">3</div><div class="rs-l">场景化推荐</div></div>
</section>
<div class="section-head" id="picks">
<h2>本周编辑精选 <span class="tag">TOP 3</span></h2>
<span class="sub">基于官网公开资料 · 综合产品定位 / 协议覆盖 / 接入入口 / 场景匹配度</span>
</div>
<section aria-label="本周编辑精选" class="rec-picks">
<article class="rec-pick primary" data-source-url="https://www.aigocode.com" itemscope="" itemtype="https://schema.org/SoftwareApplication">
<div class="pick-ribbon">AI 编程首推</div>
<div class="pick-head">
<span class="pick-mark" style="background:#2563eb">AI</span>
<div class="pick-meta">
<h3><span itemprop="name">AIGoCode</span> <span class="badge b-green dot">官网可访问</span></h3>
<p itemprop="description">AI 开发者工作台 · 代码辅助 / 智能问答 / 团队协作</p>
</div>
<div class="pick-score">
<div class="ring" style="background: conic-gradient(var(--brand) 338.4deg,#eaf1ff 0)"><span>94</span></div>
</div>
</div>
<div class="pick-body">
<ul class="pick-points">
<li>适合个人开发者和团队统一管理 AI 编程工作流。</li>
<li>官网强调代码辅助、使用管理、团队协作与订阅服务。</li>
<li>提供文档、定价、客服和登录入口，转化链路完整。</li>
<li>页面披露开发者用户、稳定性和任务处理等运营指标。</li>
</ul>
<div class="pick-spec">
<div class="ps"><i class="ps-d s-ok"></i><span>官网资料已收录 · 等待 API热点网实测接入</span></div>
<div class="ps"><i class="ps-d s-ok"></i><span>定位：AI 编程工作台 · 开发者效率工具</span></div>
<div class="ps"><i class="ps-d s-ok"></i><span>订阅服务：Standard / Premium / Professional · 官网</span></div>
</div>
</div>
<div class="pick-foot">
<span class="rec-endpoint referral-entry">
<a class="referral-anchor" href="https://aigocode.com/invite/AP5KFJWJ" rel="noopener noreferrer" target="_blank">AIGoCode 推荐入口</a>
<button aria-label="复制 AIGoCode 推荐链接" class="copy-mini" data-copy="https://aigocode.com/invite/AP5KFJWJ" title="复制推荐链接">⧉</button>
</span>
<a class="btn btn-primary btn-sm" href="https://aigocode.com/invite/AP5KFJWJ" rel="noopener noreferrer" target="_blank">推荐注册</a>
</div>
</article>
<article class="rec-pick" data-source-url="https://www.packyapi.com" itemscope="" itemtype="https://schema.org/WebApplication">
<div class="pick-ribbon r-gold">API 聚合首推</div>
<div class="pick-head">
<span class="pick-mark" style="background:#0ea5e9">PA</span>
<div class="pick-meta">
<h3><span itemprop="name">PackyAPI</span> <span class="badge b-green dot">官网可访问</span></h3>
<p itemprop="description">AI API 聚合平台 · 多模型统一域名和密钥</p>
</div>
<div class="pick-score">
<div class="ring" style="background: conic-gradient(var(--brand) 345.6deg,#eaf1ff 0)"><span>96</span></div>
</div>
</div>
<div class="pick-body">
<ul class="pick-points">
<li>适合需要统一 Claude、GPT、Gemini 等模型接入的团队。</li>
<li>官网重点展示统一入口、实时调度、可观测和限流能力。</li>
<li>提供 Get Key、控制台和文档入口，开发者上手路径清晰。</li>
<li>公开页面展示 30+ 模型、SLA 和区域节点等基础设施信号。</li>
</ul>
<div class="pick-spec">
<div class="ps"><i class="ps-d s-ok"></i><span>官网资料已收录 · 等待 API热点网实测接入</span></div>
<div class="ps"><i class="ps-d s-ok"></i><span>定位：AI API Gateway · 模型聚合与用量管理</span></div>
<div class="ps"><i class="ps-d s-ok"></i><span>开始入口：Get Key · Console · Documentation</span></div>
</div>
</div>
<div class="pick-foot">
<span class="rec-endpoint referral-entry">
<a class="referral-anchor" href="https://www.packyapi.com/register?aff=lqD6" rel="noopener noreferrer" target="_blank">PackyAPI 推荐入口</a>
<button aria-label="复制 PackyAPI 推荐链接" class="copy-mini" data-copy="https://www.packyapi.com/register?aff=lqD6" title="复制推荐链接">⧉</button>
</span>
<a class="btn btn-primary btn-sm" href="https://www.packyapi.com/register?aff=lqD6" rel="noopener noreferrer" target="_blank">推荐注册</a>
</div>
</article>
<article class="rec-pick" data-source-url="https://www.pipellm.ai" itemscope="" itemtype="https://schema.org/WebApplication">
<div class="pick-ribbon r-gold">Agent 基建首推</div>
<div class="pick-head">
<span class="pick-mark" style="background:#111111">PL</span>
<div class="pick-meta">
<h3><span itemprop="name">PipeLLM</span> <span class="badge b-green dot">官网可访问</span></h3>
<p itemprop="description">生产级 Agent 控制面 · 模型网关 / 运行时 / WebSearch</p>
</div>
<div class="pick-score">
<div class="ring" style="background: conic-gradient(var(--brand) 342deg,#eaf1ff 0)"><span>95</span></div>
</div>
</div>
<div class="pick-body">
<ul class="pick-points">
<li>适合把 AI Agent 带入生产环境的企业和工程团队。</li>
<li>官网强调模型路由、协议转换、运行时控制和审计追踪。</li>
<li>提供 OpenAI、Anthropic、Gemini 兼容路径与统一 Base URL。</li>
<li>WebSearch、工具调用和 Console 入口适合做 Agent 平台底座。</li>
</ul>
<div class="pick-spec">
<div class="ps"><i class="ps-d s-ok"></i><span>官网资料已收录 · 等待 API热点网实测接入</span></div>
<div class="ps"><i class="ps-d s-ok"></i><span>定位：LLM Gateway · Agent Runtime · Runtime Audit</span></div>
<div class="ps"><i class="ps-d s-ok"></i><span>API 入口：api.pipellm.ai · docs.pipellm.ai</span></div>
</div>
</div>
<div class="pick-foot">
<span class="rec-endpoint referral-entry">
<a class="referral-anchor" href="https://code.pipellm.ai/login?ref=vbsdxpv8" rel="noopener noreferrer" target="_blank">PipeLLM 推荐入口</a>
<button aria-label="复制 PipeLLM 推荐链接" class="copy-mini" data-copy="https://code.pipellm.ai/login?ref=vbsdxpv8" title="复制推荐链接">⧉</button>
</span>
<a class="btn btn-primary btn-sm" href="https://code.pipellm.ai/login?ref=vbsdxpv8" rel="noopener noreferrer" target="_blank">推荐注册</a>
</div>
</article>
</section>
<div class="section-head" id="latest-articles">
<h2>最新文章 <span class="tag">最近 10 篇</span></h2>
<span class="sub">自动调用当前 GEOFlow 站点按发布时间排序的最新内容</span>
</div>
<section aria-label="最新文章" class="tk-latest-panel">
<div class="tk-latest-grid">
    @forelse($latestRecommendationArticles as $article)
      @php
        $summary = trim((string) ($cardSummaries[$article->id] ?? ''));
        if ($summary === '') {
            $summary = trim(strip_tags((string) ($article->excerpt ?? '')));
        }
        $summary = \Illuminate\Support\Str::limit($summary, 96);
      @endphp
      <a class="tk-latest-card" href="{{ route('site.article', $article->slug) }}">
<span class="tk-latest-index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
<span class="tk-latest-body">
<strong>{{ $article->title }}</strong>
          @if($summary !== '')
            <em>{{ $summary }}</em>
          @endif
          <span class="tk-latest-meta">
            @if(!empty($article->category?->name))
              <b>{{ $article->category->name }}</b>
            @else
              <b>最新发布</b>
            @endif
            @if(!empty($article->published_at))
              <time>{{ $article->published_at->format('Y-m-d') }}</time>
            @endif
          </span>
</span>
</a>
    @empty
      <div class="tk-latest-empty">暂无已发布文章。</div>
    @endforelse
  </div>
<div class="tk-latest-more">
<a class="btn btn-primary" href="{{ route('site.archive') }}">查看更多文章 →</a>
</div>
</section>

<div class="section-head" id="ranks">
<h2>多维度榜单 <span class="tag">按你的需求挑</span></h2>
<span class="sub">静态版先按公开资料和场景匹配度排序，接入 API热点网探测后可替换为实时数据</span>
</div>
<div aria-label="推荐榜单维度" class="rank-tabs" role="tablist">
<button class="rt active" data-rank-tab="overall" type="button">综合榜</button>
<button class="rt" data-rank-tab="speed" type="button">接入速度</button>
<button class="rt" data-rank-tab="price" type="button">性价比</button>
<button class="rt" data-rank-tab="stable" type="button">稳定信号</button>
</div>
<div class="card board rank-board">
<div class="dt-wrap">
<table class="dt rec-table">
<thead>
<tr>
<th>#</th>
<th>中转站</th>
<th>综合状态</th>
<th>真实延迟 P95</th>
<th>30 天成功率</th>
<th>价格倍数</th>
<th>评分</th>
<th>新人福利</th>
<th></th>
</tr>
</thead>
<tbody id="rank-rows">
<tr data-position="1" data-price="2" data-speed="2" data-stable="2">
<td><span class="rk-num">1</span></td>
<td>
<div class="u-cell">
<span class="av" style="background:#2563eb">AI</span>
<span class="nm">AIGoCode<small>AI 编程工作台 · 团队协作</small></span>
</div>
</td>
<td><span class="badge b-green dot">官网可访问</span></td>
<td class="mono">待实测</td>
<td class="mono">公开资料</td>
<td class="mono price-down">订阅制</td>
<td class="mono">94</td>
<td><span class="reward-tag">开发者套餐 · 官网</span></td>
<td><a class="try-btn" href="https://aigocode.com/invite/AP5KFJWJ" rel="noopener noreferrer" target="_blank">注册</a></td>
</tr>
<tr data-position="2" data-price="1" data-speed="1" data-stable="1">
<td><span class="rk-num">2</span></td>
<td>
<div class="u-cell">
<span class="av" style="background:#0ea5e9">PA</span>
<span class="nm">PackyAPI<small>AI API Gateway · 多模型聚合</small></span>
</div>
</td>
<td><span class="badge b-green dot">SLA 声明</span></td>
<td class="mono">待实测</td>
<td class="mono">官网披露</td>
<td class="mono price-down">免费开始</td>
<td class="mono">96</td>
<td><span class="reward-tag">Get Key · Console</span></td>
<td><a class="try-btn" href="https://www.packyapi.com/register?aff=lqD6" rel="noopener noreferrer" target="_blank">注册</a></td>
</tr>
<tr data-position="3" data-price="3" data-speed="3" data-stable="3">
<td><span class="rk-num">3</span></td>
<td>
<div class="u-cell">
<span class="av" style="background:#111111">PL</span>
<span class="nm">PipeLLM<small>Agent Infrastructure · Model Gateway</small></span>
</div>
</td>
<td><span class="badge b-green dot">生产控制面</span></td>
<td class="mono">待实测</td>
<td class="mono">公开资料</td>
<td class="mono price-down">联系销售</td>
<td class="mono">95</td>
<td><span class="reward-tag">Open Console · Docs</span></td>
<td><a class="try-btn" href="https://code.pipellm.ai/login?ref=vbsdxpv8" rel="noopener noreferrer" target="_blank">注册</a></td>
</tr>
</tbody>
</table>
</div>
<div class="rec-board-foot">
<span>共 3 个上榜服务 · 静态版不调用后端 API</span>
<a href="#picks">看编辑精选</a>
</div>
</div>
<div class="section-head" id="quick-try">
<h2>创建个人网关 <span class="tag">静态入口</span></h2>
<span class="sub">先通过官网入口完成注册或咨询，后续可把真实 API Key 接入 API热点网控制台</span>
</div>
<section class="quick-try">
<div class="card try-card">
<div class="try-step">1</div>
<div class="try-h">选择适合的服务类型</div>
<p>先判断你需要 AI 编程工作台、API 聚合网关，还是生产 Agent 控制面。</p>
<div class="try-code"><span class="tc-l">Coding</span><code>AIGoCode</code><button class="copy-mini" data-copy="https://aigocode.com/invite/AP5KFJWJ">⧉</button></div>
<div class="try-code"><span class="tc-l">Gateway</span><code>PackyAPI</code><button class="copy-mini" data-copy="https://www.packyapi.com/register?aff=lqD6">⧉</button></div>
<div class="try-code"><span class="tc-l">Agent</span><code>PipeLLM</code><button class="copy-mini" data-copy="https://code.pipellm.ai/login?ref=vbsdxpv8">⧉</button></div>
</div>
<div class="card try-card">
<div class="try-step">2</div>
<div class="try-h">粘到常用工具里</div>
<div class="tool-pick">
<div class="tp"><span class="tp-ico">CC</span><b>Claude Code</b><small>OpenAI / Anthropic 兼容</small></div>
<div class="tp"><span class="tp-ico">Cu</span><b>Cursor</b><small>OpenAI 兼容协议</small></div>
<div class="tp"><span class="tp-ico">Cl</span><b>Cline</b><small>Agent 工具链</small></div>
<div class="tp"><span class="tp-ico">CW</span><b>ChatWise</b><small>多模型客户端</small></div>
<div class="tp"><span class="tp-ico">OW</span><b>Open WebUI</b><small>自托管入口</small></div>
<div class="tp"><span class="tp-ico">+</span><b>更多工具</b><small>按 Base URL 接入</small></div>
</div>
</div>
<div class="card try-card">
<div class="try-step">3</div>
<div class="try-h">配置后再接入业务</div>
<p>静态推荐页只负责选型和导流。真实上游 Key、调用额度、审计与告警应在你的控制台或 API热点网实例内管理。</p>
<div class="try-rewards">
<div class="tr-r"><span class="tr-ico">AI</span><div><b>AIGoCode</b><small>AI 编程工作台 · 订阅入口</small></div></div>
<div class="tr-r"><span class="tr-ico">PA</span><div><b>PackyAPI</b><small>API 聚合 · Get Key</small></div></div>
<div class="tr-r"><span class="tr-ico">PL</span><div><b>PipeLLM</b><small>Agent 控制面 · Console</small></div></div>
</div>
<a class="btn btn-primary" href="#picks" style="width:100%;justify-content:center">回到精选列表 →</a>
</div>
</section>
<div class="section-head" id="scenarios">
<h2>看你怎么用 <span class="tag">场景对号入座</span></h2>
<span class="sub">不同业务对中转站要求不同，直接看适合你的那家</span>
</div>
<section class="scenarios">
<div class="card sc">
<div class="sc-h"><span class="sc-ico b-blue">AI</span><h4>AI 编程与团队提效</h4></div>
<p>需要把代码辅助、智能问答、额度和团队协作集中到一个开发者工作台。</p>
<div class="sc-pick">
<span class="sc-mark" style="background:#2563eb">AI</span>
<div><b>AIGoCode</b><small>AI 编程工作台 · 评分 94</small></div>
<a class="btn btn-ghost btn-sm" href="https://aigocode.com/invite/AP5KFJWJ" rel="noopener noreferrer" target="_blank">去注册</a>
</div>
</div>
<div class="card sc">
<div class="sc-h"><span class="sc-ico b-blue">GW</span><h4>统一 API 聚合网关</h4></div>
<p>需要用一个域名和 Key 管理 Claude、GPT、Gemini、Azure OpenAI 等模型。</p>
<div class="sc-pick">
<span class="sc-mark" style="background:#0ea5e9">PA</span>
<div><b>PackyAPI</b><small>API 聚合平台 · 评分 96</small></div>
<a class="btn btn-ghost btn-sm" href="https://www.packyapi.com/register?aff=lqD6" rel="noopener noreferrer" target="_blank">去注册</a>
</div>
</div>
<div class="card sc">
<div class="sc-h"><span class="sc-ico b-blue">RT</span><h4>生产级 Agent 基础设施</h4></div>
<p>需要模型路由、协议转换、工具调用、运行时控制和审计追踪。</p>
<div class="sc-pick">
<span class="sc-mark" style="background:#111111">PL</span>
<div><b>PipeLLM</b><small>Agent 控制面 · 评分 95</small></div>
<a class="btn btn-ghost btn-sm" href="https://code.pipellm.ai/login?ref=vbsdxpv8" rel="noopener noreferrer" target="_blank">去注册</a>
</div>
</div>
</section>
<div class="grid cols-2" style="margin-top:34px">
<div class="card card-pad" id="certify">
<div class="module-title">凭什么靠谱？看认证逻辑 <span class="badge b-blue dot" style="margin-left:6px">透明可复核</span></div>
<div class="module-sub">静态页先以官网公开信息、可访问入口和场景匹配度做编辑推荐，后续可接入 API热点网实测状态 API。</div>
<div class="cert-steps">
<div class="cs"><span class="cs-n">1</span><div><b>读取官网标题、描述、可见文案</b><small>保留 canonical、OG 与 JSON-LD 等来源线索。</small></div></div>
<div class="cs"><span class="cs-n">2</span><div><b>按开发者场景归类</b><small>AI 编程、API 聚合、Agent 基础设施分开推荐。</small></div></div>
<div class="cs"><span class="cs-n">3</span><div><b>外链全部指向推荐注册入口</b><small>用户直接进入带 Refer 信息的官方注册或登录入口。</small></div></div>
<div class="cs"><span class="cs-n">4</span><div><b>SEO / GEO 结构化标注</b><small>ItemList、SoftwareApplication、FAQPage 与 llms.txt 同步。</small></div></div>
<div class="cs"><span class="cs-n">5</span><div><b>后续可替换为真实探测</b><small>延迟、成功率、状态和点击统计可以接回 API热点网 API。</small></div></div>
</div>
</div>
<div class="card card-pad">
<div class="module-title">开放 API 同步推荐状态 <span class="badge b-green dot" style="margin-left:6px">Static Ready</span></div>
<div class="module-sub">当前静态包不依赖后端。部署后如需恢复实时能力，可把下面的路径接回 API热点网 Open API。</div>
<div class="try-code"><span class="tc-l">GET</span><code>/v1/status/channels</code></div>
<div class="try-code"><span class="tc-l">GET</span><code>/v1/status/incidents</code></div>
<a class="btn btn-ghost btn-sm" href="{{ route('site.home') }}">查看 LLM 摘要 →</a>
</div>
</div>
</main><footer class="tk-footer">
<div class="tkf-inner">
<div class="tkf-brand">
<span class="logo-mark">T</span>
<span class="tkf-meta">
<b>API热点网</b>
<small>API 中转站资讯与评测</small>
</span>
</div>
<div class="tkf-links">
<a href="{{ route('site.home') }}">监控总览</a>
<a href="{{ route('site.home') }}">精选推荐</a>
<a href="#quick-try">创建网关</a>
<a href="#certify">认证逻辑</a>
</div>
<div class="tkf-right">
<div class="tkf-copy">API热点网 静态推荐页 <span class="tkf-icp">static</span></div>
<div class="tkf-ver">v0.1.0-static-20260622</div>
</div>
</div>
</footer>
        </div>
        <script>
(function () {
  const rankRows = document.getElementById("rank-rows");
  const rankButtons = Array.from(document.querySelectorAll("[data-rank-tab]"));

  function sortRankRows(metric) {
    if (!rankRows) return;
    const rows = Array.from(rankRows.querySelectorAll("tr"));
    rows.sort((a, b) => {
      const key = metric === "overall" ? "position" : metric;
      const av = Number(a.dataset[key] || a.dataset.position || 0);
      const bv = Number(b.dataset[key] || b.dataset.position || 0);
      return av - bv;
    });
    rows.forEach((row, index) => {
      const badge = row.querySelector(".rk-num");
      if (badge) badge.textContent = String(index + 1);
      rankRows.appendChild(row);
    });
  }

  rankButtons.forEach((button) => {
    button.addEventListener("click", () => {
      rankButtons.forEach((item) => item.classList.toggle("active", item === button));
      sortRankRows(button.dataset.rankTab || "overall");
    });
  });

  function textFromCopyButton(button) {
    if (button.dataset.copy) return button.dataset.copy;
    const code = button.closest("code");
    if (!code) return "";
    return Array.from(code.childNodes)
      .filter((node) => node.nodeType === Node.TEXT_NODE)
      .map((node) => node.textContent || "")
      .join("")
      .trim();
  }

  async function copyText(value) {
    if (!value) return false;
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(value);
      return true;
    }
    const input = document.createElement("textarea");
    input.value = value;
    input.setAttribute("readonly", "");
    input.style.position = "fixed";
    input.style.left = "-9999px";
    document.body.appendChild(input);
    input.select();
    const ok = document.execCommand("copy");
    document.body.removeChild(input);
    return ok;
  }

  document.querySelectorAll(".copy-mini").forEach((button) => {
    button.addEventListener("click", async (event) => {
      event.preventDefault();
      event.stopPropagation();
      const original = button.textContent;
      const value = textFromCopyButton(button);
      try {
        const ok = await copyText(value);
        button.textContent = ok ? "✓" : "!";
      } catch {
        button.textContent = "!";
      }
      window.setTimeout(() => {
        button.textContent = original || "⧉";
      }, 1200);
    });
  });
})();

        </script>
    @endif
@endsection
