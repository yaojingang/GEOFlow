"use client";

import { Plus, Trash2 } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { Badge } from "@/components/Badge";

type BrandFact = {
  id: string;
  title: string;
  body: string;
  evidenceUrl?: string | null;
  confidence: number;
};

type WorkspaceState = {
  brandFacts: BrandFact[];
  stats: { brandFactCount: number };
};

const emptyForm = { title: "", body: "", evidenceUrl: "", confidence: 80 };

export function BrandFactsClient() {
  const [state, setState] = useState<WorkspaceState | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [status, setStatus] = useState("读取品牌事实中...");

  const refresh = useCallback(async () => {
    const response = await fetch("/api/workspace/state", { cache: "no-store" });
    const data = (await response.json()) as WorkspaceState;
    setState(data);
    setStatus("品牌事实已读取");
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void refresh(), 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);

  async function createFact() {
    if (!form.title.trim() || !form.body.trim()) return;
    setStatus("保存品牌事实中...");
    const response = await fetch("/api/workspace/brand-facts", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: JSON.stringify(form),
    });
    const data = (await response.json()) as WorkspaceState & { guide?: string; error?: string };
    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "保存失败");
      return;
    }
    setState(data);
    setForm(emptyForm);
    setStatus("品牌事实已保存");
  }

  async function removeFact(id: string) {
    const response = await fetch(`/api/workspace/brand-facts?id=${encodeURIComponent(id)}`, {
      method: "DELETE",
      headers: { "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "" },
    });
    const data = (await response.json()) as WorkspaceState & { guide?: string; error?: string };
    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "删除失败");
      return;
    }
    setState(data);
    setStatus("品牌事实已删除");
  }

  return (
    <section className="mt-6 grid gap-4 lg:grid-cols-[360px_1fr]">
      <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-xl font-semibold">新增事实</h2>
          <Badge tone="doubao">{state?.stats.brandFactCount ?? 0} 条</Badge>
        </div>
        <div className="mt-4 grid gap-3">
          <input
            value={form.title}
            onChange={(event) => setForm({ ...form, title: event.target.value })}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="事实标题"
          />
          <textarea
            value={form.body}
            onChange={(event) => setForm({ ...form, body: event.target.value })}
            className="min-h-28 rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="事实内容、优势、边界或禁用说法"
          />
          <input
            value={form.evidenceUrl}
            onChange={(event) => setForm({ ...form, evidenceUrl: event.target.value })}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="证据链接"
          />
          <label className="grid gap-2 text-sm text-ink/62">
            可信度 {form.confidence}
            <input
              type="range"
              min="0"
              max="100"
              value={form.confidence}
              onChange={(event) => setForm({ ...form, confidence: Number(event.target.value) })}
              className="accent-doubao"
            />
          </label>
          <button
            type="button"
            onClick={() => void createFact()}
            className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
          >
            <Plus className="size-4" />
            保存事实
          </button>
          <p className="text-sm text-ink/55">{status}</p>
        </div>
      </article>

      <div className="grid content-start gap-3">
        {(state?.brandFacts ?? []).map((item) => (
          <article key={item.id} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <Badge tone="doubao">可信度 {item.confidence}</Badge>
                <h2 className="mt-3 text-lg font-semibold">{item.title}</h2>
              </div>
              <button
                type="button"
                onClick={() => void removeFact(item.id)}
                className="inline-flex size-9 items-center justify-center rounded-md bg-panel text-ink/55 ring-1 ring-line transition hover:text-doubao"
                aria-label="删除品牌事实"
              >
                <Trash2 className="size-4" />
              </button>
            </div>
            <p className="mt-3 text-sm leading-6 text-ink/66">{item.body}</p>
            {item.evidenceUrl ? <p className="mt-3 break-all text-sm text-doubao">{item.evidenceUrl}</p> : null}
          </article>
        ))}
      </div>
    </section>
  );
}
