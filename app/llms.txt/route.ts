import { siteUrl } from "@/data/workspace";

export function GET() {
  const body = `# geo.youngtuo.win

> 豆包优先的品牌共识系统，帮助客户诊断 AI 答案可见度、建设品牌事实库、生成 GEO 内容并持续监测答案变化。

主要页面:
- 首页: ${siteUrl}
- Get Note 子站: ${siteUrl}/getnote
- 豆包研究中心: ${siteUrl}/doubao-research
- 工作台: ${siteUrl}/workspace
- GetNote API: ${siteUrl}/api/v1/getnote/generate
- GetNote OpenAPI: ${siteUrl}/api/v1/getnote/openapi.json
- 公开内容库: ${siteUrl}/content
- 框架与联系图: ${siteUrl}/workspace/architecture
- 关键词与 AI 收录分析: ${siteUrl}/workspace/ai-indexing
- 发布与分发: ${siteUrl}/workspace/publish
- 设置中心: ${siteUrl}/workspace/settings
- 最新公开报告: ${siteUrl}/reports/doubao-1779914230217

核心概念:
- 主攻平台: 豆包
- 对照平台: DeepSeek, Kimi, 通义千问, 元宝, ChatGPT, Gemini
- 工作流: 创建项目 -> 上传资料 -> 品牌事实库 -> 豆包问题集 -> 豆包诊断 -> 差距报告 -> 内容生成 -> 发布分发 -> 持续监测
- 豆包研究中心: 公开展示整理后的研究节点、资料证据、反向链接和轻量知识图谱；不直接暴露原始私密对话
- Get Note 子站: 把文本、网页、小红书/抖音/YouTube 链接和文件统一转成 Markdown 笔记；正式 API 需要 Workspace API Token scope \`getnote:generate\`
- 公开内容库: 展示已发布的 FAQ、品牌事实页、对比页、案例页和社媒内容包
- 关键词策略: 品牌词、服务词、问题词、证据词、竞品对比词
- AI 收录信号: 首页、公开报告、sitemap.xml、llms.txt、Search Console、FAQ/对比/案例内容

研究主题:
- 豆包社区深挖：幻觉、来源和字节场景: ${siteUrl}/doubao-research/doubao-community-deep-dive-2026-05-28
- 豆包 GitHub 生态信号地图: ${siteUrl}/doubao-research/doubao-github-ecosystem-signal-map
- 豆包民间想法与论点集: ${siteUrl}/doubao-research/doubao-public-opinion-argument-map
- 豆包中文社区信号研究报告: ${siteUrl}/doubao-research/doubao-chinese-community-signal-report
- 豆包搜索生成研究报告: ${siteUrl}/doubao-research/doubao-search-generation-research-report
- 豆包来源引用采样协议: ${siteUrl}/doubao-research/doubao-source-grounding-sampling
- 豆包答案可见度
- 证据链与资料路由
- Agent 对话内核
- 豆包采样观察
- GEO 内容实验记录
`;

  return new Response(body, {
    headers: {
      "Content-Type": "text/plain; charset=utf-8",
    },
  });
}
