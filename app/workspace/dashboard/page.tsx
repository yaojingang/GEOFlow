import Link from "next/link";
import { ArrowRight, BarChart3, BrainCircuit, Clock3, Database, Eye, FileText, Globe2, Layers3, RefreshCw, Sparkles, Zap } from "lucide-react";
import { Badge } from "@/components/Badge";
import { getWorkspaceState } from "@/lib/workspace-service";

export const dynamic = "force-dynamic";

function gapCount(state: Awaited<ReturnType<typeof getWorkspaceState>>) {
  return [
    state.stats.sourceCount < 3,
    state.stats.brandFactCount < 6,
    state.stats.sampleCount < 10,
    state.stats.contentCount < 3,
    state.stats.visibleSocialAccounts < 3,
    state.analyticsConfigs.some((item) => item.status === "missing"),
  ].filter(Boolean).length;
}

function pct(value: number) {
  return `${Math.round(value)}%`;
}

function progressWidth(value: number) {
  return `${Math.max(0, Math.min(100, Math.round(value)))}%`;
}

function isToday(value: Date) {
  const now = new Date();
  return value.getFullYear() === now.getFullYear() && value.getMonth() === now.getMonth() && value.getDate() === now.getDate();
}

export default async function DashboardPage() {
  const state = await getWorkspaceState();
  const publicReports = state.reports.filter((report) => report.publicSlug);
  const draftContent = state.contentAssets.filter((item) => item.status === "draft");
  const aiGeneratedCount = state.contentAssets.length;
  const aiGeneratedRate = state.contentAssets.length === 0 ? 0 : 100;
  const configuredModelCount = [state.runtimeConfig.doubao.configured, state.runtimeConfig.planner.configured].filter(Boolean).length;
  const imageCount = state.sourceAssets.filter((item) => item.kind === "image" || item.mimeType?.startsWith("image/")).length;
  const materialTotal = state.stats.sourceCount + state.stats.brandFactCount + state.latestQuestions.length + imageCount;
  const todayAiPosts = state.contentAssets.filter((item) => isToday(item.updatedAt ?? item.createdAt)).length;
  const sourceReadiness = Math.min(100, (state.stats.sourceCount / 3) * 100);
  const contentReadiness = Math.min(100, (state.stats.contentCount / 3) * 100);
  const analyticsReadiness = state.analyticsConfigs.length === 0 ? 0 : (state.stats.configuredAnalytics / state.analyticsConfigs.length) * 100;
  const missingGaps = gapCount(state);
  const platformNames = ["网站", "小红书", "今日头条", "微信公众号", "抖音"];
  const platformDistribution = platformNames.map((name) => {
    const social = state.socialAccounts.find((item) => item.platform === name);
    const configured = name === "网站" || Boolean(social?.isVisible && (social.url || social.handle));
    return {
      name,
      count: 0,
      configured,
    };
  });
  const platformTotal = platformDistribution.reduce((sum, item) => sum + item.count, 0);
  const metricCards = [
    {
      label: "内容资产",
      value: state.contentAssets.length,
      note: `今日新增 ${todayAiPosts}`,
      icon: FileText,
    },
    {
      label: "已发布",
      value: publicReports.length,
      note: state.reports.length > 0 ? `发布率 ${pct((publicReports.length / state.reports.length) * 100)}` : "发布率 0%",
      icon: Globe2,
    },
    {
      label: "AI 生成",
      value: aiGeneratedCount,
      note: `占比 ${pct(aiGeneratedRate)}`,
      icon: BrainCircuit,
    },
    {
      label: "总浏览",
      value: 0,
      note: "待接入统计",
      icon: Eye,
    },
    {
      label: "队列任务",
      value: 0,
      note: "运行 0 / 等待 0",
      icon: Zap,
    },
    {
      label: "AI 模型",
      value: configuredModelCount,
      note: "Doubao / Planner",
      icon: Sparkles,
    },
    {
      label: "素材总量",
      value: materialTotal,
      note: "资料 / 事实 / 问题 / 图片",
      icon: Database,
    },
    {
      label: "待审核",
      value: draftContent.length,
      note: `${state.contentAssets.length} 篇内容资产`,
      icon: Clock3,
    },
  ];
  const quickStarts = [
    {
      step: "1",
      title: "准备素材",
      body: "维护官网、PDF、FAQ、案例、图片库和作者素材。",
      href: "/workspace/images",
      action: "进入图片库",
    },
    {
      step: "2",
      title: "创建任务",
      body: "扩展关键词和豆包问题，启动内容生成任务。",
      href: "/workspace/ai-indexing",
      action: "创建任务",
    },
    {
      step: "3",
      title: "内容发布",
      body: "配置内容站点发布设置，管理文章发布后的投递方式。",
      href: "/workspace/content",
      action: "管理内容",
    },
  ];
  const performanceItems = [
    ["任务成功率", "100.0%", 100, "暂无失败任务"],
    ["资料完整度", pct(sourceReadiness), sourceReadiness, `${state.stats.sourceCount}/3 份核心资料`],
    ["内容准备度", pct(contentReadiness), contentReadiness, `${state.stats.contentCount}/3 篇基础内容`],
    ["今日 AI 发文", String(todayAiPosts), Math.min(100, todayAiPosts * 25), "今日创建或更新的内容"],
    ["平均生成耗时", "--", 0, "待接任务耗时"],
  ];

  return (
    <div className="p-4 sm:p-6">
      <section className="rounded-lg bg-white p-6 shadow-panel ring-1 ring-line">
        <div className="flex flex-col justify-between gap-4 md:flex-row md:items-start">
          <div>
            <Badge tone="doubao">仪表盘</Badge>
            <h1 className="mt-5 text-4xl font-semibold">geo.youngtuo.win 仪表盘</h1>
            <p className="mt-4 max-w-3xl text-ink/65 leading-7">资料、任务队列、内容生成和发布性能概览。</p>
          </div>
          <Link
            href="/workspace/dashboard"
            className="inline-flex items-center justify-center gap-2 rounded-md bg-white px-4 py-2 text-sm font-semibold text-ink/65 ring-1 ring-line transition hover:-translate-y-0.5 hover:text-doubao hover:ring-doubao/40"
          >
            <RefreshCw className="size-4" />
            刷新
          </Link>
        </div>
      </section>

      <section className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {metricCards.map((card) => {
          const Icon = card.icon;
          return (
            <article key={card.label} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
              <div className="flex items-center gap-4">
                <div className="flex size-10 items-center justify-center rounded-md bg-panel text-doubao ring-1 ring-line">
                  <Icon className="size-5" />
                </div>
                <div className="min-w-0">
                  <p className="text-sm text-ink/50">{card.label}</p>
                  <p className="mt-1 text-3xl font-semibold text-ink">{card.value}</p>
                  <p className="mt-1 text-sm text-ink/52">{card.note}</p>
                </div>
              </div>
            </article>
          );
        })}
      </section>

      <section className="mt-6 rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div>
          <h2 className="text-xl font-semibold">快速开始</h2>
          <p className="mt-2 text-sm text-ink/55">按素材、任务和发布流程推进内容生产。</p>
        </div>
        <div className="mt-5 grid gap-4 lg:grid-cols-3">
          {quickStarts.map((item) => (
            <article key={item.step} className="rounded-md bg-white p-4 ring-1 ring-line">
              <div className="flex items-start gap-4">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-doubao text-sm font-semibold text-paper shadow-doubao">{item.step}</div>
                <div className="min-w-0">
                  <h3 className="font-semibold">{item.title}</h3>
                  <p className="mt-2 text-sm leading-6 text-ink/58">{item.body}</p>
                  <Link
                    href={item.href}
                    className="mt-4 inline-flex items-center justify-center gap-2 rounded-md bg-panel px-3 py-2 text-sm font-semibold text-ink/70 ring-1 ring-line transition hover:bg-doubao hover:text-paper hover:ring-doubao"
                  >
                    {item.action}
                    <ArrowRight className="size-4" />
                  </Link>
                </div>
              </div>
            </article>
          ))}
        </div>
      </section>

      <section className="mt-6 grid gap-4 xl:grid-cols-[1fr_1fr_390px]">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <div className="flex items-center justify-between gap-3">
            <div>
              <h2 className="text-xl font-semibold">分类分布</h2>
              <p className="mt-1 text-sm text-ink/55">按内容发布平台展示。</p>
            </div>
            <Layers3 className="size-5 text-doubao" />
          </div>
          <div className="mt-5 grid gap-4">
            {platformDistribution.map((item) => {
              const share = platformTotal > 0 ? (item.count / platformTotal) * 100 : 0;
              return (
                <div key={item.name}>
                  <div className="flex items-center justify-between gap-3 text-sm">
                    <div className="min-w-0">
                      <p className="font-semibold">{item.name}</p>
                      <p className="mt-1 text-xs text-ink/45">{item.configured ? "已配置" : "待配置"}</p>
                    </div>
                    <span className="font-mono text-ink/62">{item.count}</span>
                  </div>
                  <div className="mt-2 h-2 overflow-hidden rounded-full bg-ink/10">
                    <div className="h-full rounded-full bg-doubao progress-sweep" style={{ width: progressWidth(share) }} />
                  </div>
                </div>
              );
            })}
          </div>
          <p className="mt-5 rounded-md bg-panel p-3 text-sm leading-6 text-ink/58 ring-1 ring-line">
            {platformTotal === 0 ? "待发布：当前还没有真实平台发布记录。" : `共 ${platformTotal} 条平台发布记录。`}
          </p>
        </article>

        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <div className="flex items-center justify-between gap-3">
            <div>
              <h2 className="text-xl font-semibold">系统性能</h2>
              <p className="mt-1 text-sm text-ink/55">任务成功率、资料完整度和今日 AI 发文。</p>
            </div>
            <BarChart3 className="size-5 text-doubao" />
          </div>
          <div className="mt-5 grid gap-4">
            {performanceItems.map(([label, value, rawProgress, note]) => (
              <div key={label}>
                <div className="flex items-center justify-between gap-3 text-sm">
                  <span className="font-semibold">{label}</span>
                  <span className="font-mono text-ink/62">{value}</span>
                </div>
                <div className="mt-2 h-2 overflow-hidden rounded-full bg-ink/10">
                  <div className="h-full rounded-full bg-doubao progress-sweep" style={{ width: progressWidth(Number(rawProgress)) }} />
                </div>
                <p className="mt-1 text-xs text-ink/45">{note}</p>
              </div>
            ))}
          </div>
          <div className="mt-5 rounded-md bg-panel p-3 text-sm leading-6 text-ink/58 ring-1 ring-line">
            当前待补齐 {missingGaps} 项，平均生成耗时将在接入任务耗时后显示。
          </div>
        </article>

        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <div className="flex items-center justify-between gap-3">
            <div>
              <h2 className="text-xl font-semibold">最新内容</h2>
              <p className="mt-1 text-sm text-ink/55">最近创建或更新的 5 篇内容。</p>
            </div>
            <Link href="/workspace/content" className="inline-flex items-center gap-1 rounded-md bg-panel px-3 py-2 text-sm font-semibold text-ink/65 ring-1 ring-line transition hover:text-doubao">
              全部
              <ArrowRight className="size-4" />
            </Link>
          </div>
          <div className="mt-5 grid gap-3">
            {state.contentAssets.slice(0, 5).length > 0 ? (
              state.contentAssets.slice(0, 5).map((item) => (
                <Link key={item.id} href="/workspace/content" className="rounded-md bg-panel p-3 ring-1 ring-line transition hover:-translate-y-0.5 hover:ring-doubao/40">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="line-clamp-1 text-sm font-semibold">{item.title}</p>
                      <p className="mt-1 text-xs text-ink/45">{item.type}</p>
                    </div>
                    <Badge tone={item.status === "draft" ? "dark" : "doubao"}>{item.status === "draft" ? "草稿" : item.status}</Badge>
                  </div>
                </Link>
              ))
            ) : (
              <div className="rounded-md bg-panel p-4 text-sm leading-6 text-ink/55 ring-1 ring-line">还没有内容资产，先从关键词与收录或内容生产页生成第一篇草稿。</div>
            )}
          </div>
        </article>
      </section>
    </div>
  );
}
