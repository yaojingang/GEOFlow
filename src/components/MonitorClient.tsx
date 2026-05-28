"use client";

import { Play, RefreshCw } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { Badge } from "@/components/Badge";

type Sample = {
  id: string;
  question: string;
  answer: string;
  brandMentioned: boolean;
  sampledAt: string;
};

type WorkspaceState = {
  stats: {
    mentionRate: number;
    sampleCount: number;
  };
  answerSamples: Sample[];
  agentSettings: {
    mode: string;
    canRunDoubaoSampling: boolean;
    requireConfirmation: boolean;
  } | null;
};

export function MonitorClient() {
  const [state, setState] = useState<WorkspaceState | null>(null);
  const [status, setStatus] = useState("读取采样记录中...");
  const [isRunning, setIsRunning] = useState(false);

  const refresh = useCallback(async () => {
    const response = await fetch("/api/workspace/state", { cache: "no-store" });
    const data = (await response.json()) as WorkspaceState;
    setState(data);
    setStatus("采样记录已读取");
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void refresh();
    }, 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);

  async function runSampling() {
    setIsRunning(true);
    setStatus("运行豆包采样中...");
    const response = await fetch("/api/workspace/doubao-sampling", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: JSON.stringify({ limit: 5, confirmed: true }),
    });
    const data = (await response.json()) as { error?: string; guide?: string; samples?: Sample[] };

    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "采样失败");
      setIsRunning(false);
      return;
    }

    setStatus(`已写入 ${data.samples?.length ?? 0} 条采样记录`);
    await refresh();
    setIsRunning(false);
  }

  const canRun = state?.agentSettings?.mode === "Control" && state.agentSettings.canRunDoubaoSampling;

  return (
    <section className="mt-6 grid gap-4">
      <div className="grid gap-4 md:grid-cols-3">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <p className="text-sm text-ink/52">豆包提及率</p>
          <p className="mt-3 text-4xl font-semibold text-doubao">{state?.stats.mentionRate ?? 0}%</p>
        </article>
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <p className="text-sm text-ink/52">采样记录</p>
          <p className="mt-3 text-4xl font-semibold text-doubao">{state?.stats.sampleCount ?? 0}</p>
        </article>
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <p className="text-sm text-ink/52">权限状态</p>
          <div className="mt-4">
            <Badge tone={canRun ? "doubao" : "dark"}>{canRun ? "可运行采样" : "需设置授权"}</Badge>
          </div>
        </article>
      </div>

      <div className="flex flex-col justify-between gap-3 rounded-lg bg-white p-5 shadow-soft ring-1 ring-line md:flex-row md:items-center">
        <p className="text-sm text-ink/65">{status}</p>
        <div className="flex gap-2">
          <button
            type="button"
            onClick={() => void refresh()}
            className="inline-flex items-center gap-2 rounded-md bg-panel px-4 py-2 text-sm font-semibold text-ink ring-1 ring-line"
          >
            <RefreshCw className="size-4" />
            刷新
          </button>
          <button
            type="button"
            onClick={() => void runSampling()}
            disabled={!canRun || isRunning}
            className="inline-flex items-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink disabled:cursor-not-allowed disabled:bg-ink/20 disabled:text-ink/45 disabled:shadow-none"
          >
            <Play className="size-4" />
            运行 5 条采样
          </button>
        </div>
      </div>

      <div className="grid gap-3">
        {(state?.answerSamples ?? []).map((sample) => (
          <article key={sample.id} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="font-semibold">{sample.question}</h2>
              <Badge tone={sample.brandMentioned ? "doubao" : "dark"}>{sample.brandMentioned ? "已提及" : "未提及"}</Badge>
            </div>
            <p className="mt-3 line-clamp-4 text-sm leading-6 text-ink/66">{sample.answer}</p>
          </article>
        ))}
      </div>
    </section>
  );
}
