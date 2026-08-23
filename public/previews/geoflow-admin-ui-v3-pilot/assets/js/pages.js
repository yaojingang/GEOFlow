(function () {
    'use strict';

    const I = (name, className) => window.GeoFlowPilotShell.icon(name, className);

    function pageHeader(eyebrow, title, subtitle, actions) {
        return `<header class="gf-page-header">
            <div class="gf-page-header__copy"><span class="gf-eyebrow">${eyebrow}</span><h1>${title}</h1><p>${subtitle}</p></div>
            <div class="gf-page-header__actions">${actions}</div>
        </header>`;
    }

    function button(label, icon, variant, attributes) {
        return `<button class="gf-button gf-button--${variant || 'secondary'}" type="button" ${attributes || `data-demo="${label}演示"`}>${icon ? I(icon) : ''}<span>${label}</span></button>`;
    }

    function metric(label, value, delta, icon, tone) {
        return `<article class="gf-metric"><div class="gf-metric__head"><span>${label}</span><span class="gf-metric__icon gf-metric__icon--${tone || 'blue'}">${I(icon)}</span></div><strong>${value}</strong><small class="gf-text-${tone === 'red' ? 'danger' : 'success'}">${delta}</small></article>`;
    }

    function dashboard() {
        const bars = [42, 55, 48, 71, 64, 82, 76, 88, 69, 92, 84, 96];
        return `${pageHeader('运营总览', '数据中心', '统一查看内容生产、AI 可见性、分发健康和业务转化。', `${button('刷新', 'refresh-cw', 'secondary')}${button('新建任务', 'plus', 'primary')}`)}
            <nav class="gf-local-tabs" aria-label="数据中心维度">
                <a class="is-active" href="dashboard.html" aria-current="page">运营总览</a><a href="#" data-demo="内容分析演示">内容分析</a><a href="#" data-demo="流量分析演示">流量分析</a><a href="#" data-demo="AI 可见性演示">AI 可见性</a><a href="#" data-demo="线索分析演示">线索分析</a><a href="#" data-demo="分发分析演示">分发分析</a>
            </nav>
            <section class="gf-metrics" aria-label="关键指标">
                ${metric('内容任务', '18', '较上期提升 12.6%', 'layers-3', 'blue')}
                ${metric('知识资产', '1,286', '本周新增 43 条', 'database', 'blue')}
                ${metric('今日分发', '36', '成功率 94.8%', 'circle-check-big', 'green')}
                ${metric('AI 可见性', '72.4%', '7 个问题需要关注', 'scan-search', 'red')}
            </section>
            <section class="gf-dashboard-grid">
                <article class="gf-panel gf-trend-panel">
                    <header class="gf-panel__header"><div><h2>近 30 天 GEO 成效</h2><p>内容发布、AI 引用和线索增长保持同步。</p></div><button class="gf-segment" type="button" data-demo="时间范围演示">近 30 天 ${I('chevron-down')}</button></header>
                    <div class="gf-chart-legend"><span><i class="gf-legend-dot gf-legend-dot--blue"></i>AI 引用</span><span><i class="gf-legend-dot gf-legend-dot--green"></i>有效线索</span></div>
                    <div class="gf-bar-chart" aria-label="近 30 天 GEO 成效柱状图">${bars.map((height, index) => `<span style="--bar-height:${height}%"><i style="--bar-secondary:${Math.max(24, height - 21)}%"></i><b>${index + 1}</b></span>`).join('')}</div>
                    <footer class="gf-chart-footer"><div><strong>326</strong><span>累计 AI 引用</span></div><div><strong>87</strong><span>有效线索</span></div><div><strong>4.7%</strong><span>内容转化率</span></div></footer>
                </article>
                <aside class="gf-panel gf-attention-panel">
                    <header class="gf-panel__header"><div><h2>今天需要处理</h2><p>按业务影响排序。</p></div><span class="gf-badge gf-badge--warning">5 项</span></header>
                    <div class="gf-attention-list">
                        <button type="button" data-demo="处理内容审核"><span class="gf-attention-icon gf-attention-icon--amber">${I('shield-alert')}</span><span><strong>3 篇内容等待审核</strong><small>其中 1 篇存在证据引用风险</small></span>${I('chevron-right')}</button>
                        <button type="button" data-demo="处理渠道异常"><span class="gf-attention-icon gf-attention-icon--red">${I('radio-tower')}</span><span><strong>1 个渠道同步异常</strong><small>企业官网已连续失败 2 次</small></span>${I('chevron-right')}</button>
                        <button type="button" data-demo="查看问题缺口"><span class="gf-attention-icon gf-attention-icon--blue">${I('scan-search')}</span><span><strong>品牌问题覆盖下降</strong><small>DeepSeek 渠道新增 3 个缺口</small></span>${I('chevron-right')}</button>
                    </div>
                    <button class="gf-text-button" type="button" data-demo="查看全部待处理">查看全部待处理 ${I('arrow-right')}</button>
                </aside>
            </section>
            <section class="gf-panel gf-workflow-panel">
                <header class="gf-panel__header"><div><span class="gf-eyebrow">快速开始</span><h2>三步完成 GEO 内容流程</h2><p>能力配置、知识沉淀和任务生产统一连接。</p></div><span class="gf-badge gf-badge--success"><i></i>基础能力可用</span></header>
                <div class="gf-workflow-steps">
                    <article><span class="gf-step-number">1</span><div><h3>配置 AI 模型</h3><p>当前已接入 4 个聊天模型和 2 个 Embedding 模型。</p>${button('查看模型', 'arrow-right', 'secondary')}</div></article>
                    <article><span class="gf-step-number gf-step-number--green">2</span><div><h3>沉淀知识资产</h3><p>把业务资料整理为可检索、可引用的结构化知识。</p>${button('查看内容资产', 'arrow-right', 'secondary')}</div></article>
                    <article><span class="gf-step-number gf-step-number--dark">3</span><div><h3>创建生产任务</h3><p>组合问题、知识、模型和分发范围进入内容流程。</p>${button('新建任务', 'plus', 'primary')}</div></article>
                </div>
            </section>`;
    }

    const tasks = [
        ['品牌问题地图季度优化', 'GEO 内容', '企业知识库', 'GPT-5', '3 个站点', '每周一 09:00', '68%', '运行中', 'running'],
        ['智能客服场景专题生产', '专题内容', '产品资料库', 'DeepSeek V3', '企业官网', '每天 10:30', '42%', '运行中', 'running'],
        ['AI 搜索引用下降修复', '内容优化', '品牌证据库', '豆包 Pro', '2 个站点', '手动执行', '100%', '待审核', 'review'],
        ['GEOFlow 2.1 发布内容', '产品发布', '产品资料库', 'GPT-5', '4 个站点', '2026-08-25', '18%', '已暂停', 'paused'],
        ['行业术语知识补全', '知识维护', '行业知识库', 'DeepSeek V3', '不分发', '每月 1 日', '100%', '已完成', 'done'],
    ];

    function tasksPage() {
        return `${pageHeader('内容生产', '任务管理', '管理内容生产任务、执行节奏、模型配置和当前运行状态。', `${button('导出', 'download', 'secondary')}${button('新建任务', 'plus', 'primary')}`)}
            <section class="gf-task-summary" aria-label="任务摘要">
                <div><span>全部任务</span><strong>18</strong></div><div><span class="gf-status-dot gf-status-dot--running"></span><span>运行中</span><strong>7</strong></div><div><span class="gf-status-dot gf-status-dot--review"></span><span>待审核</span><strong>4</strong></div><div><span class="gf-status-dot gf-status-dot--done"></span><span>本月完成</span><strong>63</strong></div><div><span>队列健康</span><strong class="gf-text-success">正常</strong></div>
            </section>
            <section class="gf-panel gf-table-panel">
                <header class="gf-table-toolbar">
                    <div class="gf-search-field">${I('search')}<input type="search" placeholder="搜索任务名称" aria-label="搜索任务名称"></div>
                    <div class="gf-filter-actions"><button class="gf-filter-button" type="button" data-demo="任务状态筛选">状态：全部 ${I('chevron-down')}</button><button class="gf-filter-button" type="button" data-demo="任务类型筛选">任务类型 ${I('chevron-down')}</button><button class="gf-filter-button" type="button" data-demo="更多筛选">${I('list-filter')} 更多筛选</button></div>
                    <button class="gf-text-button" type="button" data-demo="刷新任务">${I('refresh-cw')} 刷新</button>
                </header>
                <div class="gf-batch-bar"><label><input type="checkbox" aria-label="选择全部任务"><span>选择全部</span></label><span>已选择 0 项</span><button type="button" data-demo="批量启动">批量启动</button><button type="button" data-demo="批量暂停">批量暂停</button></div>
                <div class="gf-table-scroll">
                    <table class="gf-table">
                        <thead><tr><th class="gf-col-check"></th><th>任务名称</th><th>任务类型</th><th>知识资产</th><th>模型</th><th>分发范围</th><th>执行计划</th><th>进度</th><th>状态</th><th class="gf-col-actions">操作</th></tr></thead>
                        <tbody>${tasks.map((task) => `<tr><td><input type="checkbox" aria-label="选择${task[0]}"></td><td><a href="#" data-demo="打开${task[0]}"><strong>${task[0]}</strong><small>更新于 ${task[8] === 'running' ? '8 分钟前' : '昨天'}</small></a></td><td>${task[1]}</td><td><span class="gf-table-chip">${task[2]}</span></td><td>${task[3]}</td><td>${task[4]}</td><td>${task[5]}</td><td><div class="gf-progress"><span style="width:${task[6]}"></span></div><small class="gf-progress-label">${task[6]}</small></td><td><span class="gf-status gf-status--${task[8]}"><i></i>${task[7]}</span></td><td><div class="gf-row-actions"><button type="button" aria-label="编辑${task[0]}" data-task-action="编辑">${I('pencil')}</button><button type="button" aria-label="运行${task[0]}" data-task-action="运行">${I('play')}</button><button type="button" aria-label="更多操作" data-task-action="更多操作">${I('ellipsis')}</button></div></td></tr>`).join('')}</tbody>
                    </table>
                </div>
                <footer class="gf-table-footer"><span>共 18 个任务，第 1 页</span><div><button type="button" disabled>${I('chevron-left')}</button><button type="button" class="is-current">1</button><button type="button">2</button><button type="button">3</button><button type="button">${I('chevron-right')}</button></div></footer>
            </section>
            <section class="gf-queue-strip">
                <div><span class="gf-queue-icon">${I('activity')}</span><span><strong>执行队列运行正常</strong><small>2 个 Worker 在线，当前等待 6 项任务</small></span></div>
                <dl><div><dt>执行中</dt><dd>3</dd></div><div><dt>等待中</dt><dd>6</dd></div><div><dt>今日失败</dt><dd class="gf-text-danger">1</dd></div><div><dt>平均耗时</dt><dd>4m 26s</dd></div></dl>
                <button class="gf-text-button" type="button" data-demo="查看运行记录">查看运行记录 ${I('arrow-right')}</button>
            </section>`;
    }

    const settingsTabs = [
        ['site', 'globe-2', '网站与品牌', '站点名称、域名与品牌信息'],
        ['homepage', 'layout-dashboard', '首页与主题', '首页模块、主题和预览'],
        ['leads', 'contact-round', '表单与线索', '表单配置和线索处理'],
        ['users', 'users', '用户与权限', '管理员、角色和 API Token'],
        ['security', 'shield-check', '安全与审计', '敏感词、登录和操作日志'],
        ['updates', 'refresh-cw', '系统更新', '版本、备份和更新记录'],
    ];

    function settingsNavigation() {
        return `<nav class="gf-settings-nav" role="tablist" aria-label="网站设置分区">${settingsTabs.map((tab, index) => `<button type="button" role="tab" class="${index === 0 ? 'is-active' : ''}" aria-selected="${index === 0}" data-settings-tab="${tab[0]}">${I(tab[1])}<span><strong>${tab[2]}</strong><small>${tab[3]}</small></span>${I('chevron-right')}</button>`).join('')}</nav>`;
    }

    function sitePanel() {
        return `<div class="gf-settings-panel" data-settings-panel="site">
            <header class="gf-settings-panel__header"><div><h2>网站与品牌</h2><p>这些信息会用于管理后台、公开网站和搜索结果。</p></div><span class="gf-save-state">${I('circle-check')} 已保存于 13:42</span></header>
            <form class="gf-settings-form" onsubmit="return false">
                <section class="gf-form-section"><div class="gf-form-section__intro"><h3>基础信息</h3><p>设置网站识别信息和默认访问地址。</p></div><div class="gf-form-section__fields"><label class="gf-field"><span>网站名称</span><input value="GEOFlow" required><small>显示在浏览器标题和管理后台。</small></label><label class="gf-field"><span>网站副标题</span><input value="面向 AI 搜索的内容运营系统"></label><label class="gf-field"><span>正式域名</span><div class="gf-input-prefix"><span>https://</span><input value="geoflow.example.com"></div></label><label class="gf-field"><span>默认语言</span><select><option>简体中文</option><option>English</option></select></label></div></section>
                <section class="gf-form-section"><div class="gf-form-section__intro"><h3>品牌标识</h3><p>统一公开网站和后台中的品牌表现。</p></div><div class="gf-form-section__fields"><div class="gf-logo-upload"><span class="gf-logo-preview">G</span><div><strong>网站标志</strong><small>建议上传透明 PNG 或 SVG，尺寸不小于 256px。</small><button type="button" data-demo="更换网站标志">更换标志</button></div></div><label class="gf-field"><span>品牌主色</span><div class="gf-color-input"><i></i><input value="#2563EB"></div></label></div></section>
                <section class="gf-form-section"><div class="gf-form-section__intro"><h3>搜索与分享</h3><p>设置搜索结果标题和默认分享摘要。</p></div><div class="gf-form-section__fields"><label class="gf-field"><span>默认 SEO 标题</span><input value="GEOFlow · GEO 内容运营系统"><small class="gf-counter">24 / 60</small></label><label class="gf-field"><span>默认描述</span><textarea rows="4">连接内容诊断、知识资产、内容生产、多站分发和增长观测。</textarea><small class="gf-counter">31 / 160</small></label></div></section>
                <footer class="gf-settings-actions"><span>修改仅应用于演示页面</span>${button('预览网站', 'external-link', 'secondary')}${button('保存更改', 'check', 'primary')}</footer>
            </form>
        </div>`;
    }

    function simpleSettingsPanel(id, title, description, rows) {
        return `<div class="gf-settings-panel" data-settings-panel="${id}" hidden><header class="gf-settings-panel__header"><div><h2>${title}</h2><p>${description}</p></div>${button('打开完整页面', 'arrow-up-right', 'secondary')}</header><div class="gf-settings-list">${rows.map((row) => `<button type="button" data-demo="打开${row[0]}"><span class="gf-settings-list__icon">${I(row[1])}</span><span><strong>${row[0]}</strong><small>${row[2]}</small></span>${I('chevron-right')}</button>`).join('')}</div></div>`;
    }

    function settingsPage() {
        return `${pageHeader('系统管理', '网站设置', '集中管理网站、线索、用户、安全与系统运行配置。', `${button('查看网站', 'external-link', 'secondary')}${button('保存更改', 'check', 'primary')}`)}
            <section class="gf-settings-shell">
                ${settingsNavigation()}
                <div class="gf-settings-workspace">
                    ${sitePanel()}
                    ${simpleSettingsPanel('homepage', '首页与主题', '管理公开网站的首页结构、当前主题和主题复制任务。', [['首页模块', 'layout-dashboard', '调整模块顺序、内容和展示状态'], ['当前主题', 'palette', 'GEOFlow Default，版本 2.1'], ['主题复制', 'copy-check', '2 个任务已完成，1 个等待确认']])}
                    ${simpleSettingsPanel('leads', '表单与线索', '配置线索收集表单并查看提交和跟进状态。', [['线索表单', 'clipboard-list', '3 个启用中的表单'], ['线索记录', 'contact-round', '本周新增 17 条，5 条待处理']])}
                    ${simpleSettingsPanel('users', '用户与权限', '用户管理已经合并到网站设置，统一处理账户、权限和访问凭证。', [['管理员账户', 'users', '4 个账户，3 个正常'], ['角色与权限', 'key-round', '超级管理员与管理员两类角色'], ['API Token', 'braces', '2 个有效 Token，最近使用于昨天']])}
                    ${simpleSettingsPanel('security', '安全与审计', '查看内容安全、登录保护和重要操作记录。', [['敏感词', 'shield-alert', '128 个规则，今天拦截 3 次'], ['登录安全', 'lock-keyhole', '失败锁定和会话策略已启用'], ['操作日志', 'scroll-text', '记录管理员关键操作']])}
                    ${simpleSettingsPanel('updates', '系统更新', '检查版本、创建备份并查看更新执行记录。', [['版本状态', 'badge-check', '当前版本 2.0，可升级到 2.1'], ['备份记录', 'archive', '最近备份于今天 03:00'], ['更新任务', 'history', '最近 3 次更新均完成']])}
                </div>
                <aside class="gf-settings-aside"><div class="gf-system-status"><span>${I('circle-check-big')}</span><div><strong>系统运行正常</strong><small>配置检查于 2 分钟前完成</small></div></div><dl><div><dt>当前版本</dt><dd>2.0</dd></div><div><dt>站点状态</dt><dd class="gf-text-success">已发布</dd></div><div><dt>管理员</dt><dd>4</dd></div><div><dt>最后备份</dt><dd>今天 03:00</dd></div></dl><button class="gf-text-button" type="button" data-demo="打开系统检查">运行系统检查 ${I('arrow-right')}</button></aside>
            </section>`;
    }

    window.GeoFlowPilotPages = {
        dashboard,
        tasks: tasksPage,
        'site-settings': settingsPage,
    };
}());
