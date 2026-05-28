"use client";

import { ExternalLink, FileDown, FilePlus2, RefreshCw } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { Badge } from "@/components/Badge";

type Report = {
  id: string;
  title: string;
  status: string;
  summary: string;
  publicSlug?: string | null;
  createdAt: string;
};

type WorkspaceState = {
  reports: Report[];
  stats: {
    reportCount: number;
    sampleCount: number;
    mentionRate: number;
  };
  agentSettings: {
    mode: string;
    canGenerateReports: boolean;
  } | null;
};

export function ReportsClient() {
  const [state, setState] = useState<WorkspaceState | null>(null);
  const [status, setStatus] = useState("读取报告中...");
  const [isGenerating, setIsGenerating] = useState(false);

  const refresh = useCallback(async () => {
    const response = await fetch("/api/workspace/state", { cache: "no-store" });
    const data = (await response.json()) as WorkspaceState;
    setState(data);
    setStatus("报告记录已读取");
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void refresh();
    }, 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);

  async function generate() {
    setIsGenerating(true);
    setStatus("生成诊断报告中...");
    const response = await fetch("/api/workspace/reports", {
      method: "POST",
      headers: { "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "" },
    });
    const data = (await response.json()) as { error?: string; guide?: string; report?: Report };

    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "报告生成失败");
      setIsGenerating(false);
      return;
    }

    setStatus(`已生成：${data.report?.title ?? "诊断报告"}`);
    await refresh();
    setIsGenerating(false);
  }

  const canGenerate = state?.agentSettings?.mode === "Control" && state.agentSettings.canGenerateReports;

  return (
    <section className="mt-6 grid gap-4">
      <div className="grid gap-4 md:grid-cols-3">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <p className="text-sm text-ink/52">报告数量</p>
          <p className="mt-3 text-4xl font-semibold text-doubao">{state?.stats.reportCount ?? 0}</p>
        </article>
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <p className="text-sm text-ink/52">可用采样</p>
          <p className="mt-3 text-4xl font-semibold text-doubao">{state?.stats.sampleCount ?? 0}</p>
        </article>
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <p className="text-sm text-ink/52">豆包提及率</p>
          <p className="mt-3 text-4xl font-semibold text-doubao">{state?.stats.mentionRate ?? 0}%</p>
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
            onClick={() => void generate()}
            disabled={!canGenerate || isGenerating}
            className="inline-flex items-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink disabled:cursor-not-allowed disabled:bg-ink/20 disabled:text-ink/45 disabled:shadow-none"
          >
            <FilePlus2 className="size-4" />
            生成报告
          </button>
        </div>
      </div>

      <div className="grid gap-3">
        {(state?.reports ?? []).map((report) => (
          <article key={report.id} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="font-semibold">{report.title}</h2>
              <Badge tone="doubao">{report.status}</Badge>
            </div>
            <p className="mt-3 whitespace-pre-line text-sm leading-6 text-ink/66">{report.summary}</p>
            {report.publicSlug ? (
              <div className="mt-4 flex flex-wrap gap-2">
                <a
                  href={`/reports/${report.publicSlug}`}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-2 rounded-md bg-doubao px-3 py-2 text-sm font-semibold text-paper shadow-doubao"
                >
                  <ExternalLink className="size-4" />
                  公开报告
                </a>
                <a
                  href={`/api/reports/${report.publicSlug}/markdown`}
                  className="inline-flex items-center gap-2 rounded-md bg-panel px-3 py-2 text-sm font-semibold text-ink ring-1 ring-line"
                >
                  <FileDown className="size-4" />
                  Markdown
                </a>
              </div>
            ) : null}
          </article>
        ))}
      </div>
    </section>
  );
}
