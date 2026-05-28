import { AgentPanel } from "@/components/AgentPanel";
import { Badge } from "@/components/Badge";
import { WorkflowSteps } from "@/components/WorkflowSteps";
import { agentModes, platformSignals } from "@/data/workspace";
import { getWorkspaceState } from "@/lib/workspace-service";
import Link from "next/link";

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

export default async function WorkspacePage() {
  const state = await getWorkspaceState();
  const sourcesReady = Math.min(100, Math.round((state.stats.sourceCount / 4) * 100));
  const agentMode = state.agentSettings?.mode ?? "Explain";
  const p0Gaps = gapCount(state);
  const signals = platformSignals.map((item) => (item.name === "豆包" ? { ...item, value: state.stats.mentionRate } : item));
  const missingAnalytics = state.analyticsConfigs.filter((item) => item.status === "missing");
  const missingSocials = state.socialAccounts.filter((item) => !item.isVisible || (!item.url && !item.handle));
  const readinessItems = [
    {
      title: "资料证据",
      status: state.stats.sourceCount >= 3 ? "ready" : "todo",
      metric: `${state.stats.sourceCount}/3`,
      body: state.stats.sourceCount >= 3 ? "资料数量已够演示，继续补行业案例会更稳。" : "至少补官网、案例、FAQ 三类资料，处理后进入事实库。",
      href: "/workspace/sources",
      action: "补资料",
    },
    {
      title: "品牌事实",
      status: state.stats.brandFactCount >= 6 ? "ready" : "todo",
      metric: `${state.stats.brandFactCount}/6`,
      body: state.stats.brandFactCount >= 6 ? "事实数量已够支撑报告。" : "把卖点、证据、适用场景拆成可引用事实，减少豆包答错。",
      href: "/workspace/brand",
      action: "补事实",
    },
    {
      title: "豆包采样",
      status: state.stats.sampleCount >= 10 ? "ready" : "todo",
      metric: `${state.stats.sampleCount}/10`,
      body: state.stats.sampleCount >= 10 ? "采样基线已够看趋势。" : "继续跑真实豆包问题，形成更可靠的提及率和竞品命中基线。",
      href: "/workspace/monitor",
      action: "运行采样",
    },
    {
      title: "内容资产",
      status: state.stats.contentCount >= 3 ? "ready" : "todo",
      metric: `${state.stats.contentCount}/3`,
      body: state.stats.contentCount >= 3 ? "已有可审核内容草稿。" : "优先生成 FAQ、对比页、案例页，补豆包最容易引用的内容。",
      href: "/workspace/content",
      action: "生成内容",
    },
    {
      title: "分析工具",
      status: missingAnalytics.length === 0 ? "ready" : "todo",
      metric: `${state.analyticsConfigs.length - missingAnalytics.length}/${state.analyticsConfigs.length}`,
      body:
        missingAnalytics.length === 0
          ? "分析工具已具备基础配置。"
          : `待补 ${missingAnalytics.map((item) => item.provider).join("、")}，补完后报告归因更完整。`,
      href: "/workspace/settings",
      action: "补配置",
    },
    {
      title: "社媒入口",
      status: state.stats.visibleSocialAccounts >= 3 ? "ready" : "todo",
      metric: `${state.stats.visibleSocialAccounts}/3`,
      body:
        state.stats.visibleSocialAccounts >= 3
          ? "客户展示端已有足够触点。"
          : `待补 ${missingSocials.slice(0, 3).map((item) => item.platform).join("、")}，方便客户从报告进入账号。`,
      href: "/workspace/settings",
      action: "补社媒",
    },
  ];
  const cards = [
    ["豆包提及率", `${state.stats.mentionRate}%`, `${state.stats.sampleCount} 条真实采样`],
    ["P0 缺口", String(p0Gaps), p0Gaps > 0 ? "优先补资料/事实/社媒" : "当前证据链可演示"],
    ["资料完整度", `${sourcesReady}%`, `${state.stats.sourceCount} 条资料 / ${state.stats.brandFactCount} 条事实`],
    ["Agent 权限", agentMode === "Control" ? "开启" : "关闭", agentMode === "Control" ? "控制模式" : "讲解模式"],
  ];

  return (
    <div className="grid gap-6 p-4 sm:p-6 xl:grid-cols-[1fr_390px]">
      <section className="min-w-0">
        <div className="rounded-lg bg-white p-6 shadow-panel ring-1 ring-line">
          <Badge tone="doubao">项目：geo.youngtuo.win 内测</Badge>
          <h1 className="mt-5 text-4xl font-semibold text-balance">从资料到豆包答案，一步一步跑完整个项目</h1>
          <p className="mt-4 max-w-3xl text-ink/66 leading-7">
            每一步都显示客户要做什么、系统会得到什么。Agent 会理解资料、问题集、事实库、内容和配置；默认只讲解，开启权限后才控制项目。
          </p>
        </div>

        <div className="mt-6 grid gap-4 md:grid-cols-4">
          {cards.map(([label, value, note]) => (
            <article key={label} className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
              <p className="text-xs uppercase tracking-[0.18em] text-ink/38">{label}</p>
              <p className="mt-3 text-3xl font-semibold text-doubao">{value}</p>
              <p className="mt-2 text-sm text-ink/52">{note}</p>
            </article>
          ))}
        </div>

        <section className="mt-6 rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <div className="flex flex-col justify-between gap-3 md:flex-row md:items-end">
            <div>
              <Badge tone={p0Gaps > 0 ? "dark" : "doubao"}>{p0Gaps > 0 ? `${p0Gaps} 个待补齐` : "可演示"}</Badge>
              <h2 className="mt-3 text-2xl font-semibold">客户交付待办</h2>
              <p className="mt-2 text-sm text-ink/55">每项都对应一个入口，处理完后会直接影响报告和发布页。</p>
            </div>
            <Link
              href="/workspace/publish"
              className="inline-flex items-center justify-center rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
            >
              查看交付页
            </Link>
          </div>
          <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {readinessItems.map((item) => (
              <article key={item.title} className="rounded-md bg-panel p-4 ring-1 ring-line">
                <div className="flex items-center justify-between gap-3">
                  <h3 className="font-semibold">{item.title}</h3>
                  <Badge tone={item.status === "ready" ? "doubao" : "dark"}>{item.metric}</Badge>
                </div>
                <p className="mt-3 min-h-16 text-sm leading-6 text-ink/58">{item.body}</p>
                <Link
                  href={item.href}
                  className="mt-3 inline-flex w-full items-center justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-ink/65 ring-1 ring-line transition hover:bg-doubao hover:text-paper hover:ring-doubao"
                >
                  {item.action}
                </Link>
              </article>
            ))}
          </div>
        </section>

        <section className="mt-6">
          <div className="mb-4 flex items-end justify-between gap-4">
            <div>
              <h2 className="text-2xl font-semibold">客户 9 步操作流</h2>
              <p className="mt-2 text-sm text-ink/55">客户不需要懂后台，只要跟着当前步骤走。</p>
            </div>
          </div>
          <WorkflowSteps />
        </section>

        <section className="mt-6 grid gap-4 lg:grid-cols-2">
          <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
            <h2 className="text-xl font-semibold">豆包监测概览</h2>
            <div className="mt-5 grid gap-3">
              {signals.map((item) => (
                <div key={item.name}>
                  <div className="flex justify-between text-sm">
                    <span>{item.name}</span>
                    <span className="font-mono text-doubao">{item.value}%</span>
                  </div>
                  <div className="mt-2 h-2 overflow-hidden rounded-full bg-ink/10">
                    <div className="h-full rounded-full bg-doubao progress-sweep" style={{ width: `${item.value}%` }} />
                  </div>
                </div>
              ))}
            </div>
          </article>

          <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
            <h2 className="text-xl font-semibold">Agent 权限模式</h2>
            <div className="mt-5 grid gap-3">
              {agentModes.map((mode) => (
                <div key={mode.title} className="rounded-md bg-panel p-3 shadow-soft ring-1 ring-line">
                  <div className="flex items-center justify-between">
                    <h3 className="font-semibold">{mode.title}</h3>
                    {mode.title.startsWith(agentMode === "Control" ? "控制" : agentMode === "Assist" ? "协助" : "讲解") ? (
                      <Badge tone="doubao">当前</Badge>
                    ) : (
                      <Badge tone="dark">需授权</Badge>
                    )}
                  </div>
                  <p className="mt-2 text-sm leading-6 text-ink/58">{mode.body}</p>
                </div>
              ))}
            </div>
          </article>
        </section>
      </section>

      <AgentPanel />
    </div>
  );
}
