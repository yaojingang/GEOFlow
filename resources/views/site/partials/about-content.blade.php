<p class="about-lede">
    GEOFlow 是一套面向 GEO（生成式引擎优化）的开源智能内容工程与多站点分发系统。它把知识库、素材、提示词、AI 生成任务、审核发布、数据分析和多端分发连接成一条可以持续运营的工作流。
</p>

<p>
    这个项目关注一个具体问题：团队已经拥有产品资料、行业经验、案例和专业判断，怎样把这些分散信息整理成可信、可检索、可发布、可追踪的内容资产，并让用户与 AI 搜索系统更容易理解和引用。
</p>

<h2 id="about-purpose">让可信知识进入 AI 答案</h2>

<p>
    GEOFlow 将知识库放在内容生产的起点。团队可以把真实业务资料整理进知识库，再结合标题库、关键词库、图片库、作者和提示词形成生产输入。配置 embedding 模型后，系统会为知识内容建立向量索引，并在文章生成时召回相关资料。
</p>

<blockquote class="about-principle">
    <strong>知识库建设始终排在工作流的前面。</strong>
    <span>稳定、完整、可核验的资料，决定了后续内容生成、审核、发布和分析的质量上限。</span>
</blockquote>

<h2 id="about-workflow">一条完整的内容工作流</h2>

<p>
    GEOFlow 把内容工程拆成五个连续阶段。每个阶段都能单独管理，也能通过任务、队列和分发渠道形成自动化链路。
</p>

<ol class="about-flow">
    <li><span>01</span><div><strong>沉淀知识与素材</strong><p>集中管理知识库、标题、关键词、图片、作者和提示词，为后续生产建立可复用的资料基础。</p></div></li>
    <li><span>02</span><div><strong>配置模型与生成任务</strong><p>接入 OpenAI 风格接口或 Gemini 原生接口，配置生成数量、发布节奏、知识召回和失败重试规则。</p></div></li>
    <li><span>03</span><div><strong>进入草稿与审核</strong><p>生成内容进入文章管理链路，团队可以检查事实、表达、分类、作者和 SEO 信息，再决定发布状态。</p></div></li>
    <li><span>04</span><div><strong>发布到本站与多端</strong><p>内容可以发布到本地前台，也可以通过 GEOFlow Agent、WordPress REST 或通用 HTTP API 分发到目标站点。</p></div></li>
    <li><span>05</span><div><strong>持续观察与改进</strong><p>通过内容、访问、分发、线索与 AI 可见性分析，查看生产状态和内容表现，为下一轮运营提供依据。</p></div></li>
</ol>

<h2 id="about-capabilities">GEOFlow 包含的核心能力</h2>

<dl class="about-capabilities">
    <div><dt>知识与素材管理</dt><dd>支持知识库切片与向量化，并统一管理标题、关键词、图片、作者和提示词。</dd></div>
    <div><dt>任务与队列自动化</dt><dd>管理生成数量、草稿池、审核开关、发布节奏、发布范围、失败重试和任务文章。</dd></div>
    <div><dt>审核与文章管理</dt><dd>覆盖草稿、审核、发布、回收站、分类、作者、SEO 字段和人工发布工单。</dd></div>
    <div><dt>多站点分发</dt><dd>支持目标站点包、渠道密钥、远端设置同步、静态页面生成、文章编辑与删除。</dd></div>
    <div><dt>数据分析</dt><dd>集中查看内容运营、任务健康、访问日志、AI 爬虫、线索和分发趋势。</dd></div>
    <div><dt>主题与抓取输出</dt><dd>提供前台主题、SEO 元信息、Open Graph、Schema、sitemap、TXT 地图和 llms.txt。</dd></div>
</dl>

<h2 id="about-foundation">开放、可部署的技术基础</h2>

<p>GEOFlow 建立在 Laravel 12 与 PHP 8.3+ 之上，使用 PostgreSQL 保存业务数据，并推荐 pgvector 支撑知识向量。Redis 承担队列与缓存，Laravel Scheduler、Horizon 和 Reverb 分别服务于调度、队列运行与实时能力。</p>

<dl class="about-stack">
    <div><dt>应用层</dt><dd>Laravel 12、Blade、PHP 8.3+</dd></div>
    <div><dt>数据层</dt><dd>PostgreSQL、pgvector、Redis</dd></div>
    <div><dt>运行层</dt><dd>Scheduler、Queue、Horizon、Reverb</dd></div>
    <div><dt>部署层</dt><dd>Docker Compose、Nginx、PHP-FPM</dd></div>
</dl>

<p>仓库提供开发与生产环境的 Docker Compose 配置。团队也可以按标准 Laravel 应用方式自托管，并依据自己的模型、域名、数据和安全要求完成配置。</p>

<h2 id="about-scenarios">适合持续建设内容资产的团队</h2>

<p>GEOFlow 可以作为独立 GEO 官网、现有官网中的内容频道、行业信源站点、内部内容管理后台，或多品牌、多主题的分发中枢。内容团队可以使用素材、任务和审核链路，工程团队可以控制部署、模型和渠道，运营团队可以查看发布、分发和分析结果。</p>

<p>适合从一个明确的问题域开始：先确定目标读者和业务目标，再整理真实资料，建立第一条可审核的内容链路，随后逐步扩展模型、自动化和多站点分发。</p>

<h2 id="about-open-source">从开源仓库开始</h2>

<p>GEOFlow 以 Apache License 2.0 开源发布，允许个人与企业在遵守许可证条款的前提下使用、修改、分发和商用。仓库包含应用源码、部署配置、说明文档、测试以及 GEOFlow Agent Skill。</p>

<div class="about-repository">
    <div><strong>查看源码与部署文档</strong><p>从 README 开始了解安装方式、系统架构、能力边界和参与路径。</p></div>
    <a href="{{ $repositoryUrl }}" target="_blank" rel="noopener noreferrer">打开 GitHub 仓库 <i data-lucide="arrow-up-right" aria-hidden="true"></i></a>
</div>
