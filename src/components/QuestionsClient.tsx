"use client";

import { Plus, Trash2 } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { Badge } from "@/components/Badge";

type QuestionSet = {
  id: string;
  title: string;
  questions: string[];
  createdAt: string;
};

type WorkspaceState = {
  questionSets: QuestionSet[];
  latestQuestions: string[];
};

export function QuestionsClient() {
  const [state, setState] = useState<WorkspaceState | null>(null);
  const [title, setTitle] = useState("豆包客户问题集");
  const [rawQuestions, setRawQuestions] = useState("");
  const [status, setStatus] = useState("读取问题集中...");

  const refresh = useCallback(async () => {
    const response = await fetch("/api/workspace/state", { cache: "no-store" });
    const data = (await response.json()) as WorkspaceState;
    setState(data);
    if (!rawQuestions) {
      setRawQuestions((data.latestQuestions ?? []).join("\n"));
    }
    setStatus("问题集已读取");
  }, [rawQuestions]);

  useEffect(() => {
    const timer = window.setTimeout(() => void refresh(), 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);

  async function saveQuestions() {
    const questions = rawQuestions
      .split("\n")
      .map((item) => item.trim())
      .filter(Boolean);

    if (questions.length === 0) return;
    setStatus("保存问题集中...");
    const response = await fetch("/api/workspace/questions", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: JSON.stringify({ title, questions }),
    });
    const data = (await response.json()) as WorkspaceState & { guide?: string; error?: string };
    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "保存失败");
      return;
    }
    setState(data);
    setStatus(`已保存 ${questions.length} 个问题`);
  }

  async function removeQuestionSet(id: string) {
    const response = await fetch(`/api/workspace/questions?id=${encodeURIComponent(id)}`, {
      method: "DELETE",
      headers: { "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "" },
    });
    const data = (await response.json()) as WorkspaceState & { guide?: string; error?: string };
    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "删除失败");
      return;
    }
    setState(data);
    setStatus("问题集已删除");
  }

  return (
    <section className="mt-6 grid gap-4 lg:grid-cols-[420px_1fr]">
      <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-xl font-semibold">编辑当前问题</h2>
          <Badge tone="doubao">{rawQuestions.split("\n").filter((item) => item.trim()).length} 个</Badge>
        </div>
        <div className="mt-4 grid gap-3">
          <input
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="问题集标题"
          />
          <textarea
            value={rawQuestions}
            onChange={(event) => setRawQuestions(event.target.value)}
            className="min-h-80 rounded-md border-0 bg-panel px-3 py-2 text-sm leading-6 outline-none ring-1 ring-line focus:ring-doubao"
            placeholder={"每行一个问题\n例如：豆包推荐 GEO 服务商时，怎样让它提到我们？"}
          />
          <button
            type="button"
            onClick={() => void saveQuestions()}
            className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
          >
            <Plus className="size-4" />
            保存为新版本
          </button>
          <p className="text-sm text-ink/55">{status}</p>
        </div>
      </article>

      <div className="grid content-start gap-3">
        {(state?.questionSets ?? []).map((set) => (
          <article key={set.id} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <Badge tone="doubao">{set.questions.length} 个问题</Badge>
                <h2 className="mt-3 text-lg font-semibold">{set.title}</h2>
              </div>
              <button
                type="button"
                onClick={() => void removeQuestionSet(set.id)}
                className="inline-flex size-9 items-center justify-center rounded-md bg-panel text-ink/55 ring-1 ring-line transition hover:text-doubao"
                aria-label="删除问题集"
              >
                <Trash2 className="size-4" />
              </button>
            </div>
            <ol className="mt-4 grid gap-2 text-sm leading-6 text-ink/66">
              {set.questions.slice(0, 8).map((question) => (
                <li key={question}>{question}</li>
              ))}
            </ol>
          </article>
        ))}
      </div>
    </section>
  );
}
