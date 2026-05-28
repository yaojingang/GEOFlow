"use client";

import { FileUp, Plus, RotateCw, Trash2 } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";
import { Badge } from "@/components/Badge";

type SourceAsset = {
  id: string;
  type: string;
  kind?: string | null;
  title: string;
  url?: string | null;
  status: string;
  summary?: string | null;
  mimeType?: string | null;
  metadata?: {
    parseMode?: string;
    textLength?: number;
    retrievalScopes?: string[];
    fileName?: string;
    fileSize?: number;
    ocrStatus?: string;
    totalPages?: number;
    pageCitations?: { page: number; excerpt: string; textLength: number }[];
  } | null;
};

type WorkspaceState = {
  sourceAssets: SourceAsset[];
  stats: { sourceCount: number; brandFactCount?: number };
};

const emptyForm = { type: "官网链接", title: "", url: "", summary: "" };

const kindLabels: Record<string, string> = {
  brand: "品牌事实",
  proof: "可信证据",
  faq: "FAQ 问答",
  competitor: "竞品对比",
  case: "客户案例",
  social: "社媒素材",
  image: "图片素材",
  analytics: "分析工具",
  policy: "政策/规则",
};

export function SourcesClient() {
  const [state, setState] = useState<WorkspaceState | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [status, setStatus] = useState("读取资料库中...");
  const [processingId, setProcessingId] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const refresh = useCallback(async () => {
    const response = await fetch("/api/workspace/state", { cache: "no-store" });
    const data = (await response.json()) as WorkspaceState;
    setState(data);
    setStatus("资料库已读取");
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void refresh(), 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);

  async function createSource() {
    if (!form.title.trim()) return;
    setStatus("保存资料中...");
    const response = await fetch("/api/workspace/sources", {
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
    setStatus("资料已保存");
  }

  async function removeSource(id: string) {
    const response = await fetch(`/api/workspace/sources?id=${encodeURIComponent(id)}`, {
      method: "DELETE",
      headers: { "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "" },
    });
    const data = (await response.json()) as WorkspaceState & { guide?: string; error?: string };
    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "删除失败");
      return;
    }
    setState(data);
    setStatus("资料已删除");
  }

  async function processSource(id: string) {
    setProcessingId(id);
    setStatus("处理资料中...");
    const response = await fetch("/api/workspace/sources/process", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: JSON.stringify({ id }),
    });
    const data = (await response.json()) as { state?: WorkspaceState; guide?: string; error?: string };

    if (!response.ok || !data.state) {
      setStatus(data.guide ?? data.error ?? "处理失败");
      setProcessingId(null);
      return;
    }

    setState(data.state);
    setStatus(`资料已处理，品牌事实 ${data.state.stats.brandFactCount ?? 0} 条`);
    setProcessingId(null);
  }

  async function uploadSource(file: File | null) {
    if (!file) return;
    setUploading(true);
    setStatus("上传并解析文件中...");

    const formData = new FormData();
    formData.set("file", file);
    formData.set("type", form.type || "本地资料");
    formData.set("title", form.title.trim() || file.name);
    formData.set("summary", form.summary.trim());

    const response = await fetch("/api/workspace/sources/upload", {
      method: "POST",
      headers: {
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: formData,
    });
    const data = (await response.json()) as { state?: WorkspaceState; guide?: string; error?: string };

    if (!response.ok || !data.state) {
      setStatus(data.guide ?? data.error ?? "上传失败");
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
      return;
    }

    setState(data.state);
    setForm(emptyForm);
    setStatus("文件已进入资料库");
    setUploading(false);
    if (fileInputRef.current) fileInputRef.current.value = "";
  }

  return (
    <section className="mt-6 grid gap-4">
      <div className="grid gap-4 lg:grid-cols-[360px_1fr]">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <div className="flex items-center justify-between gap-3">
            <h2 className="text-xl font-semibold">新增资料</h2>
            <Badge tone="doubao">{state?.stats.sourceCount ?? 0} 条</Badge>
          </div>
          <div className="mt-4 grid gap-3">
            <select
              value={form.type}
              onChange={(event) => setForm({ ...form, type: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            >
              {["官网链接", "产品手册", "客户案例", "FAQ", "社媒链接", "过往文章"].map((item) => (
                <option key={item}>{item}</option>
              ))}
            </select>
            <input
              value={form.title}
              onChange={(event) => setForm({ ...form, title: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="资料标题"
            />
            <input
              value={form.url}
              onChange={(event) => setForm({ ...form, url: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="https://..."
            />
            <textarea
              value={form.summary}
              onChange={(event) => setForm({ ...form, summary: event.target.value })}
              className="min-h-24 rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="这份资料能证明什么？"
            />
            <button
              type="button"
              onClick={() => void createSource()}
              className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
            >
              <Plus className="size-4" />
              保存资料
            </button>
            <label className="inline-flex cursor-pointer items-center justify-center gap-2 rounded-md bg-ink px-4 py-2 text-sm font-semibold text-paper shadow-soft transition hover:-translate-y-0.5 hover:bg-doubao">
              <FileUp className="size-4" />
              {uploading ? "上传中..." : "上传文件"}
              <input
                ref={fileInputRef}
                type="file"
                accept=".pdf,.txt,.md,.csv,.json,.html,.htm,text/*,application/pdf,application/json"
                className="sr-only"
                disabled={uploading}
                onChange={(event) => void uploadSource(event.target.files?.[0] ?? null)}
              />
            </label>
            <p className="text-sm text-ink/55">{status}</p>
          </div>
        </article>

        <div className="grid content-start gap-3">
          {(state?.sourceAssets ?? []).map((item) => (
            <article key={item.id} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <div className="flex flex-wrap gap-2">
                    <Badge tone="dark">{item.type}</Badge>
                    <Badge tone="doubao">{kindLabels[item.kind ?? "brand"] ?? "品牌事实"}</Badge>
                  </div>
                  <h2 className="mt-3 text-lg font-semibold">{item.title}</h2>
                </div>
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => void processSource(item.id)}
                    disabled={processingId === item.id}
                    className="inline-flex items-center gap-2 rounded-md bg-doubao px-3 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink disabled:cursor-not-allowed disabled:bg-ink/20 disabled:text-ink/45 disabled:shadow-none"
                  >
                    <RotateCw className={`size-4 ${processingId === item.id ? "animate-spin" : ""}`} />
                    处理资料
                  </button>
                  <button
                    type="button"
                    onClick={() => void removeSource(item.id)}
                    className="inline-flex size-9 items-center justify-center rounded-md bg-panel text-ink/55 ring-1 ring-line transition hover:text-doubao"
                    aria-label="删除资料"
                  >
                    <Trash2 className="size-4" />
                  </button>
                </div>
              </div>
              <div className="mt-3 flex flex-wrap gap-3 text-xs text-ink/45">
                <span>状态：{item.status}</span>
                {item.mimeType ? <span>类型：{item.mimeType}</span> : null}
                {item.metadata?.fileName ? <span>文件：{item.metadata.fileName}</span> : null}
                {item.metadata?.textLength ? <span>正文：{item.metadata.textLength.toLocaleString()} 字符</span> : null}
                {item.metadata?.totalPages ? <span>页数：{item.metadata.totalPages}</span> : null}
                {item.metadata?.parseMode ? <span>解析：{item.metadata.parseMode}</span> : null}
                {item.metadata?.ocrStatus === "required" ? <span>OCR：待处理</span> : null}
              </div>
              {item.url ? <p className="mt-3 break-all text-sm text-doubao">{item.url}</p> : null}
              {item.summary ? <p className="mt-3 line-clamp-8 text-sm leading-6 text-ink/62">{item.summary}</p> : null}
              {item.metadata?.retrievalScopes?.length ? (
                <p className="mt-3 text-xs text-ink/45">路由：{item.metadata.retrievalScopes.join(" / ")}</p>
              ) : null}
              {item.metadata?.pageCitations?.length ? (
                <div className="mt-3 grid gap-2 rounded-md bg-panel p-3 text-xs text-ink/55 ring-1 ring-line">
                  <p className="font-semibold text-ink/70">页码线索</p>
                  {item.metadata.pageCitations.slice(0, 3).map((citation) => (
                    <p key={citation.page} className="leading-5">
                      P{citation.page}：{citation.excerpt}
                    </p>
                  ))}
                </div>
              ) : null}
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
