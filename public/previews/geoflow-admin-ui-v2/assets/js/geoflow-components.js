(function () {
    'use strict';

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const icon = (name, className = '') => `<i data-lucide="${escapeHtml(name)}"${className ? ` class="${escapeHtml(className)}"` : ''}></i>`;

    const statusSequence = [
        ['运行中', 'working'],
        ['已完成', 'success'],
        ['待确认', 'warning'],
        ['需要关注', 'danger'],
        ['草稿', 'neutral'],
    ];

    const groupDefaults = {
        workspace: ['内容任务', '知识资产', '今日分发', 'AI 可见性'],
        analytics: ['核心指标', '环比增长', '目标完成率', '待优化项'],
        content: ['全部记录', '处理中', '已完成', '需要关注'],
        distribution: ['目标渠道', '运行任务', '成功率', '失败重试'],
        materials: ['资产总量', '本周新增', '本月使用', '待处理'],
        'ai-config': ['可用模型', '服务商', '提示词', '异常配置'],
        site: ['有效配置', '本周提交', '页面使用', '待跟进'],
        system: ['正常项目', '待处理', '本月操作', '安全提醒'],
    };

    const metricValues = {
        workspace: ['18', '1,286', '36', '72.4%'],
        analytics: ['12,680', '+18.6%', '86.2%', '5'],
        content: ['126', '18', '96', '12'],
        distribution: ['5', '12', '96.8%', '3'],
        materials: ['1,286', '86', '326', '18'],
        'ai-config': ['6', '4', '28', '1'],
        site: ['24', '86', '12', '18'],
        system: ['18', '3', '326', '2'],
    };

    function button(label, iconName, variant = 'secondary', attrs = '', buttonType = 'button') {
        const variantClass = variant === 'primary'
            ? ' gf-button--primary'
            : variant === 'danger'
                ? ' gf-button--danger'
                : variant === 'quiet'
                    ? ' gf-button--quiet'
                    : '';
        return `<button type="${escapeHtml(buttonType)}" class="gf-button${variantClass}" ${attrs}>${icon(iconName)}<span>${escapeHtml(label)}</span></button>`;
    }

    function status(label, tone) {
        return `<span class="gf-status gf-status--${escapeHtml(tone)}">${escapeHtml(label)}</span>`;
    }

    function badge(label, tone = 'blue') {
        return `<span class="gf-badge gf-badge--${escapeHtml(tone)}">${escapeHtml(label)}</span>`;
    }

    function pageHeader(page) {
        const actionVariant = page.actionTone === 'danger' ? 'danger' : 'primary';
        const formId = page.type === 'form'
            ? `gf-form-${page.id}`
            : page.type === 'wizard'
                ? `gf-wizard-${page.id}`
                : '';
        const primaryAction = page.type === 'error'
            ? `<a class="gf-button gf-button--primary" href="../../pages/workspace/dashboard.html">${icon(page.actionIcon || 'home')}<span>${escapeHtml(page.action || '返回首页')}</span></a>`
            : formId
                ? button(page.action || '保存', page.actionIcon || 'save', actionVariant, `form="${escapeHtml(formId)}"`, 'submit')
                : page.action
                    ? button(page.action, page.actionIcon || 'plus', actionVariant, `data-demo-action="${escapeHtml(page.action)}仅用于原型演示"`)
                    : '';
        return `<header class="gf-page-header">
            <div class="gf-page-header__copy">
                <h1 class="gf-page-title">${escapeHtml(page.title)}</h1>
                <p class="gf-page-subtitle">${escapeHtml(page.subtitle)}</p>
            </div>
            <div class="gf-page-header__actions">
                ${button('刷新', 'refresh-cw', 'secondary', 'data-demo-action="已刷新演示数据"')}
                ${primaryAction}
            </div>
        </header>`;
    }

    function metrics(page) {
        const labels = groupDefaults[page.group] || groupDefaults.workspace;
        const values = metricValues[page.group] || metricValues.workspace;
        const icons = ['layers-3', 'trending-up', 'circle-check-big', 'triangle-alert'];
        return `<section class="gf-metrics" aria-label="核心指标">
            ${labels.map((label, index) => `<article class="gf-metric">
                <div class="gf-metric__head"><span>${escapeHtml(label)}</span><span class="gf-metric__icon">${icon(icons[index])}</span></div>
                <div class="gf-metric__value">${escapeHtml(values[index])}</div>
                <div class="gf-metric__trend${index === 3 ? ' gf-metric__trend--down' : ''}">${index === 3 ? '较昨日减少 2 项' : '较上期提升 12.6%'}</div>
            </article>`).join('')}
        </section>`;
    }

    function pagination(total = 126) {
        return `<div class="gf-pagination">
            <span>共 ${total} 条演示记录</span>
            <div class="gf-pagination__pages">
                <button class="gf-page-button" type="button" aria-label="上一页" data-page-step="previous">${icon('chevron-left')}</button>
                <button class="gf-page-button is-current" type="button" aria-label="第 1 页" aria-current="page" data-page-number="1">1</button>
                <button class="gf-page-button" type="button" aria-label="第 2 页" data-page-number="2">2</button>
                <button class="gf-page-button" type="button" aria-label="第 3 页" data-page-number="3">3</button>
                <button class="gf-page-button" type="button" aria-label="下一页" data-page-step="next">${icon('chevron-right')}</button>
            </div>
        </div>`;
    }

    function filterbar(page) {
        return `<div class="gf-filterbar">
            <label class="gf-search">
                <span class="gf-sr-only">搜索${escapeHtml(page.entity || page.title)}</span>
                ${icon('search')}
                <input class="gf-input" type="search" placeholder="搜索${escapeHtml(page.entity || page.title)}">
            </label>
            <select class="gf-select gf-filter-select" aria-label="状态筛选"><option>全部状态</option><option>运行中</option><option>已完成</option><option>需要关注</option></select>
            <select class="gf-select gf-filter-select" aria-label="时间筛选"><option>最近 30 天</option><option>最近 7 天</option><option>今天</option></select>
            <span class="gf-filterbar__spacer"></span>
            ${button('批量操作', 'list-checks', 'secondary', 'data-demo-action="批量操作仅用于演示"')}
        </div>`;
    }

    function table(page) {
        const names = page.keywords || [page.title];
        const rows = Array.from({ length: 5 }, (_, index) => {
            const item = names[index % names.length];
            const [statusLabel, statusTone] = statusSequence[index % statusSequence.length];
            const ids = ['GF-20260803-018', 'GF-20260802-126', 'GF-20260801-086', 'GF-20260731-042', 'GF-20260730-018'];
            return `<tr>
                <td><div class="gf-table__primary">${escapeHtml(item)}</div><div class="gf-table__secondary">${ids[index]}</div></td>
                <td>${status(statusLabel, statusTone)}</td>
                <td>${['姚金刚', '王晓明', '陈思远', 'GEOFlow AI', '系统任务'][index]}</td>
                <td>${['2 分钟前', '1 小时前', '昨天 18:20', '2026-08-01', '2026-07-30'][index]}</td>
                <td><div class="gf-table__actions"><button class="gf-icon-button" type="button" aria-label="查看${escapeHtml(item)}" data-demo-action="打开${escapeHtml(item)}详情">${icon('ellipsis')}</button></div></td>
            </tr>`;
        }).join('');

        return `<section class="gf-card">
            ${filterbar(page)}
            <div class="gf-table-wrap">
                <table class="gf-table">
                    <thead><tr><th>${escapeHtml(page.entity || '名称')}</th><th>状态</th><th>负责人</th><th>更新时间</th><th aria-label="操作"></th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <footer class="gf-card__footer">${pagination(126)}</footer>
        </section>`;
    }

    function form(page) {
        const labels = page.keywords || ['名称', '类型', '配置', '范围', '说明'];
        const formId = `gf-form-${page.id}`;
        const fieldMarkup = labels.slice(0, 5).map((label, index) => {
            const full = index === 4 ? ' gf-field--full' : '';
            const fieldId = `${formId}-field-${index}`;
            if (index === 4) {
                return `<div class="gf-field${full}"><label class="gf-label" for="${escapeHtml(fieldId)}">${escapeHtml(label)}</label><textarea class="gf-textarea" id="${escapeHtml(fieldId)}" placeholder="补充${escapeHtml(label)}相关说明">演示内容仅用于界面评审，可在页面数据文件中直接修改。</textarea><p class="gf-help">建议填写真实、可验证并适合后续审核的信息。</p></div>`;
            }
            if (index === 1 || index === 3) {
                return `<div class="gf-field${full}"><label class="gf-label" for="${escapeHtml(fieldId)}">${escapeHtml(label)}${index === 1 ? '<span class="gf-label__required" aria-hidden="true">*</span>' : ''}</label><select class="gf-select" id="${escapeHtml(fieldId)}"${index === 1 ? ' required' : ''}><option>${escapeHtml(label)}默认选项</option><option>${escapeHtml(label)}备选一</option><option>${escapeHtml(label)}备选二</option></select><p class="gf-help">当前为演示选项，不会写入后台。</p></div>`;
            }
            return `<div class="gf-field${full}"><label class="gf-label" for="${escapeHtml(fieldId)}">${escapeHtml(label)}${index === 0 ? '<span class="gf-label__required" aria-hidden="true">*</span>' : ''}</label><input class="gf-input" id="${escapeHtml(fieldId)}" value="${escapeHtml(index === 0 ? `${page.entity || page.title}演示名称` : `${label}演示值`)}"${index === 0 ? ' required' : ''}><p class="gf-help">用于展示 GEOFlow 标准表单字段。</p></div>`;
        }).join('');

        return `<div class="gf-grid gf-grid--main-side">
            <section class="gf-card">
                <div class="gf-card__header"><div><p class="gf-card__eyebrow">基本信息</p><h2 class="gf-card__title">${escapeHtml(page.entity || page.title)}配置</h2><p class="gf-card__subtitle">填写必需信息并确认演示配置。</p></div>${badge('演示数据', 'blue')}</div>
                <div class="gf-card__body"><form class="gf-field-grid" id="${escapeHtml(formId)}" data-demo-form data-success-message="${escapeHtml(`${page.action || '保存'}已通过本地校验`)}">${fieldMarkup}</form></div>
                <footer class="gf-card__footer"><span class="gf-help">所有输入只保留在当前页面内存中。</span><div class="gf-form-actions">${button('取消', 'x', 'secondary', 'data-demo-action="已取消演示修改"')}${button(page.action || '保存', page.actionIcon || 'save', page.actionTone === 'danger' ? 'danger' : 'primary', `form="${escapeHtml(formId)}"`, 'submit')}</div></footer>
            </section>
            <aside class="gf-card">
                <div class="gf-card__header"><div><h2 class="gf-card__title">提交摘要</h2><p class="gf-card__subtitle">保存前快速确认本次配置。</p></div></div>
                <div class="gf-card__body"><div class="gf-summary-list">
                    ${labels.slice(0, 5).map((label, index) => `<div class="gf-summary-row"><span class="gf-summary-row__label">${escapeHtml(label)}</span><span class="gf-summary-row__value">${index === 0 ? '已填写' : index === 4 ? '待确认' : '默认配置'}</span></div>`).join('')}
                </div><div class="gf-callout gf-mt-20">${icon('info')}<div>原型不会提交数据，操作按钮用于演示反馈。</div></div></div>
            </aside>
        </div>`;
    }

    function detail(page) {
        const values = page.keywords || [page.title];
        const tabId = (name) => `${page.id}-tab-${name}`;
        const panelId = (name) => `${page.id}-panel-${name}`;
        return `${metrics(page)}
        <div class="gf-grid gf-grid--main-side">
            <section class="gf-card">
                <div class="gf-tabs" role="tablist" aria-label="详情内容" data-tabs><button class="gf-tab is-active" id="${escapeHtml(tabId('overview'))}" type="button" role="tab" aria-selected="true" aria-controls="${escapeHtml(panelId('overview'))}" data-tab-target="${escapeHtml(panelId('overview'))}">概览</button><button class="gf-tab" id="${escapeHtml(tabId('related'))}" type="button" role="tab" aria-selected="false" aria-controls="${escapeHtml(panelId('related'))}" tabindex="-1" data-tab-target="${escapeHtml(panelId('related'))}">关联内容</button><button class="gf-tab" id="${escapeHtml(tabId('history'))}" type="button" role="tab" aria-selected="false" aria-controls="${escapeHtml(panelId('history'))}" tabindex="-1" data-tab-target="${escapeHtml(panelId('history'))}">操作记录</button></div>
                <div class="gf-card__body gf-tab-panel" id="${escapeHtml(panelId('overview'))}" role="tabpanel" aria-labelledby="${escapeHtml(tabId('overview'))}" data-tab-panel><div class="gf-detail-list">
                    ${values.slice(0, 6).map((value, index) => `<div class="gf-detail-item"><div class="gf-detail-item__label">${['名称', '当前状态', '核心指标', '关联范围', '最近更新', '负责人'][index] || `字段 ${index + 1}`}</div><div class="gf-detail-item__value">${escapeHtml(value)}</div></div>`).join('')}
                </div></div>
                <div class="gf-card__body gf-tab-panel" id="${escapeHtml(panelId('related'))}" role="tabpanel" aria-labelledby="${escapeHtml(tabId('related'))}" data-tab-panel hidden><div class="gf-insight-list">${values.slice(0, 3).map((value) => `<div class="gf-insight">${icon('link')}<p><strong>${escapeHtml(value)}</strong><br>该演示记录与当前对象保持关联。</p></div>`).join('')}</div></div>
                <div class="gf-card__body gf-tab-panel" id="${escapeHtml(panelId('history'))}" role="tabpanel" aria-labelledby="${escapeHtml(tabId('history'))}" data-tab-panel hidden><div class="gf-timeline">${['配置已更新', '状态检查完成', '关联数据已同步'].map((item, index) => `<div class="gf-timeline__item"><span class="gf-timeline__dot"></span><div><div class="gf-timeline__title">${item}</div><div class="gf-timeline__meta">${['2 分钟前', '1 小时前', '昨天 18:20'][index]}</div></div></div>`).join('')}</div></div>
            </section>
            <aside class="gf-card">
                <div class="gf-card__header"><div><h2 class="gf-card__title">近期动态</h2><p class="gf-card__subtitle">演示操作轨迹</p></div></div>
                <div class="gf-card__body"><div class="gf-timeline">
                    ${['配置已更新', '状态检查完成', '关联数据已同步', '记录创建'].map((item, index) => `<div class="gf-timeline__item"><span class="gf-timeline__dot"></span><div><div class="gf-timeline__title">${item}</div><div class="gf-timeline__meta">${['2 分钟前', '1 小时前', '昨天 18:20', '2026-08-01'][index]} · ${['姚金刚', 'GEOFlow AI', '王晓明', '系统'][index]}</div></div></div>`).join('')}
                </div></div>
            </aside>
        </div>`;
    }

    function cards(page) {
        const items = page.keywords || [page.title];
        const icons = ['library', 'building-2', 'heading-1', 'tags', 'images', 'users', 'folder-tree', 'link'];
        return `<section class="gf-card">
            ${filterbar(page)}
            <div class="gf-card__body"><div class="gf-grid gf-grid--4">
                ${items.map((item, index) => `<button class="gf-resource-card gf-resource-card--button" type="button" data-demo-action="打开${escapeHtml(item)}">
                    <div class="gf-resource-card__head"><span class="gf-resource-card__icon">${icon(icons[index % icons.length])}</span>${status(index % 3 === 0 ? '使用中' : '可用', index % 3 === 0 ? 'working' : 'success')}</div>
                    <h3>${escapeHtml(item)}</h3><p>${escapeHtml(item)}包含可复用业务资料和演示配置，可进入详情继续查看。</p>
                    <div class="gf-resource-card__footer"><span>${18 + index * 12} 项内容</span><span>今天更新</span></div>
                </button>`).join('')}
            </div></div>
        </section>`;
    }

    function chart(page) {
        const labels = ['8/01', '8/02', '8/03', '8/04', '8/05', '8/06', '8/07'];
        const heights = [42, 58, 50, 74, 68, 86, 78];
        return `<div class="gf-chart" role="img" aria-label="${escapeHtml(page.title)}最近 7 天趋势演示图，数据总体上升，8 月 6 日达到最高点">
            <span class="gf-sr-only">8 月 1 日到 8 月 7 日的数据依次为 42、58、50、74、68、86 和 78。</span>
            <div class="gf-chart__plot"><div class="gf-chart__grid"><span></span><span></span><span></span></div>
                ${labels.map((label, index) => `<div class="gf-chart__bar-wrap"><span class="gf-chart__bar gf-chart__bar--${heights[index]}"></span><span class="gf-chart__label">${label}</span></div>`).join('')}
            </div>
        </div>`;
    }

    function analytics(page) {
        return `${metrics(page)}
        <div class="gf-grid gf-grid--main-side">
            <section class="gf-card"><div class="gf-card__header"><div><p class="gf-card__eyebrow">趋势变化</p><h2 class="gf-card__title">${escapeHtml(page.keywords?.[0] || page.title)}趋势</h2><p class="gf-card__subtitle">最近 7 天演示数据</p></div><select class="gf-select gf-filter-select" aria-label="指标"><option>按日</option><option>按周</option></select></div><div class="gf-card__body">${chart(page)}</div></section>
            <aside class="gf-card"><div class="gf-card__header"><div><h2 class="gf-card__title">AI 洞察</h2><p class="gf-card__subtitle">根据当前演示数据生成</p></div>${badge('3 条建议', 'violet')}</div><div class="gf-card__body"><div class="gf-insight-list">
                ${(page.keywords || []).slice(0, 3).map((item, index) => `<div class="gf-insight">${icon('sparkles')}<p><strong>${escapeHtml(item)}</strong>${['较上期提升 18.6%，建议继续保持当前内容节奏。', '出现新的增长机会，可补充证据和来源覆盖。', '有 5 项需要关注，建议先创建优化任务。'][index]}</p></div>`).join('')}
            </div></div></aside>
        </div>
        <section class="gf-card gf-card--spaced"><div class="gf-card__header"><div><h2 class="gf-card__title">重点对象</h2><p class="gf-card__subtitle">按当前指标排序的演示记录</p></div></div>${table(page).replace(/^<section class="gf-card">|<\/section>$/g, '')}</section>`;
    }

    function dashboard(page) {
        const steps = [
            ['配置 API', 'plug-zap', '添加至少一个聊天模型，并按需配置 Embedding 模型。'],
            ['沉淀知识资产', 'database', '把真实可靠的业务资料整理成可复用资产。'],
            ['新建任务', 'workflow', '组合问题、资产、模型和分发范围进入内容流程。'],
        ];
        const flow = [
            ['知识资产', 'database'], ['任务化生产', 'workflow'], ['质量门禁', 'shield-check'], ['权威分发', 'radio-tower'], ['观测归因', 'chart-no-axes-combined'],
        ];
        return `${metrics(page)}<section class="gf-card"><div class="gf-card__header"><div><p class="gf-card__eyebrow">快速开始</p><h2 class="gf-card__title">三步演示 GEO 内容流程</h2><p class="gf-card__subtitle">先接入模型能力，再沉淀知识资产，最后创建任务并进入审核、分发与观测链路。</p></div>${badge('基础能力可用', 'green')}</div><div class="gf-grid gf-grid--3 gf-grid--flush">
            ${steps.map((step, index) => `<article class="gf-card__body gf-dashboard-step"><div class="gf-dashboard-step__layout"><span class="gf-resource-card__icon">${icon(step[1])}</span><div><h3 class="gf-dashboard-step__title">${index + 1}. ${step[0]}</h3><p class="gf-card__subtitle">${step[2]}</p>${button(index === 0 ? '配置 AI 模型' : index === 1 ? '查看内容资产' : '新建任务', 'arrow-right', index === 0 ? 'primary' : 'secondary', 'data-demo-action="打开演示步骤"')}</div></div></article>`).join('')}
        </div></section>
        <section class="gf-card gf-card--spaced"><div class="gf-card__header"><div><p class="gf-card__eyebrow">现场演示路径</p><h2 class="gf-card__title">五分钟讲清 GEO 内容流程</h2><p class="gf-card__subtitle">把抽象概念落到可执行的后台操作。</p></div>${badge('讲解动线', 'blue')}</div><div class="gf-grid gf-grid--4 gf-card__body">
            ${flow.map((item, index) => `<article class="gf-resource-card"><div class="gf-resource-card__head"><span class="gf-resource-card__icon">${icon(item[1])}</span><span class="gf-badge">0${index + 1}</span></div><h3>${item[0]}</h3><p>${['组织真实业务资料和内容生产资产。', '把问题与资产组合成可执行任务。', '检查结构、证据和风险表达。', '同步合格内容到目标站点。', '用访问和 AI 信号解释效果。'][index]}</p><div class="gf-resource-card__footer"><span>进入模块</span>${icon('arrow-right')}</div></article>`).join('')}
        </div></section>`;
    }

    function wizard(page) {
        const labels = ['设置输入', '确认配置', '执行处理', '查看结果'];
        const formId = `gf-wizard-${page.id}`;
        return `<div class="gf-stepper">${labels.map((label, index) => `<div class="gf-step${index === 0 ? ' is-active' : ''}"><span class="gf-step__number">${index + 1}</span><span>${label}</span></div>`).join('')}</div>
        <div class="gf-grid gf-grid--main-side"><section class="gf-card"><div class="gf-card__header"><div><p class="gf-card__eyebrow">步骤 1</p><h2 class="gf-card__title">${escapeHtml(page.keywords?.[0] || '设置输入')}</h2><p class="gf-card__subtitle">完成当前步骤后进入预览确认。</p></div>${badge('演示流程', 'blue')}</div><div class="gf-card__body"><div class="gf-field-grid">
            <form class="gf-field-grid" id="${escapeHtml(formId)}" data-demo-form data-success-message="已进入下一步演示">${(page.keywords || []).slice(0, 5).map((label, index) => { const fieldId = `${formId}-field-${index}`; return `<div class="gf-field${index === 4 ? ' gf-field--full' : ''}"><label class="gf-label" for="${escapeHtml(fieldId)}">${escapeHtml(label)}${index === 0 ? '<span class="gf-label__required" aria-hidden="true">*</span>' : ''}</label>${index === 4 ? `<textarea class="gf-textarea" id="${escapeHtml(fieldId)}">补充本次处理要求，执行前仍会进入确认页面。</textarea>` : `<input class="gf-input" id="${escapeHtml(fieldId)}" value="${escapeHtml(label)}演示值"${index === 0 ? ' required' : ''}>`}<p class="gf-help">此字段只用于原型交互。</p></div>`; }).join('')}</form>
        </div></div><footer class="gf-card__footer"><span class="gf-help">下一步将展示影响范围。</span>${button(page.action || '继续', page.actionIcon || 'arrow-right', 'primary', `form="${escapeHtml(formId)}"`, 'submit')}</footer></section>
        <aside class="gf-card"><div class="gf-card__header"><div><h2 class="gf-card__title">安全检查</h2><p class="gf-card__subtitle">处理前自动验证</p></div></div><div class="gf-card__body"><div class="gf-summary-list">${['来源地址有效', '目标范围明确', '未发现敏感信息', '支持取消和重试'].map((item) => `<div class="gf-summary-row"><span class="gf-summary-row__label">${item}</span><span class="gf-summary-row__value gf-color-success">通过</span></div>`).join('')}</div></div></aside></div>`;
    }

    function settings(page) {
        const tabs = (page.keywords || ['基础设置', '高级设置', '安全设置']).slice(0, 5);
        return `<section class="gf-card"><div class="gf-tabs" aria-label="设置分类">${tabs.map((tab, index) => `<button type="button" class="gf-tab${index === 0 ? ' is-active' : ''}" aria-pressed="${index === 0 ? 'true' : 'false'}" data-visual-tab>${escapeHtml(tab)}</button>`).join('')}</div><div class="gf-card__body"><div class="gf-grid gf-grid--2">
            ${tabs.slice(0, 4).map((item, index) => `<div class="gf-field"><label class="gf-label" for="setting-${index}">${escapeHtml(item)}</label>${index % 2 === 0 ? `<input id="setting-${index}" class="gf-input" value="${escapeHtml(item)}演示配置">` : `<select id="setting-${index}" class="gf-select"><option>已启用</option><option>已停用</option></select>`}<p class="gf-help">配置修改只在当前原型页面显示。</p></div>`).join('')}
        </div><div class="gf-callout gf-mt-24">${icon('info')}<div><strong>原型提示</strong><br>保存操作不会调用真实接口，也不会修改系统设置。</div></div></div><footer class="gf-card__footer"><span class="gf-help">最近保存：今天 10:20</span><div class="gf-form-actions">${button('恢复默认', 'rotate-ccw', 'secondary', 'data-demo-action="已恢复演示默认值"')}${button(page.action || '保存设置', page.actionIcon || 'save', 'primary', 'data-demo-action="演示设置已保存"')}</div></footer></section>
        <section class="gf-card gf-card--spaced"><div class="gf-card__header"><div><h2 class="gf-card__title">配置记录</h2><p class="gf-card__subtitle">最近 3 次演示变更</p></div></div><div class="gf-card__body"><div class="gf-timeline">${['更新基础设置', '完成配置检查', '创建当前配置'].map((item, index) => `<div class="gf-timeline__item"><span class="gf-timeline__dot"></span><div><div class="gf-timeline__title">${item}</div><div class="gf-timeline__meta">${['2 分钟前', '1 小时前', '2026-08-01'][index]} · ${['姚金刚', 'GEOFlow AI', '系统'][index]}</div></div></div>`).join('')}</div></div></section>`;
    }

    function review(page) {
        const danger = page.actionTone === 'danger';
        return `<div class="gf-callout ${danger ? 'gf-callout--danger' : 'gf-callout--warning'}">${icon(danger ? 'triangle-alert' : 'info')}<div><strong>${danger ? '高风险操作确认' : '执行前检查'}</strong><br>${escapeHtml(page.subtitle)}</div></div>
        <div class="gf-grid gf-grid--2 gf-grid--spaced"><section class="gf-card"><div class="gf-card__header"><div><p class="gf-card__eyebrow">当前状态</p><h2 class="gf-card__title">执行对象</h2></div>${status(danger ? '等待删除确认' : '等待同步确认', danger ? 'danger' : 'warning')}</div><div class="gf-card__body"><div class="gf-summary-list">${(page.keywords || []).map((item, index) => `<div class="gf-summary-row"><span class="gf-summary-row__label">${['目标', '关联范围', '变化项目', '恢复能力', '预计耗时'][index] || `项目 ${index + 1}`}</span><span class="gf-summary-row__value">${escapeHtml(item)}</span></div>`).join('')}</div></div></section>
        <section class="gf-card"><div class="gf-card__header"><div><p class="gf-card__eyebrow">影响检查</p><h2 class="gf-card__title">执行后的变化</h2></div></div><div class="gf-card__body"><div class="gf-insight-list">${['保留当前操作日志和审核记录', '执行前再次校验目标与权限', danger ? '关联内容不会自动删除' : '只同步存在差异的配置', '失败后可重新检查并执行'].map((item) => `<div class="gf-insight">${icon('shield-check')}<p>${item}</p></div>`).join('')}</div></div></section></div>
        <section class="gf-card gf-card--spaced"><div class="gf-card__footer"><span class="gf-help">确认前请核对目标和影响范围。</span><div class="gf-form-actions">${button('返回修改', 'arrow-left', 'secondary', 'data-demo-action="返回修改演示"')}${button(page.action || '确认执行', page.actionIcon || 'check', danger ? 'danger' : 'primary', `data-demo-action="${escapeHtml(page.action || '确认执行')}仅用于演示"`)}</div></div></section>`;
    }

    function aiWorkspace(page) {
        const chips = [
            ['scan-search', '内容诊断'], ['workflow', '创建任务'], ['database', '知识资产'], ['radio-tower', '多站分发'], ['ellipsis', '更多'],
        ];
        const demoPrompt = '请分析 GEOFlow 官网近 30 天在 ChatGPT、豆包和 DeepSeek 中的品牌可见性，找出引用下降的内容，生成优化任务，并安排审核后同步到 3 个站点。';
        const reasoningSteps = [
            ['scan-search', '理解用户意图', '识别目标、范围和交付物，确认需要完成可见性诊断与任务安排。', '意图识别'],
            ['sparkles', '调用 GEOFlow 专用 Skill', '加载 GEO 策略、内容诊断和多站分发能力，建立本次执行边界。', '3 个 Skill'],
            ['database', '查找相关数据与信息', '读取近 30 天 AI 可见性记录、官网内容资产和目标站点状态。', '86 条样本'],
            ['chart-spline', '分析引用下降原因', '比较 ChatGPT、豆包和 DeepSeek 的提及率与引用页面变化。', '多源分析'],
            ['workflow', '编排可执行任务', '将诊断结果拆解为内容优化、质量审核和分发任务。', '4 个任务'],
            ['list-checks', '梳理结果与确认项', '汇总工作量、风险门禁和需要用户确认的执行动作。', '等待确认'],
        ];
        const planSteps = [
            ['诊断可见性', '比较三个 AI 来源的提及率、引用页面和问题覆盖，定位下降原因。', '数据已就绪', 'ready'],
            ['优化重点内容', '为 6 篇优先内容补充问题回答、证据来源和结构化摘要。', '等待创建', 'pending'],
            ['审核并分发', '通过事实、风险和格式门禁后，同步到 3 个已连接站点。', '需要确认', 'waiting'],
            ['持续观测', '发布后观察 7 天访问和 AI 引用变化，生成效果回看。', '自动安排', 'ready'],
        ];
        return `<div class="gf-ai-landing" data-ai-landing>
            <section class="gf-ai-hero"><h1>${escapeHtml(page.title)}</h1><p>${escapeHtml(page.subtitle)}</p></section>
            <section class="gf-composer"><textarea id="gf-ai-prompt" rows="3" data-ai-input="landing" aria-label="告诉 GEOFlow 你想完成的工作" placeholder="例如：分析近 30 天 AI 爬虫访问与品牌可见性，找出优先优化的内容并创建任务">${escapeHtml(demoPrompt)}</textarea><div class="gf-composer__toolbar"><div class="gf-composer__group"><button type="button" class="gf-composer-button gf-composer-button--icon" aria-label="添加上下文" data-demo-action="打开资料选择演示">${icon('plus')}</button><button type="button" class="gf-composer-button gf-composer-button--icon" aria-label="AI 智能拆解" data-demo-action="AI 智能拆解演示">${icon('sparkles')}</button><button type="button" class="gf-composer-button gf-composer-button--text-mobile" aria-pressed="false" data-ai-mode>${icon('chart-spline')}增长分析</button><button type="button" class="gf-composer-button is-active gf-composer-button--text-mobile" aria-pressed="true" data-ai-mode>${icon('workflow')}创建任务</button></div><div class="gf-composer__group"><span class="gf-composer__hint">Enter 发送</span><button type="button" class="gf-composer-button gf-composer-button--icon" aria-label="语音输入" data-demo-action="语音输入演示">${icon('mic')}</button><button type="button" class="gf-composer-button gf-composer-button--icon gf-composer-button--send" aria-label="开始分析并进入任务会话" data-ai-send="landing">${icon('arrow-up')}</button></div></div></section>
            <div class="gf-ai-shortcuts-head"><span>您想做什么？</span><div class="gf-ai-shortcuts-head__actions"><button class="gf-ai-link" type="button" data-demo-action="上传资料演示">${icon('upload')}<span>上传资料</span></button><button class="gf-ai-link" type="button" data-demo-action="模板选择演示">${icon('file-text')}<span>从模板选择</span></button></div></div>
            <div class="gf-chip-row">${chips.map((item, index) => `<button type="button" class="gf-chip${index === 0 ? ' is-active' : ''}" data-ai-chip="${escapeHtml(item[1])}">${icon(item[0])}${escapeHtml(item[1])}</button>`).join('')}</div>
            <section class="gf-focus-card"><div class="gf-focus-card__body"><div class="gf-focus-card__title">GEO 工作一站直达 ${icon('chevron-right')}</div><div class="gf-focus-card__desc">内容、资产、任务、分发与增长观测，在一个工作台完成</div><div class="gf-mini-tags">${['问题地图', '知识资产', '任务编排', '质量门禁', 'AI 可见性', '多站分发', '增长分析', '数据导出'].map((item) => `<span class="gf-mini-tag">${item}</span>`).join('')}</div></div></section>
            <section class="gf-focus-card gf-focus-card--tight"><div class="gf-card__header"><div class="gf-focus-card__title">${icon('book-open')}运营日报 <span class="gf-focus-card__desc">· 今天 · 2026年8月4日</span></div><button type="button" class="gf-ai-link" data-demo-action="查看往期日报">${icon('history')}往期</button></div><div class="gf-card__body"><div class="gf-grid gf-grid--2"><div><p class="gf-card__eyebrow gf-color-muted">今日动态</p><div class="gf-insight-list gf-mt-12"><div class="gf-insight">${icon('workflow')}<p>创建任务「品牌问题地图优化」 · 8 篇内容</p></div><div class="gf-insight">${icon('shield-check')}<p>质量审核「企业知识库专题」 · 通过 5/6</p></div><div class="gf-insight">${icon('radio-tower')}<p>今日完成多站分发 12 篇 · 成功率 96%</p></div></div></div><div><p class="gf-card__eyebrow gf-color-muted">AI 建议</p><div class="gf-insight-list gf-mt-12"><div class="gf-insight">${icon('sparkles')}<p>优先处理 3 篇高访问、低引用内容。</p></div><div class="gf-insight">${icon('sparkles')}<p>更新 18 个待向量化知识切片。</p></div><div class="gf-insight">${icon('sparkles')}<p>2 个目标站点响应偏慢，建议错峰分发。</p></div></div></div></div></div></section>
        </div>
        <section class="gf-ai-chat" aria-labelledby="gf-ai-chat-title" data-ai-conversation hidden>
            <header class="gf-ai-chat__header"><div class="gf-ai-chat__header-inner"><button type="button" class="gf-icon-button" aria-label="返回 AI 工作台" data-ai-new-chat>${icon('arrow-left')}</button><div class="gf-ai-chat__title"><span>AI Agent 任务</span><h2 id="gf-ai-chat-title">品牌可见性优化任务</h2></div><div class="gf-ai-chat__actions"><span class="gf-ai-chat__status" data-ai-chat-status><i></i><span>正在工作</span></span><button type="button" class="gf-button gf-button--small" data-ai-replay>${icon('refresh-cw')}<span>重新运行</span></button></div></div></header>
            <div class="gf-ai-chat__messages">
            <div class="gf-ai-turn gf-ai-turn--user"><article class="gf-ai-message gf-ai-message--user"><header class="gf-ai-message__meta"><span class="gf-ai-avatar gf-ai-avatar--user">姚</span><span>你</span><time datetime="2026-08-04T10:32:00+08:00">10:32</time></header><p data-ai-user-message>${escapeHtml(demoPrompt)}</p><div class="gf-ai-message__tags"><span>增长分析</span><span>创建任务</span><span>多站分发</span></div></article></div>
            <div class="gf-ai-turn gf-ai-turn--agent"><span class="gf-ai-avatar gf-ai-avatar--assistant" aria-hidden="true">${icon('sparkles')}</span><article class="gf-ai-message gf-ai-message--assistant gf-ai-message--process" aria-live="polite" aria-busy="true" data-ai-reasoning><header class="gf-ai-message__meta"><strong>GEOFlow Agent</strong><span class="gf-badge gf-badge--blue">Pro</span><span class="gf-ai-run-status" data-ai-reason-status><i></i><span data-ai-reason-status-text>正在理解任务</span></span></header><h3>好的，我开始处理这项任务</h3><p class="gf-ai-message__lead">我会展示正在使用的能力和处理进度，完成后给出可审核的任务安排。</p><ol class="gf-ai-activity-stream">
                ${reasoningSteps.map((step, index) => `<li data-ai-reason-step data-ai-step-label="${escapeHtml(step[1])}" hidden><span class="gf-ai-activity-stream__marker">${icon(step[0])}</span><div class="gf-ai-activity-stream__content"><div><strong>${escapeHtml(step[1])}</strong><span class="gf-ai-activity-stream__tag">${escapeHtml(step[3])}</span></div><p>${escapeHtml(step[2])}</p></div><span class="gf-ai-activity-stream__state"><span class="gf-sr-only">等待处理</span>${icon('check')}</span></li>`).join('')}
            </ol><div class="gf-ai-reason-summary" data-ai-reason-summary><span>${icon('route')}</span><p><strong>工作过程已完成</strong>，已形成“诊断、优化、审核分发、效果观测”四段任务计划。</p></div></article></div>
            <div class="gf-ai-turn" data-ai-result hidden><span class="gf-ai-avatar gf-ai-avatar--assistant gf-ai-avatar--complete" aria-hidden="true">${icon('check')}</span><article class="gf-ai-message gf-ai-message--assistant gf-ai-message--result"><header class="gf-ai-message__meta"><strong>GEOFlow Agent</strong><span class="gf-ai-result-status">${icon('circle-check')}计划已生成</span></header><div class="gf-ai-result-title"><div><h3>品牌可见性优化任务已安排</h3><p>已定位 6 篇优先内容，并生成 4 个带审核节点的子任务。</p></div><span class="gf-badge gf-badge--green">等待确认</span></div>
                <div class="gf-ai-result-metrics"><div><strong>86</strong><span>可见性样本</span></div><div><strong>6</strong><span>优先内容</span></div><div><strong>4</strong><span>执行子任务</span></div><div><strong>24 分钟</strong><span>预计准备时间</span></div></div>
                <div class="gf-ai-plan"><div class="gf-ai-plan__head"><h4>任务安排</h4><span>发布前需人工确认</span></div>${planSteps.map((step, index) => `<div class="gf-ai-plan__item"><span class="gf-ai-plan__number">0${index + 1}</span><div><strong>${escapeHtml(step[0])}</strong><p>${escapeHtml(step[1])}</p></div><span class="gf-ai-plan__state gf-ai-plan__state--${step[3]}">${escapeHtml(step[2])}</span></div>`).join('')}</div>
                <div class="gf-ai-completion"><span class="gf-ai-completion__icon">${icon('shield-check')}</span><div><strong>准备工作已完成</strong><p>数据范围、内容资源和质量门禁已确认，当前没有执行真实发布。</p></div></div>
                <div class="gf-ai-approval"><div><strong>确认方式</strong><p data-ai-approval-note>低风险步骤仍等待你的最终确认。</p></div><label class="gf-ai-approval__toggle"><span><strong>自动确认安全步骤</strong><small>遇到发布、删除或权限变化时仍会暂停</small></span><input type="checkbox" data-ai-auto-confirm><span class="gf-switch" aria-hidden="true"><i></i></span></label></div>
                <div class="gf-ai-confirmation" data-ai-confirmation hidden>${icon('circle-check')}<div><strong>任务已确认</strong><p>4 个任务已进入演示执行队列，发布操作仍会再次请求确认。</p></div></div>
                <div class="gf-ai-result-actions">${button('调整任务安排', 'pencil', 'secondary', 'data-demo-action="调整任务安排演示"')}${button('一键确认 4 个任务', 'check-check', 'primary', 'data-ai-confirm')}</div>
            </article></div>
            </div>
            <div class="gf-ai-chat__composer-dock"><div class="gf-ai-runbar" data-ai-runbar><span class="gf-ai-runbar__pulse"></span><strong data-ai-runbar-label>正在准备任务上下文</strong><time data-ai-runbar-time datetime="PT0S">00:00</time><span data-ai-runbar-count>0/${reasoningSteps.length}</span></div><div class="gf-ai-chat__composer"><textarea id="gf-ai-followup" rows="1" data-ai-input="followup" aria-label="继续向 GEOFlow 补充任务要求" placeholder="继续补充要求，或让 GEOFlow 调整任务计划"></textarea><div class="gf-ai-chat__composer-actions"><div><button type="button" class="gf-composer-button gf-composer-button--icon" aria-label="添加资料" data-demo-action="添加资料演示">${icon('plus')}</button><span>GEOFlow 会在执行真实发布前再次确认</span></div><button type="button" class="gf-composer-button gf-composer-button--icon gf-composer-button--stop" aria-label="暂停 Agent 工作" data-ai-stop hidden>${icon('square')}</button><button type="button" class="gf-composer-button gf-composer-button--icon gf-composer-button--send" aria-label="发送补充要求" data-ai-send="followup">${icon('arrow-up')}</button></div></div></div>
        </section>`;
    }

    function login(page) {
        return `<a class="gf-skip-link gf-sr-only" href="#main-content">跳到主要内容</a><main class="gf-login-page" id="main-content"><section class="gf-login-card"><div class="gf-login-brand">GEOFlow</div><h1>${escapeHtml(page.title)}</h1><p>${escapeHtml(page.subtitle)}</p><form class="gf-section-stack" data-demo-form data-success-message="登录信息已通过本地校验"><div class="gf-field"><label class="gf-label" for="login-user">管理员账号</label><input class="gf-input" id="login-user" autocomplete="username" value="admin" required></div><div class="gf-field"><label class="gf-label" for="login-password">密码</label><input class="gf-input" id="login-password" autocomplete="current-password" type="password" value="geoflow-demo" required></div><button class="gf-button gf-button--primary gf-w-full" type="submit">${icon('log-in')}登录</button></form><div class="gf-callout gf-mt-20">${icon('info')}<div>这是静态原型，账号和密码不会被提交。</div></div></section></main><div class="gf-toast" role="status" data-toast>原型操作已完成</div>`;
    }

    function error(page) {
        return `<section class="gf-card"><div class="gf-empty"><span class="gf-empty__icon">${icon(page.icon || 'circle-alert')}</span><h2>${escapeHtml(page.title)}</h2><p>${escapeHtml(page.subtitle)}。你可以返回首页或从左侧菜单继续访问其他功能。</p><a class="gf-button gf-button--primary" href="../../pages/workspace/dashboard.html">${icon(page.actionIcon || 'home')}<span>${escapeHtml(page.action || '返回首页')}</span></a></div></section>`;
    }

    function stateOverride(page, state) {
        const stateEntity = page.entity || ({
            'ai-workspace': '工作台推荐',
            dashboard: '运营概览',
            login: '登录配置',
            error: '页面信息',
        }[page.type] || page.title);
        if (state === 'empty') {
            return `<section class="gf-card"><div class="gf-empty"><span class="gf-empty__icon">${icon('inbox')}</span><h2>暂时没有${escapeHtml(stateEntity)}数据</h2><p>创建第一条记录后，数据会在这里展示。</p>${button(page.action || '新建记录', page.actionIcon || 'plus', 'primary', 'data-demo-action="创建第一条演示记录"')}</div></section>`;
        }
        if (state === 'loading') {
            return `<section class="gf-card" aria-busy="true" aria-label="正在加载${escapeHtml(stateEntity)}数据"><div class="gf-card__header"><div class="gf-skeleton-head"><div class="gf-skeleton gf-skeleton-title"></div><div class="gf-skeleton gf-skeleton-copy"></div></div></div><div class="gf-card__body">${Array.from({ length: 6 }, () => '<div class="gf-skeleton gf-skeleton-row"></div>').join('')}</div></section>`;
        }
        if (state === 'error') {
            return `<section class="gf-card"><div class="gf-empty"><span class="gf-empty__icon gf-empty__icon--error">${icon('circle-alert')}</span><h2>页面数据加载失败</h2><p>演示请求暂时不可用，请检查配置后重试。</p>${button('重新加载', 'refresh-cw', 'primary', 'data-demo-action="重新加载演示"')}</div></section>`;
        }
        if (state === 'permission') {
            const permissionCopy = page.type === 'login'
                ? '当前登录入口暂不可用，请联系系统管理员检查访问策略。'
                : page.role === 'super_admin'
                    ? '该页面需要超级管理员权限，请联系系统管理员调整角色。'
                    : '该页面需要管理员权限，请联系系统管理员检查账号角色。';
            return `<section class="gf-card"><div class="gf-empty"><span class="gf-empty__icon gf-empty__icon--permission">${icon('shield-alert')}</span><h2>当前账号无权访问</h2><p>${permissionCopy}</p>${button('返回上一页', 'arrow-left', 'secondary', 'data-demo-action="返回上一页演示"')}</div></section>`;
        }
        return '';
    }

    function renderPage(page, state = 'normal') {
        const override = stateOverride(page, state);
        if (page.type === 'login') {
            if (!override) return login(page);
            return `<a class="gf-skip-link gf-sr-only" href="#main-content">跳到主要内容</a><main class="gf-login-page" id="main-content"><h1 class="gf-sr-only">${escapeHtml(page.title)}</h1><div class="gf-login-state">${override}</div></main><div class="gf-toast" role="status" data-toast>原型操作已完成</div>`;
        }
        if (override) return `${pageHeader(page)}${override}`;

        const renderers = {
            'ai-workspace': aiWorkspace,
            dashboard,
            analytics,
            table,
            form,
            detail,
            cards,
            wizard,
            settings,
            review,
            error,
        };
        const renderer = renderers[page.type] || table;
        return `${page.type === 'ai-workspace' ? '' : pageHeader(page)}${renderer(page)}`;
    }

    window.GeoFlowComponents = {
        badge,
        button,
        escapeHtml,
        icon,
        metrics,
        pageHeader,
        renderPage,
        status,
    };
}());
