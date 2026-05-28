import Link from "next/link";
import { ArrowRight, FileText, SearchCheck } from "lucide-react";
import { Badge } from "@/components/Badge";
import { KeywordExpansionClient } from "@/components/KeywordExpansionClient";
import { getWorkspaceState } from "@/lib/workspace-service";

export const dynamic = "force-dynamic";

function countMentions(text: string, terms: string[]) {
  const normalized = text.toLowerCase();
  return terms.reduce((sum, term) => {
    const needle = term.toLowerCase();
    return sum + (normalized.match(new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "g"))?.length ?? 0);
  }, 0);
}

export default async function AiIndexingPage() {
  const state = await getWorkspaceState();
  const latestReport = state.reports.find((report) => report.publicSlug);
  const corpus = [
    state.latestQuestions.join("\n"),
    state.brandFacts.map((item) => `${item.title}\n${item.body}`).join("\n"),
    state.sourceAssets.map((item) => `${item.title}\n${item.summary ?? ""}\n${item.processedText ?? ""}`).join("\n"),
    state.answerSamples.map((item) => `${item.question}\n${item.answer}`).join("\n"),
    state.contentAssets.map((item) => `${item.title}\n${item.body}`).join("\n"),
  ].join("\n");
  const keywordGroups = [
    {
      group: "品牌词",
      terms: ["geo.youngtuo.win", "geo", "品牌共识", "GEO"],
      intent: "让豆包明确识别项目名称和品牌定位。",
      action: "在首页、报告、FAQ 和案例页持续统一名称。",
    },
    {
      group: "服务词",
      terms: ["豆包优化", "AI 搜索优化", "GEO 服务", "品牌可见度", "AI 收录"],
      intent: "承接客户直接找服务商、方案和代运营的需求。",
      action: "补服务页、对比页和报价/流程型 FAQ。",
    },
    {
      group: "问题词",
      terms: ["怎么", "如何", "为什么", "第一步", "推荐"],
      intent: "覆盖豆包常见问答式入口。",
      action: "问题集继续扩展到 50-200 个真实中文问法。",
    },
    {
      group: "证据词",
      terms: ["案例", "FAQ", "证据", "报告", "Search Console", "sitemap"],
      intent: "让 AI 有可引用来源，而不是只看到营销口号。",
      action: "优先补案例、FAQ、Search Console 和可公开报告。",
    },
    {
      group: "竞品对比词",
      terms: ["竞品", "对比", "推荐排名", "首选推荐", "替代方案"],
      intent: "提高豆包在推荐服务商和比较场景里的引用概率。",
      action: "生成品牌 vs 竞品、方案选择和 Alternatives 内容。",
    },
  ].map((item) => ({
    ...item,
    mentions: countMentions(corpus, item.terms),
  }));
  const indexingSignals = [
    {
      name: "公开首页",
      status: "pass",
      detail: "首页已动态展示真实豆包状态，并保留清晰品牌主张。",
      href: "/",
    },
    {
      name: "llms.txt",
      status: "pass",
      detail: "已提供 AI crawler 可读的项目说明和核心入口。",
      href: "/llms.txt",
    },
    {
      name: "sitemap.xml",
      status: "pass",
      detail: "已生成 sitemap；本轮会补入更多公开页面。",
      href: "/sitemap.xml",
    },
    {
      name: "公开报告",
      status: latestReport?.publicSlug ? "pass" : "warn",
      detail: latestReport?.publicSlug ? `最新报告 ${latestReport.publicSlug} 可公开访问。` : "还没有可公开报告。",
      href: latestReport?.publicSlug ? `/reports/${latestReport.publicSlug}` : "/workspace/reports",
    },
    {
      name: "Search Console",
      status: state.analyticsConfigs.some((item) => item.provider === "Search Console" && item.status !== "missing") ? "pass" : "warn",
      detail: "当前仍建议补 Search Console 验证和 sitemap 提交，便于跟踪搜索收录。",
      href: "/workspace/settings",
    },
    {
      name: "内容资产",
      status: state.stats.contentCount >= 3 ? "pass" : "warn",
      detail: `${state.stats.contentCount} 篇内容草稿；建议至少补 FAQ、对比页、案例页。`,
      href: "/workspace/content",
    },
  ];
  const coverageScore = Math.min(
    100,
    Math.round(
      ((state.stats.sourceCount >= 3 ? 20 : state.stats.sourceCount * 6) +
        (state.stats.brandFactCount >= 6 ? 20 : state.stats.brandFactCount * 3) +
        (state.stats.sampleCount >= 10 ? 20 : state.stats.sampleCount * 2) +
        (state.stats.contentCount >= 3 ? 15 : state.stats.contentCount * 5) +
        (latestReport?.publicSlug ? 15 : 0) +
        (state.analyticsConfigs.some((item) => item.provider === "Search Console" && item.status !== "missing") ? 10 : 0)),
    ),
  );

  return (
    <div className="p-4 sm:p-6">
      <section className="rounded-lg bg-white p-6 shadow-panel ring-1 ring-line">
        <Badge tone="doubao">关键词与 AI 收录</Badge>
        <h1 className="mt-5 text-4xl font-semibold">关键词分析与 AI 收录分析</h1>
        <p className="mt-4 max-w-3xl text-ink/65 leading-7">
          用现有资料、问题集、豆包采样、内容和报告，判断哪些关键词已经有证据支撑，哪些公开入口更适合被搜索引擎和 AI crawler 读取。
        </p>
      </section>

      <section className="mt-6 grid gap-4 md:grid-cols-4">
        {[
          ["关键词覆盖", `${coverageScore}%`, "按资料、事实、采样、内容和报告估算"],
          ["问题词", `${state.latestQuestions.length}`, "当前豆包监测问题"],
          ["公开报告", `${state.stats.reportCount}`, latestReport?.publicSlug ? "已有可分享报告" : "待生成"],
          ["内容资产", `${state.stats.contentCount}`, "FAQ / 对比 / 案例仍需补强"],
        ].map(([label, value, note]) => (
          <article key={label} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
            <p className="text-sm text-ink/50">{label}</p>
            <p className="mt-3 text-3xl font-semibold text-doubao">{value}</p>
            <p className="mt-2 text-sm leading-6 text-ink/55">{note}</p>
          </article>
        ))}
      </section>

      <KeywordExpansionClient />

      <section className="mt-6 grid gap-4 xl:grid-cols-[1fr_380px]">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <div className="flex items-center gap-3">
            <SearchCheck className="size-5 text-doubao" />
            <h2 className="text-xl font-semibold">关键词覆盖</h2>
          </div>
          <div className="mt-5 grid gap-3">
            {keywordGroups.map((item) => (
              <div key={item.group} className="rounded-md bg-panel p-4 ring-1 ring-line">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <h3 className="font-semibold">{item.group}</h3>
                  <Badge tone={item.mentions > 0 ? "doubao" : "dark"}>{item.mentions} 次命中</Badge>
                </div>
                <p className="mt-3 text-sm leading-6 text-ink/62">{item.intent}</p>
                <div className="mt-3 flex flex-wrap gap-2">
                  {item.terms.map((term) => (
                    <span key={term} className="rounded-md bg-white px-2.5 py-1 text-xs text-ink/62 ring-1 ring-line">
                      {term}
                    </span>
                  ))}
                </div>
                <p className="mt-3 rounded-md bg-white p-3 text-sm leading-6 text-ink/62 ring-1 ring-line">建议：{item.action}</p>
              </div>
            ))}
          </div>
        </article>

        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <h2 className="text-xl font-semibold">AI 收录信号</h2>
          <div className="mt-5 grid gap-3">
            {indexingSignals.map((item) => (
              <Link key={item.name} href={item.href} className="rounded-md bg-panel p-4 ring-1 ring-line transition hover:-translate-y-0.5 hover:ring-doubao/40">
                <div className="flex items-center justify-between gap-3">
                  <h3 className="font-semibold">{item.name}</h3>
                  <Badge tone={item.status === "pass" ? "doubao" : "dark"}>{item.status === "pass" ? "已具备" : "待补强"}</Badge>
                </div>
                <p className="mt-3 text-sm leading-6 text-ink/62">{item.detail}</p>
              </Link>
            ))}
          </div>
        </article>
      </section>

      <section className="mt-6 rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <h2 className="text-xl font-semibold">下一步优先级</h2>
        <div className="mt-4 grid gap-3 md:grid-cols-3">
          {[
            ["补 Search Console", "验证 geo.youngtuo.win 并提交 sitemap.xml，后续才能看收录和查询词。", "/workspace/settings"],
            ["扩关键词问题集", "把购买、对比、品牌、行业问题扩到 50-200 个，提升豆包采样覆盖。", "/workspace/questions"],
            ["生成收录友好内容", "先做 FAQ、对比页、案例页，让 AI 有更明确可引用页面。", "/workspace/content"],
          ].map(([title, body, href]) => (
            <Link key={title} href={href} className="rounded-md bg-panel p-4 ring-1 ring-line transition hover:-translate-y-0.5 hover:ring-doubao/40">
              <FileText className="size-5 text-doubao" />
              <h3 className="mt-3 font-semibold">{title}</h3>
              <p className="mt-2 text-sm leading-6 text-ink/62">{body}</p>
              <span className="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-doubao">
                去处理
                <ArrowRight className="size-4" />
              </span>
            </Link>
          ))}
        </div>
      </section>
    </div>
  );
}
