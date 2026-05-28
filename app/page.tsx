import Link from "next/link";
import {
  ArrowRight,
  BarChart3,
  Bot,
  BrainCircuit,
  CheckCircle2,
  DatabaseZap,
  Gauge,
  Globe2,
  MessageSquareText,
  MousePointer2,
  Play,
  Sparkles,
} from "lucide-react";
import { Badge } from "@/components/Badge";
import { LeadForm } from "@/components/LeadForm";
import { platformSignals, skills, workflowSteps } from "@/data/workspace";
import { getWorkspaceState } from "@/lib/workspace-service";

export const dynamic = "force-dynamic";

const serviceSteps = [
  ["诊断豆包认知", "采样真实问题，记录品牌是否出现、排第几、被怎样描述。"],
  ["建设品牌事实库", "把官网、资料、案例和禁用说法整理成可被引用的事实。"],
  ["生产豆包友好内容", "生成 FAQ、对比页、榜单页、事实页和社媒内容包。"],
  ["持续监测变化", "每周追踪答案变化，形成 Day 7/14/30 报告。"],
];

const comparison = [
  ["传统 SEO", "优化网页排名", "用户还要点击链接"],
  ["普通 AI 内容工具", "批量生成文章", "不知道豆包是否采纳"],
  ["geo.youngtuo.win", "塑造豆包答案里的品牌共识", "能诊断、执行、监测、报告"],
];

const commandSteps = ["读取资料", "生成问题", "采样豆包", "输出报告"];

