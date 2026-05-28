import {
  Activity,
  BarChart3,
  Bot,
  BookOpenCheck,
  Brain,
  FileText,
  Globe2,
  ImageIcon,
  Library,
  Megaphone,
  MessageSquareText,
  Microscope,
  Network,
  SearchCheck,
  Settings,
  ShieldCheck,
  Sparkles,
  Workflow,
} from "lucide-react";

export const siteUrl = process.env.NEXT_PUBLIC_SITE_URL ?? "https://geo.youngtuo.win";
const plannerModel = process.env.AGENT_PLANNER_MODEL || process.env.DOUBAO_MODEL || "gpt-5.4-mini";

export const workflowSteps = [
  {
    id: "create",
    title: "创建项目",
    input: "品牌名、官网、行业、目标市场、核心产品、竞品",
    output: "一个可被 Agent 理解的客户项目工作区",
    status: "complete",
    href: "/workspace/settings",
    action: "核对项目配置",
  },
  {
    id: "sources",
    title: "上传资料",
    input: "官网链接、PDF、案例、FAQ、社媒链接、过往文章",
    output: "资料库和可追溯证据台账",
    status: "active",
    href: "/workspace/sources",
    action: "上传或处理资料",
  },
  {
    id: "facts",
    title: "生成品牌事实库",
    input: "资料库、官网、人工补充事实、禁用说法",
    output: "品牌事实卡、产品优势、证据来源、风险边界",
    status: "pending",
    href: "/workspace/brand",
    action: "维护品牌事实",
  },
  {
    id: "questions",
    title: "生成豆包问题集",
    input: "行业、品类、用户场景、竞品名单",
    output: "50-200 个豆包监测问题，按意图分组",
    status: "pending",
    href: "/workspace/questions",
    action: "整理问题集",
  },
  {
    id: "sample",
    title: "跑豆包诊断",
    input: "问题集、采样口径、设备/地区/联网状态",
    output: "豆包答案样本、品牌提及率、竞品位置、错误事实",
    status: "pending",
    href: "/workspace/monitor",
    action: "运行豆包采样",
  },
  {
    id: "gap",
    title: "查看差距报告",
    input: "答案样本、品牌事实库、竞品样本",
    output: "P0/P1/P2 优化清单和解释",
    status: "pending",
    href: "/workspace/reports",
    action: "生成诊断报告",
  },
  {
    id: "content",
    title: "生成优化内容",
    input: "内容缺口、关键词、事实卡、目标问题",
    output: "FAQ、对比页、榜单页、品牌事实页、社媒短内容",
    status: "pending",
    href: "/workspace/content",
    action: "生成内容草稿",
  },
  {
    id: "publish",
    title: "发布与分发",
    input: "审核后的内容、发布渠道、社交账号、UTM",
    output: "公开页面、社媒草稿、客户报告、分发记录",
    status: "pending",
    href: "/workspace/publish",
    action: "准备分发",
  },
  {
    id: "monitor",
    title: "持续监测",
    input: "定时采样计划、问题集、竞品名单",
    output: "Day 7/14/30 趋势、月报、续费依据",
    status: "pending",
    href: "/workspace/monitor",
    action: "查看监测计划",
  },
];

export const skills = [
  ["全景诊断", "geo-panorama-audit", "建立品牌在豆包和主流 AI 里的基线"],
  ["问题集", "geo-intent-miner", "生成购买、对比、品牌、行业四类问题"],
  ["事实图谱", "geo-brand-graph", "把资料变成可引用的品牌事实"],
  ["知识库", "geo-knowledge-base-builder", "整理证据、边界和来源台账"],
  ["标题体系", "geo-title-optimizer", "面向豆包的自然中文问题标题"],
  ["科普内容", "geo-explainer-builder", "解释页、FAQ、术语页"],
  ["对比内容", "geo-comparison-builder", "品牌 vs 竞品、方案选择"],
  ["榜单内容", "geo-ranking-article-builder", "推荐、Top、Alternatives"],
  ["旧文改造", "geo-content-refiner", "把现有文章改成 GEO 友好结构"],
  ["效果监测", "geo-effect-monitor", "豆包答案变化、引用和竞品差异"],
  ["归因方案", "geo-tracking", "事件、报告、线索和谨慎归因"],
  ["路线图", "geo-execution-roadmap", "30/60/90 天客户行动计划"],
];

