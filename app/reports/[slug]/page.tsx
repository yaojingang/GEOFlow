import { notFound } from "next/navigation";
import { Badge } from "@/components/Badge";
import { ReportActions } from "@/components/ReportActions";
import { prisma } from "@/lib/prisma";
import { getReportStats } from "@/lib/report-export";

type ReportPageProps = {
  params: Promise<{ slug: string }>;
};

export default async function ReportPage({ params }: ReportPageProps) {
  const { slug } = await params;
  const report = await prisma.report.findUnique({
    where: { publicSlug: slug },
    include: {
      workspace: {
        include: {
          answerSamples: { orderBy: { sampledAt: "desc" }, take: 20 },
          brandFacts: { orderBy: { confidence: "desc" }, take: 20 },
          sourceAssets: { orderBy: { createdAt: "desc" }, take: 20 },
          questionSets: { orderBy: { createdAt: "desc" }, take: 5 },
          analyticsConfigs: { orderBy: { provider: "asc" } },
          socialAccounts: { orderBy: { platform: "asc" } },
        },
      },
    },
  });

  if (!report) {
    notFound();
  }

  const stats = getReportStats(report);
  const verification = report.verification as
    | {
        checks?: Array<{ key: string; label: string; status: "pass" | "warn" | "fail"; detail: string }>;
      }
    | null;
  const configuredAnalytics = report.workspace.analyticsConfigs.filter((item) => item.status === "configured" || item.status === "active");
  const visibleSocials = report.workspace.socialAccounts.filter((item) => item.isVisible && (item.url || item.handle));
  const reportUrl = `https://geo.youngtuo.win/reports/${report.publicSlug ?? slug}`;
  const nextActions = [
    stats.sourceCount < 3 ? "补齐官网、案例、FAQ 三类资料，并重新处理资料库。" : "继续补充高质量案例和客户问题，扩大证据覆盖面。",
    stats.sampleCount < 10 ? "再运行至少 3 条真实豆包采样，形成更稳定的趋势基线。" : "按 Day 7/14/30 计划复测，观察提及率和错误事实变化。",
    configuredAnalytics.some((item) => item.provider === "Search Console")
      ? "保留 Search Console 与统计数据，后续用于收录和归因复盘。"
      : "补 Search Console 并提交 sitemap.xml，让报告具备搜索收录依据。",
    visibleSocials.length >= 3 ? "用现有社媒入口承接咨询和案例分发。" : "至少补 3 个客户可见社媒入口，方便从报告进入账号。",
  ];
  const handoffItems = [
    ["公开报告", "已生成", reportUrl],
    ["Markdown", "可导出", `/api/reports/${report.publicSlug ?? slug}/markdown`],
    ["分析工具", `${configuredAnalytics.length}/${report.workspace.analyticsConfigs.length}`, configuredAnalytics.map((item) => item.provider).join("、") || "待配置"],
    ["社媒入口", `${visibleSocials.length}/${report.workspace.socialAccounts.length}`, visibleSocials.map((item) => item.platform).join("、") || "待配置"],
  ];

  return (
    <main className="min-h-screen bg-paper text-ink">
      <section className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:py-10 print:max-w-none print:px-0">
        <header className="rounded-lg bg-white p-6 shadow-panel ring-1 ring-line print:shadow-none">
          <div className="flex flex-col justify-between gap-4 md:flex-row md:items-start">
            <div>
              <Badge tone="doubao">客户报告</Badge>
              <h1 className="mt-5 max-w-4xl text-4xl font-semibold text-balance">{report.title}</h1>
              <p className="mt-3 text-sm text-ink/55">
                {report.workspace.name} · {report.createdAt.toISOString().slice(0, 10)}
              </p>
            </div>
            <ReportActions slug={report.publicSlug ?? slug} />
          </div>
        </header>

        <section className="grid gap-4 md:grid-cols-5">
          {[
            ["豆包提及率", `${stats.mentionRate}%`],
            ["采样记录", stats.sampleCount],
            ["资料", stats.sourceCount],
            ["品牌事实", stats.factCount],
            ["问题集", stats.questionSetCount],
          ].map(([label, value]) => (
            <article key={label} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line print:shadow-none">
              <p className="text-sm text-ink/50">{label}</p>
              <p className="mt-3 text-3xl font-semibold text-doubao">{value}</p>
            </article>
          ))}
        </section>

        <section className="grid gap-4 lg:grid-cols-[1fr_360px]">
          <article className="rounded-lg bg-white p-6 shadow-soft ring-1 ring-line print:shadow-none">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="text-2xl font-semibold">执行结论</h2>
              <Badge tone={report.verificationStatus === "verified" ? "doubao" : "dark"}>
                {report.verificationStatus === "verified" ? "可交付" : "需补证据"}
              </Badge>
            </div>
            <div className="mt-4 grid gap-3">
              {nextActions.map((action, index) => (
                <div key={action} className="flex gap-3 rounded-md bg-panel p-4 ring-1 ring-line">
                  <span className="font-mono text-xs font-semibold text-doubao">{String(index + 1).padStart(2, "0")}</span>
                  <p className="text-sm leading-6 text-ink/68">{action}</p>
                </div>
              ))}
            </div>
          </article>

          <article className="rounded-lg bg-white p-6 shadow-soft ring-1 ring-line print:shadow-none">
            <h2 className="text-2xl font-semibold">交付清单</h2>
            <div className="mt-4 grid gap-3">
              {handoffItems.map(([name, status, detail]) => (
                <div key={name} className="rounded-md bg-panel p-4 ring-1 ring-line">
                  <div className="flex items-center justify-between gap-3">
                    <p className="font-medium">{name}</p>
                    <span className="text-xs font-semibold text-doubao">{status}</span>
                  </div>
                  <p className="mt-2 break-all text-xs leading-5 text-ink/58">{detail}</p>
                </div>
              ))}
            </div>
          </article>
        </section>

        <section className="rounded-lg bg-white p-6 shadow-soft ring-1 ring-line print:shadow-none">
          <h2 className="text-2xl font-semibold">摘要</h2>
          <p className="mt-4 whitespace-pre-line text-sm leading-7 text-ink/68">{report.summary}</p>
        </section>

        <section className="rounded-lg bg-white p-6 shadow-soft ring-1 ring-line print:shadow-none">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-2xl font-semibold">证据链检查</h2>
            <Badge tone={report.verificationStatus === "verified" ? "doubao" : "dark"}>{report.verificationStatus}</Badge>
          </div>
          <p className="mt-4 text-sm leading-7 text-ink/68">{report.verificationSummary ?? "尚未检查"}</p>
          {verification?.checks?.length ? (
            <div className="mt-4 grid gap-3 md:grid-cols-2">
              {verification.checks.map((check) => (
                <div key={check.key} className="rounded-md bg-panel p-4 ring-1 ring-line">
                  <Badge tone={check.status === "pass" ? "doubao" : "dark"}>{check.status}</Badge>
                  <h3 className="mt-3 font-semibold">{check.label}</h3>
                  <p className="mt-2 text-sm leading-6 text-ink/65">{check.detail}</p>
                </div>
              ))}
            </div>
          ) : null}
        </section>

        <section className="grid gap-4 lg:grid-cols-2">
          <article className="rounded-lg bg-white p-6 shadow-soft ring-1 ring-line print:shadow-none">
            <h2 className="text-2xl font-semibold">品牌事实</h2>
            <div className="mt-4 grid gap-3">
              {report.workspace.brandFacts.map((fact) => (
                <div key={fact.id} className="rounded-md bg-panel p-4 ring-1 ring-line">
                  <Badge tone="doubao">可信度 {fact.confidence}</Badge>
                  <h3 className="mt-3 font-semibold">{fact.title}</h3>
                  <p className="mt-2 text-sm leading-6 text-ink/65">{fact.body}</p>
                </div>
              ))}
            </div>
          </article>

          <article className="rounded-lg bg-white p-6 shadow-soft ring-1 ring-line print:shadow-none">
            <h2 className="text-2xl font-semibold">资料来源</h2>
            <div className="mt-4 grid gap-3">
              {report.workspace.sourceAssets.length === 0 ? (
                <p className="text-sm text-ink/55">暂无资料来源。</p>
              ) : (
                report.workspace.sourceAssets.map((source) => (
                  <div key={source.id} className="rounded-md bg-panel p-4 ring-1 ring-line">
                    <Badge tone="dark">{source.type}</Badge>
                    <h3 className="mt-3 font-semibold">{source.title}</h3>
                    {source.url ? <p className="mt-2 break-all text-sm text-doubao">{source.url}</p> : null}
                    {source.summary ? <p className="mt-2 text-sm leading-6 text-ink/65">{source.summary}</p> : null}
                  </div>
                ))
              )}
            </div>
          </article>
        </section>

        <section className="rounded-lg bg-white p-6 shadow-soft ring-1 ring-line print:shadow-none">
          <h2 className="text-2xl font-semibold">最近采样</h2>
          <div className="mt-4 grid gap-3">
            {report.workspace.answerSamples.slice(0, 8).map((sample) => (
              <article key={sample.id} className="rounded-md bg-panel p-4 ring-1 ring-line">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <h3 className="font-semibold">{sample.question}</h3>
                  <Badge tone={sample.brandMentioned ? "doubao" : "dark"}>
                    {sample.brandMentioned ? "已提及" : "未提及"}
                  </Badge>
                </div>
                <p className="mt-3 text-sm leading-6 text-ink/66">{sample.answer}</p>
              </article>
            ))}
          </div>
        </section>
      </section>
    </main>
  );
}