export default async function Home() {
  const state = await getWorkspaceState();
  const latestReport = state.reports.find((report) => report.publicSlug);
  const liveSignals = platformSignals.map((item) => (item.name === "豆包" ? { ...item, value: state.stats.mentionRate } : item));

  return (
    <main className="min-h-screen bg-paper text-ink">
      <header className="fixed inset-x-0 top-0 z-40 border-b border-line bg-paper/86 backdrop-blur-xl">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-6 px-4 py-4 sm:px-6 lg:px-8">
          <Link href="/" className="flex items-center gap-3">
            <div className="flex size-9 items-center justify-center rounded-md bg-doubao/10 text-xs font-bold text-doubao shadow-soft">geo</div>
            <div>
              <p className="text-sm font-semibold">geo.youngtuo.win</p>
              <p className="text-xs text-ink/45">Project console</p>
            </div>
          </Link>
          <nav className="hidden items-center gap-7 text-sm text-ink/70 md:flex">
            <a className="transition hover:text-ink" href="#workflow">客户步骤</a>
            <a className="transition hover:text-ink" href="#doubao">豆包主攻</a>
            <Link className="transition hover:text-ink" href="/getnote">Get Note</Link>
            <Link className="transition hover:text-ink" href="/doubao-research">研究中心</Link>
            <a className="transition hover:text-ink" href="#skills">Skill 矩阵</a>
            {latestReport?.publicSlug ? <Link className="transition hover:text-ink" href={`/reports/${latestReport.publicSlug}`}>报告</Link> : null}
            <a className="transition hover:text-ink" href="#contact">联系</a>
          </nav>
          <Link href="/workspace" className="inline-flex items-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink">
            进入工作台
            <ArrowRight className="size-4" />
          </Link>
        </div>
      </header>

      <section className="answer-grid relative flex min-h-screen items-center overflow-hidden pt-24">
        <div className="absolute inset-x-0 top-24 h-px signal-line" />
        <div className="absolute inset-x-0 bottom-0 h-36 bg-gradient-to-t from-paper via-paper/80 to-transparent" />
        <div className="mx-auto grid w-full max-w-7xl items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[0.95fr_1.05fr] lg:px-8">
          <div className="animate-rise">
            <Badge tone="doubao">主攻豆包 · 兼顾多平台</Badge>
            <h1 className="mt-7 max-w-4xl text-5xl font-semibold leading-[1.02] text-balance sm:text-6xl lg:text-7xl">
              让你的品牌成为豆包答案里的首选推荐
            </h1>
            <p className="mt-6 max-w-2xl text-lg leading-8 text-ink/72">
              从品牌资料到豆包问题集，从答案采样到内容优化，再到 Day 7/14/30 效果报告。客户每一步都知道要做什么，也知道会得到什么。
            </p>

            <div className="mt-8 max-w-2xl rounded-lg bg-paper p-2 text-ink shadow-panel">
              <div className="flex items-start gap-3 px-3 py-3">
                <MessageSquareText className="mt-1 size-5 shrink-0 text-doubao" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-ink/55">输入一个客户问题或品牌域名</p>
                  <p className="mt-1 text-base font-semibold">“豆包推荐做 GEO 服务的公司时，怎样让它提到我们？”</p>
                </div>
              </div>
              <div className="flex flex-wrap items-center justify-between gap-3 border-t border-ink/8 px-3 py-2">
                <div className="flex flex-wrap gap-2 text-xs text-ink/55">
                  {commandSteps.map((step) => (
                    <span key={step} className="rounded-md bg-panel px-2.5 py-1">{step}</span>
                  ))}
                </div>
                <Link href="/workspace" className="inline-flex min-w-28 items-center justify-center gap-2 rounded-md bg-doubao px-3 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:bg-ink">
                  运行工作流
                  <Play className="size-4" />
                </Link>
              </div>
            </div>

            <div className="mt-6 flex flex-wrap gap-3">
              <Link href="/workspace" className="inline-flex items-center gap-2 rounded-md bg-doubao px-5 py-3 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink">
                查看客户工作台
                <ArrowRight className="size-4" />
              </Link>
              {latestReport?.publicSlug ? (
                <Link href={`/reports/${latestReport.publicSlug}`} className="inline-flex items-center gap-2 rounded-md border border-line bg-white px-5 py-3 text-sm font-semibold text-ink shadow-soft transition hover:-translate-y-0.5 hover:border-doubao hover:text-doubao">
                  查看最新报告
                </Link>
              ) : null}
              <Link href="/doubao-research" className="inline-flex items-center gap-2 rounded-md border border-line bg-white px-5 py-3 text-sm font-semibold text-ink shadow-soft transition hover:-translate-y-0.5 hover:border-doubao hover:text-doubao">
                豆包研究中心
              </Link>
              <Link href="/getnote" className="inline-flex items-center gap-2 rounded-md border border-line bg-white px-5 py-3 text-sm font-semibold text-ink shadow-soft transition hover:-translate-y-0.5 hover:border-doubao hover:text-doubao">
                Get Note 子站
              </Link>
              <a href="#contact" className="inline-flex items-center gap-2 rounded-md border border-line bg-white px-5 py-3 text-sm font-semibold text-ink shadow-soft transition hover:-translate-y-0.5 hover:border-doubao hover:text-doubao">
                获取方案
              </a>
            </div>
          </div>

          <div className="relative animate-rise-delayed">
            <div className="absolute -inset-6 rounded-lg bg-doubao/10 blur-3xl" />
            <div className="relative overflow-hidden rounded-lg bg-panel/96 p-4 shadow-panel ring-1 ring-line/10">
              <div className="flex items-center justify-between rounded-md bg-white/70 px-4 py-3">
                <div>
                  <p className="text-xs uppercase tracking-[0.24em] text-doubao">Doubao Signal</p>
                  <h2 className="mt-2 text-2xl font-semibold">豆包答案基线</h2>
                </div>
                <Gauge className="size-8 animate-pulse text-doubao" />
              </div>
              <div className="mt-4 grid gap-3">
                {liveSignals.map((item, index) => (
                  <div key={item.name} className="rounded-md bg-white p-4 shadow-soft">
                    <div className="flex items-center justify-between text-sm">
                      <span className="font-medium">{item.name}</span>
                      <span className="font-mono text-doubao">{item.value}%</span>
                    </div>
                    <div className="mt-3 h-2 overflow-hidden rounded-full bg-ink/10">
                      <div className="h-full rounded-full bg-doubao progress-sweep" style={{ width: `${item.value}%`, animationDelay: `${index * 120}ms` }} />
                    </div>
                    <p className="mt-2 text-xs text-ink/55">{item.note}</p>
                  </div>
                ))}
              </div>
              <div className="mt-4 grid gap-3 rounded-md bg-paper p-4 text-ink md:grid-cols-[1fr_auto] md:items-center">
                <div>
                  <p className="text-sm font-semibold">真实运行状态</p>
                  <p className="mt-1 text-sm text-ink/62">
                    {state.stats.sampleCount} 条豆包采样，{state.stats.reportCount} 份报告；下一步补资料、社媒入口和 Search Console。
                  </p>
                </div>
                <MousePointer2 className="size-5 text-doubao" />
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="border-y border-line bg-white py-6">
        <div className="mx-auto grid max-w-7xl gap-3 px-4 sm:px-6 md:grid-cols-4 lg:px-8">
          {[
            ["豆包提及率", `${state.stats.mentionRate}%`, `${state.stats.sampleCount} 条真实采样`],
            ["资料与事实", `${state.stats.sourceCount}/${state.stats.brandFactCount}`, "资料 / 品牌事实"],
            ["内容与报告", `${state.stats.contentCount}/${state.stats.reportCount}`, "草稿 / 报告"],
            ["Agent", state.agentSettings?.mode === "Control" ? "控制" : "讲解", "设置里逐项授权"],
          ].map(([label, value, note]) => (
            <article key={label} className="rounded-md bg-panel p-4 ring-1 ring-line">
              <p className="text-xs font-semibold uppercase text-ink/40">{label}</p>
              <p className="mt-2 text-2xl font-semibold text-doubao">{value}</p>
              <p className="mt-1 text-xs text-ink/55">{note}</p>
            </article>
          ))}
        </div>
      </section>

      <section id="workflow" className="bg-paper py-20 text-ink">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="max-w-3xl">
            <p className="text-sm font-semibold uppercase tracking-[0.25em] text-doubao">Client workflow</p>
            <h2 className="mt-4 text-4xl font-semibold text-balance">客户不需要理解复杂系统，只要按 9 步往前走</h2>
          </div>
          <div className="mt-6 flex flex-wrap gap-3">
            <Link href="/workspace/architecture" className="inline-flex items-center gap-2 rounded-md bg-doubao px-5 py-3 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink">
              看框架联系图
              <ArrowRight className="size-4" />
            </Link>
            {latestReport?.publicSlug ? (
              <Link href={`/reports/${latestReport.publicSlug}`} className="inline-flex items-center gap-2 rounded-md bg-white px-5 py-3 text-sm font-semibold text-ink shadow-soft ring-1 ring-line transition hover:-translate-y-0.5 hover:text-doubao">
                看客户报告
              </Link>
            ) : null}
          </div>
          <div className="mt-10 grid gap-4 md:grid-cols-3">
            {workflowSteps.map((step, index) => (
              <article key={step.id} className="rounded-lg bg-white p-5 shadow-soft transition hover:-translate-y-1 hover:shadow-panel">
                <span className="font-mono text-sm text-doubao">{String(index + 1).padStart(2, "0")}</span>
                <h3 className="mt-4 text-xl font-semibold">{step.title}</h3>
                <p className="mt-3 text-sm leading-6 text-ink/65">做：{step.input}</p>
                <p className="mt-2 text-sm leading-6 text-ink">得：{step.output}</p>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section id="doubao" className="py-20">
        <div className="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[0.9fr_1.1fr] lg:px-8">
          <div>
            <Badge>Doubao-first</Badge>
            <h2 className="mt-5 text-4xl font-semibold text-balance">豆包主攻必须清晰、有效、好用</h2>
            <p className="mt-5 leading-8 text-ink/70">
              新系统把豆包放在第一优先级：问题集按中文真实问法组织，采样记录设备和环境，内容围绕“短答案直接采纳”优化。
            </p>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            {serviceSteps.map(([title, body], index) => (
              <article key={title} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line transition hover:-translate-y-1">
                <span className="font-mono text-xs text-doubao">0{index + 1}</span>
                <h3 className="mt-4 text-xl font-semibold">{title}</h3>
                <p className="mt-3 text-sm leading-6 text-ink/64">{body}</p>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section id="skills" className="bg-panel py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="flex flex-col justify-between gap-6 md:flex-row md:items-end">
            <div>
              <p className="text-sm font-semibold uppercase tracking-[0.25em] text-doubao">Skill as operating system</p>
              <h2 className="mt-4 text-4xl font-semibold">把 GEO skill 变成产品能力</h2>
            </div>
            <div className="grid grid-cols-3 gap-3 text-center text-sm">
              <div className="rounded-lg bg-white p-3 shadow-soft"><strong className="block text-2xl text-doubao">12</strong>核心 Skill</div>
              <div className="rounded-lg bg-white p-3 shadow-soft"><strong className="block text-2xl text-doubao">9</strong>客户步骤</div>
              <div className="rounded-lg bg-white p-3 shadow-soft"><strong className="block text-2xl text-doubao">3</strong>Agent 模式</div>
            </div>
          </div>
          <div className="mt-10 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
            {skills.map(([name, id, body]) => (
              <article key={id} className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line transition hover:-translate-y-1 hover:ring-doubao/40">
                <div className="flex items-center gap-3">
                  <BrainCircuit className="size-5 text-doubao" />
                  <h3 className="font-semibold">{name}</h3>
                </div>
                <p className="mt-3 font-mono text-xs text-doubao">{id}</p>
                <p className="mt-3 text-sm leading-6 text-ink/62">{body}</p>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section className="bg-paper py-20 text-ink">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <h2 className="text-4xl font-semibold">不是只做仪表盘，而是把问题解决掉</h2>
          <div className="mt-10 grid gap-4 md:grid-cols-3">
            {comparison.map(([title, focus, limit]) => (
              <article key={title} className="rounded-lg bg-white p-5 shadow-soft">
                <h3 className="text-xl font-semibold">{title}</h3>
                <p className="mt-4 flex gap-2 text-sm text-ink/70"><CheckCircle2 className="size-4 text-doubao" />{focus}</p>
                <p className="mt-3 text-sm text-ink/55">{limit}</p>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section id="contact" className="py-20">
        <div className="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[0.9fr_1.1fr] lg:px-8">
          <div>
            <Badge tone="doubao">Launch on geo.youngtuo.win</Badge>
            <h2 className="mt-5 text-4xl font-semibold text-balance">今天不建豆包共识，明天就被竞品定义</h2>
            <div className="mt-8 grid gap-3 text-sm text-ink/70">
              <p className="flex items-center gap-3"><Globe2 className="size-4 text-doubao" /> 域名沿用 geo.youngtuo.win</p>
              <p className="flex items-center gap-3"><DatabaseZap className="size-4 text-doubao" /> 资料库、事实库、监测样本互相关联</p>
              <p className="flex items-center gap-3"><Bot className="size-4 text-doubao" /> Agent 默认讲解，授权后才控制项目</p>
              <p className="flex items-center gap-3"><BarChart3 className="size-4 text-doubao" /> 分析工具未配置时给配置指导，已配置时做状态分析</p>
            </div>
          </div>
          <LeadForm />
        </div>
      </section>

      <footer className="border-t border-line py-8">
        <div className="mx-auto flex max-w-7xl flex-col justify-between gap-4 px-4 text-sm text-ink/48 sm:px-6 md:flex-row lg:px-8">
          <p>geo.youngtuo.win — Doubao-first brand consensus system.</p>
          <p>微信公众号 · 小红书 · 抖音 · X · YouTube · LinkedIn 待配置</p>
        </div>
      </footer>
    </main>
  );
}
