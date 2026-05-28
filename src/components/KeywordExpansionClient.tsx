"use client";

import { useState } from "react";
import { Copy, FilePlus2, Plus, Sparkles } from "lucide-react";
import { Badge } from "@/components/Badge";
import type { KeywordExpansionResult } from "@/lib/keyword-expansion";

export function KeywordExpansionClient() {
  const [seed, setSeed] = useState("豆包 GEO 服务");
  const [industry, setIndustry] = useState("AI 搜索优化 / GEO");
  const [competitors, setCompetitors] = useState("");
  const [status, setStatus] = useState("输入种子词后生成关键词和豆包问题。");
  const [result, setResult] = useState<KeywordExpansionResult | null>(null);

  async function generate() {
    setStatus("生成关键词扩展中...");
    const response = await fetch("/api/workspace/keyword-expansion", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ seed, industry, competitors }),
    });
    const data = (await response.json()) as KeywordExpansionResult & { error?: string };
    if (!response.ok) {
      setStatus(data.error ?? "生成失败");
      return;
    }
    setResult(data);
    setStatus(data.source === "model" ? "已用 AI 模型生成关键词扩展。" : "已用规则兜底生成关键词扩展。");
  }

  async function saveAsQuestionSet() {
    if (!result) return;
    const questions = result.groups.flatMap((group) => group.questions).filter(Boolean).slice(0, 200);
    if (questions.length === 0) return;
    setStatus("保存为豆包问题集中...");
    const response = await fetch("/api/workspace/questions", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: JSON.stringify({ title: `${seed} AI 关键词扩展`, questions }),
    });
    if (!response.ok) {
      const error = (await response.json()) as { guide?: string; error?: string };
      setStatus(error.guide ?? error.error ?? "保存失败，请先在设置里输入管理员 Key。");
      return;
    }
    setStatus(`已保存 ${questions.length} 个问题为新问题集。`);
  }

  async function createContentDrafts() {
    if (!result) return;

    const adminKey = window.localStorage.getItem("geo-admin-key") ?? "";
    const drafts = result.groups.slice(0, 5).map((group) => {
      const primaryKeyword = group.keywords[0] ?? seed;
      const primaryQuestion = group.questions[0] ?? `${primaryKeyword}怎么做？`;
      const gaps = group.contentGaps.length > 0 ? group.contentGaps : ["补充可被 AI 引用的事实、流程和案例证据。"];
      const typeMap: Record<string, string> = {
        购买意图: "FAQ",
        对比意图: "对比页",
        品牌意图: "品牌事实页",
        问题意图: "FAQ",
        证据意图: "案例页",
      };
      const type = typeMap[group.group] ?? "FAQ";

      return {
        type,
        title: `${group.group}内容草稿：${primaryKeyword}`,
        targetGap: gaps[0],
        body: [
          `# ${group.group}内容草稿：${primaryKeyword}`,
          "",
          "## 目标意图",
          "",
          group.intent,
          "",
          "## 主问题",
          "",
          primaryQuestion,
          "",
          "## 覆盖关键词",
          "",
          ...group.keywords.slice(0, 10).map((keyword) => `- ${keyword}`),
          "",
          "## 内容缺口",
          "",
          ...gaps.map((gap) => `- ${gap}`),
          "",
          "## 写作要求",
          "",
          "- 用客户能直接理解的话解释问题。",
          "- 引用资料库、品牌事实和公开报告中的证据。",
          "- 结尾给出下一步操作，引导客户上传资料、运行豆包采样或查看报告。",
        ].join("\n"),
      };
    });

    setStatus("保存内容草稿中...");
    for (const draft of drafts) {
      const response = await fetch("/api/workspace/content", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "x-geo-admin-key": adminKey,
        },
        body: JSON.stringify(draft),
      });
      if (!response.ok) {
        const error = (await response.json()) as { guide?: string; error?: string };
        setStatus(error.guide ?? error.error ?? "保存失败，请先在设置里输入管理员 Key。");
        return;
      }
    }
    setStatus(`已保存 ${drafts.length} 篇内容草稿，可到内容资产继续编辑。`);
  }

  async function copyAll() {
    if (!result) return;
    const text = result.groups
      .map((group) => [`## ${group.group}`, `意图：${group.intent}`, "", "关键词：", ...group.keywords.map((item) => `- ${item}`), "", "豆包问题：", ...group.questions.map((item) => `- ${item}`), "", "内容缺口：", ...group.contentGaps.map((item) => `- ${item}`)].join("\n"))
      .join("\n\n");
    await navigator.clipboard.writeText(text);
    setStatus("已复制关键词扩展结果。");
  }

  return (
    <section className="mt-6 grid gap-4">
      <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="grid gap-3 lg:grid-cols-[1fr_1fr_1fr_auto]">
          <input
            value={seed}
            onChange={(event) => setSeed(event.target.value)}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="种子词，如 豆包 GEO 服务"
          />
          <input
            value={industry}
            onChange={(event) => setIndustry(event.target.value)}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="行业"
          />
          <input
            value={competitors}
            onChange={(event) => setCompetitors(event.target.value)}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="竞品，可用逗号分隔"
          />
          <button
            type="button"
            onClick={() => void generate()}
            className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
          >
            <Sparkles className="size-4" />
            AI 扩展
          </button>
        </div>
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-ink/55">{status}</p>
          {result ? (
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => void copyAll()}
                className="inline-flex items-center gap-2 rounded-md bg-panel px-3 py-2 text-sm font-semibold text-ink/65 ring-1 ring-line transition hover:text-doubao"
              >
                <Copy className="size-4" />
                复制
              </button>
              <button
                type="button"
                onClick={() => void saveAsQuestionSet()}
                className="inline-flex items-center gap-2 rounded-md bg-panel px-3 py-2 text-sm font-semibold text-ink/65 ring-1 ring-line transition hover:text-doubao"
              >
                <Plus className="size-4" />
                保存为问题集
              </button>
              <button
                type="button"
                onClick={() => void createContentDrafts()}
                className="inline-flex items-center gap-2 rounded-md bg-doubao px-3 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
              >
                <FilePlus2 className="size-4" />
                生成内容草稿
              </button>
            </div>
          ) : null}
        </div>
      </article>

      {result ? (
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-xl font-semibold">AI 扩展结果</h2>
            <Badge tone={result.source === "model" ? "doubao" : "dark"}>{result.source === "model" ? "AI 模型" : "规则兜底"}</Badge>
          </div>
          <div className="mt-5 grid gap-3 lg:grid-cols-2">
            {result.groups.map((group) => (
              <div key={group.group} className="rounded-md bg-panel p-4 ring-1 ring-line">
                <h3 className="font-semibold">{group.group}</h3>
                <p className="mt-2 text-sm leading-6 text-ink/60">{group.intent}</p>
                <div className="mt-3 flex flex-wrap gap-2">
                  {group.keywords.map((keyword) => (
                    <span key={keyword} className="rounded-md bg-white px-2.5 py-1 text-xs text-ink/62 ring-1 ring-line">
                      {keyword}
                    </span>
                  ))}
                </div>
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                  <div>
                    <p className="text-xs font-semibold uppercase text-ink/40">豆包问题</p>
                    <ol className="mt-2 grid gap-1 text-sm leading-6 text-ink/66">
                      {group.questions.slice(0, 6).map((question) => (
                        <li key={question}>{question}</li>
                      ))}
                    </ol>
                  </div>
                  <div>
                    <p className="text-xs font-semibold uppercase text-ink/40">内容缺口</p>
                    <ul className="mt-2 grid gap-1 text-sm leading-6 text-ink/66">
                      {group.contentGaps.map((gap) => (
                        <li key={gap}>{gap}</li>
                      ))}
                    </ul>
                  </div>
                </div>
              </div>
            ))}
          </div>
          {result.nextSteps.length > 0 ? (
            <div className="mt-5 rounded-md bg-panel p-4 ring-1 ring-line">
              <p className="font-semibold">下一步</p>
              <ul className="mt-2 grid gap-1 text-sm leading-6 text-ink/66">
                {result.nextSteps.map((step) => (
                  <li key={step}>{step}</li>
                ))}
              </ul>
            </div>
          ) : null}
        </article>
      ) : null}
    </section>
  );
}
