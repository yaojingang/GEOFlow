"use client";

import { Copy, FileUp, ImageIcon, Plus, Trash2 } from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
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
};

type WorkspaceState = {
  sourceAssets: SourceAsset[];
  contentAssets: Array<{
    id: string;
    body: string;
  }>;
};

const emptyForm = {
  title: "",
  url: "",
  summary: "",
};

const filters = [
  { label: "全部", keyword: "" },
  { label: "未使用", keyword: "__unused" },
  { label: "已使用", keyword: "__used" },
  { label: "官网", keyword: "官网" },
  { label: "报告", keyword: "报告" },
  { label: "小红书", keyword: "小红书" },
  { label: "抖音", keyword: "抖音" },
  { label: "公众号", keyword: "公众号" },
  { label: "案例", keyword: "案例" },
];

function isImageSource(item: SourceAsset) {
  return item.kind === "image" || Boolean(item.mimeType?.startsWith("image/"));
}

function imageSearchText(item: SourceAsset) {
  return `${item.title} ${item.summary ?? ""} ${item.type}`.toLowerCase();
}

function usageCount(image: SourceAsset, state: WorkspaceState | null) {
  if (!image.url || !state) return 0;
  return state.contentAssets.filter((content) => content.body.includes(image.url ?? "")).length;
}

