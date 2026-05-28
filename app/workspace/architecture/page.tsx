import Link from "next/link";
import { ArrowRight, Bot, Database, FileText, Globe2, MessageSquareText, Network, Share2, Sparkles } from "lucide-react";
import { Badge } from "@/components/Badge";
import { getWorkspaceState } from "@/lib/workspace-service";

export const dynamic = "force-dynamic";

function statusTone(ready: boolean) {
  return ready ? "doubao" : "dark";
}

export default async function ArchitecturePage() {
  const state = await getWorkspaceState();
  const visibleSocials = state.socialAccounts.filter((item) => item.isVisible && (item.url || item.handle));
  const configuredAnalytics = state.analyticsConfigs.filter((item) => item.status === "configured" || item.status === "active");
  const nodes = [
    {
      title: "客户展示端",
      icon: Globe2,
      metric: state.workspace.domain ?? "geo.youngtuo.win",
      body: "客户、搜索引擎和 AI crawler 看到的公开入口。",
      href: "https://geo.youngtuo.win",
      action: "打开",
      ready: true,
    },
    {
      title: "资料与证据",
      icon: Database,
      metric: `${state.stats.sourceCount} 资料 / ${state.stats.brandFactCount} 事实`,
      body: "官网、PDF、FAQ、案例和人工事实会变成可引用证据。",
      href: "/workspace/sources",
      action: "管理资料",
      ready: state.stats.sourceCount >= 3 && state.stats.brandFactCount >= 6,
    },
    {
      title: "豆包问题集",
      icon: MessageSquareText,
      metric: `${state.latestQuestions.length} 个问题`,
      body: "围绕行业、购买、对比、品牌和竞品场景组织采样问题。",
      href: "/workspace/questions",
      action: "维护问题",
      ready: state.latestQuestions.length >= 5,
    },
    {
      title: "Agent 工作台",
      icon: Bot,
      metric: state.agentSettings?.mode === "Control" ? "控制模式" : "讲解模式",
      body: "默认解释项目；授权后才运行采样、生成报告或创建内容。",
      href: "/workspace/settings",
      action: "配置权限",
      ready: state.agentSettings?.mode === "Control",
    },
    {
      title: "豆包监测",
      icon: Network,
      metric: `${state.stats.sampleCount} 样本 / ${state.stats.mentionRate}%`,
      body: "记录品牌是否被提到、竞品是否出现、事实是否错误。",
      href: "/workspace/monitor",
      action: "运行采样",
      ready: state.stats.sampleCount >= 10,
    },
    {
      title: "内容生产",
      icon: Sparkles,
      metric: `${state.stats.contentCount} 篇草稿`,
      body: "把缺口转成 FAQ、对比页、案例页和社媒短内容。",
      href: "/workspace/content",
      action: "生成内容",
      ready: state.stats.contentCount >= 3,
    },
    {
      title: "报告中心",
      icon: FileText,
      metric: `${state.stats.reportCount} 份报告`,
      body: "把监测、证据链和下一步动作整理成客户可分享报告。",
      href: "/workspace/reports",
      action: "查看报告",
      ready: state.stats.reportCount > 0,
    },
    {
      title: "发布与分发",
      icon: Share2,
      metric: `${visibleSocials.length} 社媒 / ${configuredAnalytics.length} 分析`,
      body: "把报告、内容、社媒主页和分析工具汇总成交付状态。",
      href: "/workspace/publish",
      action: "查看交付",
      ready: visibleSocials.length >= 3 && configuredAnalytics.length >= 3,
    },
  ];
  const flows = [
    ["客户资料", "资料与证据", "品牌事实库"],
    ["品牌事实库", "豆包问题集", "豆包监测"],
    ["豆包监测", "报告中心", "客户展示端"],
    ["豆包监测", "内容生产", "发布与分发"],
    ["Agent 工作台", "资料与证据", "报告中心"],
    ["发布与分发", "分析工具", "持续监测"],
  ];
  const diagramStages = [
    {
      title: "输入",
      metric: `${state.stats.sourceCount} 份资料`,
      body: "官网、PDF、FAQ、案例、社媒链接",
    },
    {
      title: "理解",
      metric: `${state.stats.brandFactCount} 条事实`,
      body: "资料路由、品牌事实、证据边界",
    },
    {
      title: "采样",
      metric: `${state.stats.sampleCount} 条豆包答案`,
      body: "问题集、提及率、竞品命中、错误事实",
    },
    {
      title: "生成",
      metric: `${state.stats.contentCount} 篇内容`,
      body: "FAQ、对比页、案例页、社媒短内容",
    },
    {
      title: "交付",
      metric: `${state.stats.reportCount} 份报告`,
      body: "客户报告、Markdown、发布页、复盘待办",
    },
  ];
  const controlLoops = [
    ["Agent", state.agentSettings?.mode === "Control" ? "可控制执行" : "讲解与建议"],
    ["Planner", state.runtimeConfig.planner.configured ? "模型规划已接入" : "规则规划兜底"],
    ["Doubao", state.runtimeConfig.doubao.configured ? "真实采样已接入" : "待接 API"],
    ["Cron", "Day 7/14/30 定时监测"],
  ];

  return (
    <div className="p-4 sm:p-6">
      <section className="rounded-lg bg-white p-6 shadow-panel ring-1 ring-line">
        <Badge tone="doubao">项目框架</Badge>
        <h1 className="mt-5 text-4xl font-semibold">框架与联系图</h1>
        <p className="mt-4 max-w-3xl text-ink/65 leading-7">
          这张图说明 geo.youngtuo.win 如何从客户资料出发，经过豆包采样、Agent 协作、内容生产和报告发布，最后形成可复核的 GEO 交付闭环。
        </p>
      </section>

      <section className="mt-6 rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex flex-col justify-between gap-3 md:flex-row md:items-end">
          <div>
            <Badge tone="doubao">主流程</Badge>
            <h2 className="mt-3 text-xl font-semibold">从资料到交付</h2>
          </div>
          <p className="text-sm text-ink/55">所有写入动作都经过 Agent 权限和二次确认。</p>
        </div>
        <div className="mt-5 grid gap-3 xl:grid-cols-[1fr_auto_1fr_auto_1fr_auto_1fr_auto_1fr] xl:items-stretch">
          {diagramStages.map((stage, index) => (
            <div key={stage.title} className="contents">
              <article className="rounded-md bg-panel p-4 ring-1 ring-line">
                <div className="flex items-center justify-between gap-3">
                  <span className="font-mono text-xs text-doubao">{String(index + 1).padStart(2, "0")}</span>
                  <span className="rounded-full bg-white px-2 py-1 text-xs font-semibold text-doubao ring-1 ring-line">{stage.title}</span>
                </div>
                <p className="mt-4 text-lg font-semibold">{stage.metric}</p>
                <p className="mt-2 text-sm leading-6 text-ink/58">{stage.body}</p>
              </article>
              {index < diagramStages.length - 1 ? (
                <div className="flex items-center justify-center text-doubao">
                  <ArrowRight className="hidden size-5 xl:block" />
                  <div className="h-6 w-px bg-line xl:hidden" />
                </div>
              ) : null}
            </div>
          ))}
        </div>
        <div className="mt-4 grid gap-3 md:grid-cols-4">
          {controlLoops.map(([name, value]) => (
            <div key={name} className="rounded-md bg-panel px-4 py-3 ring-1 ring-line">
              <p className="text-xs font-semibold uppercase text-ink/40">{name}</p>
              <p className="mt-2 text-sm font-medium text-ink/72">{value}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="mt-6 rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex items-center gap-3">
          <Network className="size-5 text-doubao" />
          <h2 className="text-xl font-semibold">运行框架</h2>
        </div>
        <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          {nodes.map((node) => {
            const Icon = node.icon;
            return (
              <article key={node.title} className="rounded-md bg-panel p-4 ring-1 ring-line">
                <div className="flex items-center justify-between gap-3">
                  <Icon className="size-5 text-doubao" />
                  <Badge tone={statusTone(node.ready)}>{node.ready ? "已就绪" : "待补齐"}</Badge>
                </div>
                <h3 className="mt-4 font-semibold">{node.title}</h3>
                <p className="mt-2 break-all text-sm font-medium text-doubao">{node.metric}</p>
                <p className="mt-3 min-h-18 text-sm leading-6 text-ink/58">{node.body}</p>
                <Link
                  href={node.href}
                  target={node.href.startsWith("http") ? "_blank" : undefined}
                  rel={node.href.startsWith("http") ? "noreferrer" : undefined}
                  className="mt-4 inline-flex w-full items-center justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-ink/65 ring-1 ring-line transition hover:bg-doubao hover:text-paper hover:ring-doubao"
                >
                  {node.action}
                </Link>
              </article>
            );
          })}
        </div>
      </section>

      <section className="mt-6 grid gap-4 xl:grid-cols-[1fr_380px]">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <h2 className="text-xl font-semibold">联系路径</h2>
          <div className="mt-5 grid gap-3">
            {flows.map(([from, via, to]) => (
              <div key={`${from}-${via}-${to}`} className="grid gap-3 rounded-md bg-panel p-4 ring-1 ring-line md:grid-cols-[1fr_auto_1fr_auto_1fr] md:items-center">
                <p className="font-medium">{from}</p>
                <ArrowRight className="hidden size-4 text-doubao md:block" />
                <p className="font-medium text-doubao">{via}</p>
                <ArrowRight className="hidden size-4 text-doubao md:block" />
                <p className="font-medium">{to}</p>
              </div>
            ))}
          </div>
        </article>

        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <h2 className="text-xl font-semibold">当前瓶颈</h2>
          <div className="mt-4 grid gap-3 text-sm leading-6 text-ink/62">
            <p className="rounded-md bg-panel p-4 ring-1 ring-line">资料和事实还偏少，优先补客户案例、FAQ 和证据链接。</p>
            <p className="rounded-md bg-panel p-4 ring-1 ring-line">豆包采样还差 3 条到基础趋势线，建议继续运行真实问题。</p>
            <p className="rounded-md bg-panel p-4 ring-1 ring-line">Search Console 和更多社媒入口补齐后，报告会更适合客户交付。</p>
          </div>
        </article>
      </section>
    </div>
  );
}
