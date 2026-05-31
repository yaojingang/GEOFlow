# GEO 生产闭环运营手册

## 1. 配置 AI 模型

进入后台 `AI配置器`，新增 OpenAI-compatible 或 Anthropic-compatible 模型。保存后回到 GEO 工作台，确认模型出现在“用于搜索的 AI 平台”区域。没有真实模型时，可以先用 Mock 平台完成流程验收。

## 2. 保存品牌资料

在 GEO 工作台填写品牌名、别名、产品服务、核心优势、案例、痛点、服务区域和补充事实。服务区域和补充事实会影响草稿生成、审核和发布后复测。

## 3. 生成机会词

点击“生成机会词”，系统会基于品牌资料生成关键词机会。优先选择机会分高、成交意图明确、资料可补充的词进入 AI 搜索批次。

## 4. 运行 AI 搜索批次

选择机会词和 AI 平台，创建搜索批次后执行运行。完成后检查 AI 回答中是否抽取到了引用 URL。没有来源时，换更具体的问题词，或补充品牌资料再重跑。

搜索批次会按 `points_cost` 扣减组织点数，并写入 `point_logs.action=geo_search_run`。余额不足时，批次保持 `pending`，不会进入运行态，也不会产生回答记录。引用 URL 会同时写入去重后的来源库和逐回答归因表，后续同一个 URL 被多轮检视引用时，仍能追溯到各自的搜索批次。

## 5. 批量采集引用来源

进入引用来源库，勾选来源，点击“批量采集”。采集失败时先看状态和错误信息：登录、验证码、反爬、404、超时都属于正常阻塞，不要绕过平台限制。

## 6. 批量评分并生成简报

对已成功采集的来源执行“批量评分”，再点击“生成简报”。简报会选取高分参考内容，沉淀参考标题、URL、摘要、结构建议和证据点。

## 7. 生成文章草稿

在引用来源库的参考内容简报区域点击“生成草稿”。草稿会保留高分参考内容和品牌事实，先生成稳定 Markdown，后续可以人工编辑。

## 8. 审核、转文章、发布

在草稿编辑页查看“发布准备”。发布前至少检查禁用词、参考来源覆盖、本地意图覆盖和品牌出现。转为正式文章后进入文章管理继续审核和发布。

独立引用来源草稿如需走微信公众号草稿链路，查看 `docs/geo/wxmp-yixiaoer-publish-chain.md`。该链路只提交微信公众号草稿，不群发，也不提交到小红书、抖音、视频号或 B 站。

## 9. 发布后复测

文章转为正式文章后，在报告页点击“发布后复测”。当前版本会生成确定性本地复测记录，保存发布前得分、复测得分、文章 URL 和摘要。后续接真实 AI 搜索批次时，复测记录可继续作为对比基线。

## 10. 队列运行建议

本地调试保持：

```env
GEOAMPLIFY_GEO_ASYNC_JOBS=false
```

生产批量任务建议：

```env
QUEUE_CONNECTION=redis
GEOAMPLIFY_GEO_ASYNC_JOBS=true
GEOAMPLIFY_AI_WEB_WORKBENCH_COMMAND=/absolute/path/to/ai-web-workbench
GEOAMPLIFY_AI_WEB_WORKBENCH_TIMEOUT=420
GEOAMPLIFY_AI_WEB_WORKBENCH_LOGIN_CHECK_TIMEOUT=90
GEOAMPLIFY_AI_WEB_WORKBENCH_DATA_DIR=/absolute/path/to/workbench-data
YIXIAOER_API_KEY=...
YIXIAOER_API_URL=https://www.yixiaoer.cn/api
```

并确保 queue worker 或 Horizon 常驻运行。