export function ImageLibraryClient() {
  const [state, setState] = useState<WorkspaceState | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [status, setStatus] = useState("读取图片库中...");
  const [uploading, setUploading] = useState(false);
  const [activeFilter, setActiveFilter] = useState("全部");
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const images = useMemo(() => (state?.sourceAssets ?? []).filter(isImageSource), [state]);
  const activeKeyword = filters.find((item) => item.label === activeFilter)?.keyword ?? "";
  const visibleImages = images.filter((item) => {
    if (!activeKeyword) return true;
    if (activeKeyword === "__unused") return usageCount(item, state) === 0;
    if (activeKeyword === "__used") return usageCount(item, state) > 0;
    return imageSearchText(item).includes(activeKeyword.toLowerCase());
  });

  const refresh = useCallback(async () => {
    const response = await fetch("/api/workspace/state", { cache: "no-store" });
    const data = (await response.json()) as WorkspaceState;
    setState(data);
    setStatus("图片库已读取");
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void refresh(), 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);

  async function createImage() {
    if (!form.title.trim() || !form.url.trim()) return;
    setStatus("保存图片素材中...");
    const response = await fetch("/api/workspace/sources", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: JSON.stringify({
        type: "图片素材",
        kind: "image",
        title: form.title,
        url: form.url,
        summary: form.summary,
        status: "ready",
      }),
    });
    const data = (await response.json()) as WorkspaceState & { guide?: string; error?: string };
    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "保存失败，请先在设置里输入管理员 Key。");
      return;
    }
    setState(data);
    setForm(emptyForm);
    setStatus("图片素材已保存");
  }

  async function uploadImage(file: File | null) {
    if (!file) return;
    setUploading(true);
    setStatus("上传图片中...");

    const formData = new FormData();
    formData.set("file", file);
    formData.set("type", "图片素材");
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
      setStatus(data.guide ?? data.error ?? "上传失败，请先在设置里输入管理员 Key。");
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
      return;
    }

    setState(data.state);
    setForm(emptyForm);
    setStatus("图片已上传并进入图片库");
    setUploading(false);
    if (fileInputRef.current) fileInputRef.current.value = "";
  }

  async function removeImage(id: string) {
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
    setStatus("图片已删除");
  }

  async function copyText(text: string, label: string) {
    try {
      await navigator.clipboard.writeText(text);
      setStatus(`已复制${label}`);
    } catch {
      setStatus(`复制${label}失败，请手动选择文本复制。`);
    }
  }

  return (
    <section className="mt-6 grid gap-4 xl:grid-cols-[380px_1fr]">
      <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-xl font-semibold">新增图片</h2>
          <Badge tone="doubao">{images.length} 张</Badge>
        </div>
        <div className="mt-4 grid gap-3">
          <input
            value={form.title}
            onChange={(event) => setForm({ ...form, title: event.target.value })}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="图片标题，如 首页封面图"
          />
          <input
            value={form.url}
            onChange={(event) => setForm({ ...form, url: event.target.value })}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="https://.../image.jpg"
          />
          <textarea
            value={form.summary}
            onChange={(event) => setForm({ ...form, summary: event.target.value })}
            className="min-h-28 rounded-md border-0 bg-panel px-3 py-2 text-sm leading-6 outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="用途说明：用于官网首屏、报告配图、小红书封面等"
          />
          <button
            type="button"
            onClick={() => void createImage()}
            className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
          >
            <Plus className="size-4" />
            保存图片
          </button>
          <label className="inline-flex cursor-pointer items-center justify-center gap-2 rounded-md bg-ink px-4 py-2 text-sm font-semibold text-paper shadow-soft transition hover:-translate-y-0.5 hover:bg-doubao">
            <FileUp className="size-4" />
            {uploading ? "上传中..." : "上传图片"}
            <input
              ref={fileInputRef}
              type="file"
              accept="image/png,image/jpeg,image/webp,image/gif"
              className="sr-only"
              disabled={uploading}
              onChange={(event) => void uploadImage(event.target.files?.[0] ?? null)}
            />
          </label>
          <p className="text-sm text-ink/55">{status}</p>
        </div>
      </article>

      <div className="grid content-start gap-4">
        <article className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="font-semibold">图片筛选</h2>
              <p className="mt-1 text-sm text-ink/55">
                当前显示 {visibleImages.length} / {images.length} 张
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              {filters.map((filter) => (
                <button
                  key={filter.label}
                  type="button"
                  onClick={() => setActiveFilter(filter.label)}
                  className={`rounded-md px-3 py-2 text-sm font-semibold ring-1 transition ${
                    activeFilter === filter.label ? "bg-doubao text-paper ring-doubao shadow-doubao" : "bg-panel text-ink/65 ring-line hover:text-doubao"
                  }`}
                >
                  {filter.label}
                </button>
              ))}
            </div>
          </div>
        </article>

        <div className="grid content-start gap-4 md:grid-cols-2 2xl:grid-cols-3">
          {visibleImages.length > 0 ? (
            visibleImages.map((item) => {
              const usedCount = usageCount(item, state);
              return (
            <article key={item.id} className="overflow-hidden rounded-lg bg-white shadow-soft ring-1 ring-line">
              {item.url ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={item.url} alt={item.title} className="aspect-[16/10] w-full bg-panel object-cover" />
              ) : (
                <div className="flex aspect-[16/10] items-center justify-center bg-panel text-doubao">
                  <ImageIcon className="size-8" />
                </div>
              )}
              <div className="p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap gap-2">
                      <Badge tone="doubao">图片素材</Badge>
                      <Badge tone={usedCount > 0 ? "doubao" : "dark"}>{usedCount > 0 ? `已用于 ${usedCount} 篇` : "未使用"}</Badge>
                    </div>
                    <h3 className="mt-3 line-clamp-2 font-semibold">{item.title}</h3>
                  </div>
                  <button
                    type="button"
                    onClick={() => void removeImage(item.id)}
                    className="inline-flex size-9 shrink-0 items-center justify-center rounded-md bg-panel text-ink/55 ring-1 ring-line transition hover:text-doubao"
                    aria-label="删除图片"
                  >
                    <Trash2 className="size-4" />
                  </button>
                </div>
                {item.summary ? <p className="mt-3 line-clamp-4 text-sm leading-6 text-ink/58">{item.summary}</p> : null}
                {item.url ? <p className="mt-3 break-all text-xs text-ink/42">{item.url}</p> : null}
                {item.url ? (
                  <div className="mt-4 grid gap-2 sm:grid-cols-2">
                    <button
                      type="button"
                      onClick={() => void copyText(item.url ?? "", "图片链接")}
                      className="inline-flex items-center justify-center gap-2 rounded-md bg-panel px-3 py-2 text-sm font-semibold text-ink/65 ring-1 ring-line transition hover:text-doubao"
                    >
                      <Copy className="size-4" />
                      复制链接
                    </button>
                    <button
                      type="button"
                      onClick={() => void copyText(`![${item.title}](${item.url})`, "Markdown")}
                      className="inline-flex items-center justify-center gap-2 rounded-md bg-panel px-3 py-2 text-sm font-semibold text-ink/65 ring-1 ring-line transition hover:text-doubao"
                    >
                      <Copy className="size-4" />
                      复制 Markdown
                    </button>
                  </div>
                ) : null}
              </div>
            </article>
              );
            })
          ) : (
            <div className="rounded-lg bg-white p-6 text-sm leading-6 text-ink/55 shadow-soft ring-1 ring-line">
              {images.length === 0 ? "还没有图片素材。先添加官网封面、案例截图、报告配图或社媒封面，后续内容生产时可以直接选用。" : "当前分类下没有图片素材。"}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
