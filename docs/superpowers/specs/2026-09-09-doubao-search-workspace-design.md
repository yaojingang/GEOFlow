# 豆包搜索与竞品证据工作区设计

## 目标

在 AI 可见性后台页落地 V5 双栏工作台：用户先选择豆包 Global 或 Custom 搜索配置，执行搜索后查看结构化结果、权威等级和 JSON；分析结果展示信源数量分布，并将 DeepSeek 清洗出的竞品、信源、文章标题和证据链接结构化呈现。

## 方案

- 复用现有 `DoubaoSearchCustomClient` 和 `AiVisibilityService`，新增选项归一化，Global 最大 20 条，Custom 最大 50 条。
- 兼容豆包 Custom API 的 `Filter`、`QueryControl`、`Industry` 和字符串站点过滤参数；保留原始结果字段到 source metadata，避免迁移。
- DeepSeek 继续使用现有模型调用，但要求 JSON 输出；解析支持纯 JSON 和 markdown 代码块，且 evidence URL 只能引用同一次搜索的原始 URL。
- 分析服务提供 `source_distribution` 和 `competitors` 聚合数据；空数据和解析失败显示待复核，不把推断当作事实。
- Blade 页面使用现有 Tailwind 约定，证据链接放固定高度独立滚动容器，链接以标题卡片展示并保留外链按钮；JSON 提供预览、复制、下载。

## 错误处理与安全

校验查询长度、模式、数量和枚举值；无效 DeepSeek JSON 保留原文并标记待复核；不记录 API Key、密码或服务器凭证；本次只修改本地工作区，不部署、不推送 GitHub。

## 验收标准

Global/Custom 请求 payload 符合豆包文档；权威等级 1/2/3/4 正常显示；信源分布按站点计数；竞品 evidence URL 经过原始 URL 白名单校验；页面包含配置、结果筛选、JSON 操作、信源表和滚动证据链接区；相关 PHPUnit、JS、构建和静态检查通过。