export const platformSignals = [
  { name: "豆包", value: 72, note: "主战场，采样优先级最高" },
  { name: "DeepSeek", value: 44, note: "对照平台，重视证据链" },
  { name: "Kimi", value: 38, note: "长文引用与深度研究" },
  { name: "通义", value: 35, note: "阿里生态问法" },
  { name: "元宝", value: 29, note: "微信生态来源" },
];

export const dashboardNav = [
  { label: "项目总览", href: "/workspace", icon: Workflow },
  { label: "仪表盘", href: "/workspace/dashboard", icon: BarChart3 },
  { label: "框架与联系图", href: "/workspace/architecture", icon: Network },
  { label: "关键词与收录", href: "/workspace/ai-indexing", icon: SearchCheck },
  { label: "资料库", href: "/workspace/sources", icon: Library },
  { label: "图片库", href: "/workspace/images", icon: ImageIcon },
  { label: "品牌事实库", href: "/workspace/brand", icon: ShieldCheck },
  { label: "豆包问题集", href: "/workspace/questions", icon: MessageSquareText },
  { label: "豆包监测", href: "/workspace/monitor", icon: Activity },
  { label: "内容生产", href: "/workspace/content", icon: Sparkles },
  { label: "报告中心", href: "/workspace/reports", icon: FileText },
  { label: "GEO 经验库", href: "/workspace/lessons", icon: BookOpenCheck },
  { label: "豆包研究中心", href: "/workspace/research", icon: Microscope },
  { label: "发布与分发", href: "/workspace/publish", icon: Megaphone },
  { label: "设置", href: "/workspace/settings", icon: Settings },
  { label: "项目 Agent", href: "/workspace#agent", icon: Bot },
];

export const settingsGroups = [
  {
    title: "域名配置",
    icon: Globe2,
    items: [
      ["主域名", "geo.youngtuo.win", "已沿用原入口，当前指向 Next.js 服务"],
      ["Sitemap", `${siteUrl}/sitemap.xml`, "Next.js metadata route 自动生成"],
      ["llms.txt", `${siteUrl}/llms.txt`, "为 AI 抓取准备品牌说明和重点页面"],
      ["robots.txt", `${siteUrl}/robots.txt`, "允许搜索与 AI crawler 读取公开内容"],
    ],
  },
  {
    title: "社交账号",
    icon: Megaphone,
    items: [
      ["微信公众号", "未检测", "配置二维码、账号名、UTM 后显示在前台"],
      ["小红书 / 抖音 / 视频号", "待配置", "用于豆包 GEO 案例短内容分发"],
      ["X / YouTube / LinkedIn", "待配置", "用于英文和出海信号"],
    ],
  },
  {
    title: "分析工具",
    icon: BarChart3,
    items: [
      ["GA4", "未配置", "建议新建 property 并填入 Measurement ID"],
      ["Search Console / Bing", "未配置", "验证 geo.youngtuo.win，提交 sitemap"],
      ["Clarity / PostHog / Umami", "未配置", "热力图、产品事件、隐私友好统计三选二"],
      ["Doubao GEO Monitor", "内置", "核心指标：提及率、推荐排名、竞品命中、错误事实"],
    ],
  },
  {
    title: "豆包模型",
    icon: Brain,
    items: [
      ["模型", process.env.DOUBAO_MODEL || "doubao-seed-2-0-pro-260215", "主战场采样模型，当前用于真实豆包答案记录"],
      ["API Key", "运行时检测", "真实状态以下方动态配置区为准；密钥不会暴露到前端"],
      ["采样口径", "人工确认 + API 记录", "每次记录问题、答案、设备/地区/联网状态"],
      ["Agent Planner", "运行时检测", `规划模型：${plannerModel}；控制模式下批量采样仍需二次确认`],
    ],
  },
];

export const agentModes = [
  {
    title: "讲解模式",
    active: true,
    body: "默认开启。Agent 只能解释项目、报告、指标和下一步，不会改数据。",
  },
  {
    title: "协助模式",
    active: false,
    body: "Agent 可以生成草稿、准备任务和推荐配置，执行前需要人工确认。",
  },
  {
    title: "控制模式",
    active: false,
    body: "管理员在设置里逐项授权后，Agent 才能运行采样、生成报告、发布内容或修改配置。",
  },
];
