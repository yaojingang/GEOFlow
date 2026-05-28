"use client";

import { FileText, ImageIcon, Plus, Sparkles, Trash2 } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { Badge } from "@/components/Badge";

type ContentAsset = {
  id: string;
  type: string;
  title: string;
  status: string;
  body: string;
  targetGap?: string | null;
};

type WorkspaceState = {
  contentAssets: ContentAsset[];
  sourceAssets: Array<{
    id: string;
    kind?: string | null;
    title: string;
    url?: string | null;
    mimeType?: string | null;
    summary?: string | null;
  }>;
  stats: { contentCount: number };
};

const emptyForm = {
  type: "FAQ",
  title: "",
  body: "",
  targetGap: "",
};

function extractMarkdownImages(markdown: string) {
  return [...markdown.matchAll(/!\[([^\]]*)\]\(([^)\s]+)\)/g)]
    .map((match) => ({
      alt: match[1]?.trim() || "内容配图",
      url: match[2]?.trim() || "",
    }))
    .filter((item) => item.url)
    .slice(0, 3);
}

function stripMarkdownImages(markdown: string) {
  return markdown.replace(/!\[[^\]]*\]\([^)]+\)/g, "").trim();
}

export function ContentClient() {
  const [state, setState] = useState<WorkspaceState | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [status, setStatus] = useState("读取内容资产中...");
  const [selectedImageId, setSelectedImageId] = useState<string>("");
  const imageAssets = (state?.sourceAssets ?? []).filter((item) => item.kind === "image" || Boolean(item.mimeType?.startsWith("image/")));
  const selectedImage = imageAssets.find((item) => item.id === selectedImageId);

  const refresh = useCallback(async () => {
    const response = await fetch("/api/workspace/state", { cache: "no-store" });
    const data = (await response.json()) as WorkspaceState;
    setState(data);
    setStatus("内容资产已读取");
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void refresh(), 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);

  async function saveContent(generate = false) {
    if (!generate && (!form.title.trim() || !form.body.trim())) return;
    setStatus(generate ? "生成内容草稿中..." : "保存内容中...");

    const response = await fetch("/api/workspace/content", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: JSON.stringify({ ...form, generate, imageId: generate ? selectedImageId || undefined : undefined }),
    });
    const data = (await response.json()) as WorkspaceState & { guide?: string; error?: string };
    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "保存失败");
      return;
    }
    setState(data);
    if (!generate) setForm(emptyForm);
    setStatus(generate ? `已生成内容草稿${selectedImage ? `，首图：${selectedImage.title}` : ""}` : "内容已保存");
  }

  async function removeContent(id: string) {
    const response = await fetch(`/api/workspace/content?id=${encodeURIComponent(id)}`, {
      method: "DELETE",
      headers: { "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "" },
    });
    const data = (await response.json()) as WorkspaceState & { guide?: string; error?: string };
    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "删除失败");
      return;
    }
    setState(data);
    setStatus("内容已删除");
  }

  function insertImage(title: string, url?: string | null) {
    if (!url) return;
    const markdown = `![${title}](${url})`;
    setForm((current) => ({
      ...current,
      body: current.body.trim() ? `${current.body.trim()}\n\n${markdown}\n` : `${markdown}\n`,
    }));
    setStatus(`已插入图片：${title}`);
  }

  return (
    <section className="mt-6 grid gap-4">
      <div className="grid gap-4 xl:grid-cols-[420px_1fr]">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <div className="flex items-center justify-between gap-3">
            <h2 className="text-xl font-semibold">新增内容</h2>
            <Badge tone="doubao">{state?.stats.contentCount ?? 0} 篇</Badge>
          </div>
          <div className="mt-4 grid gap-3">
            <select
              value={form.type}
              onChange={(event) => setForm({ ...form, type: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            >
              {["FAQ", "对比页", "品牌事实页", "案例页", "社媒短内容"].map((item) => (
                <option key={item}>{item}</option>
              ))}
            </select>
            <input
              value={form.title}
              onChange={(event) => setForm({ ...form, title: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="内容标题"
            />
            <input
              value={form.targetGap}
              onChange={(event) => setForm({ ...form, targetGap: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="对应内容缺口"
            />
            <textarea
              value={form.body}
              onChange={(event) => setForm({ ...form, body: event.target.value })}
              className="min-h-72 rounded-md border-0 bg-panel px-3 py-2 text-sm leading-6 outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="正文 Markdown"
            />
            <div className="rounded-md bg-panel p-3 ring-1 ring-line">
              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2 text-sm font-semibold text-ink/70">
                  <ImageIcon className="size-4 text-doubao" />
                  从图片库插入
                </div>
                <Badge tone={imageAssets.length > 0 ? "doubao" : "dark"}>{imageAssets.length} 张</Badge>
              </div>
              {selectedImage ? (
                <p className="mt-2 rounded-md bg-white px-3 py-2 text-xs text-ink/55 ring-1 ring-line">生成首图：{selectedImage.title}</p>
              ) : null}
              {imageAssets.length > 0 ? (
                <div className="mt-3 grid max-h-56 gap-2 overflow-y-auto pr-1">
                  {imageAssets.slice(0, 8).map((image) => (
                    <div
                      key={image.id}
                      className={`grid grid-cols-[56px_1fr] items-center gap-3 rounded-md bg-white p-2 ring-1 transition ${
                        selectedImageId === image.id ? "ring-doubao" : "ring-line hover:ring-doubao/40"
                      }`}
                    >
                      {image.url ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={image.url} alt={image.title} className="aspect-square w-14 rounded-md bg-panel object-cover" />
                      ) : (
                        <span className="flex aspect-square w-14 items-center justify-center rounded-md bg-panel text-doubao">
                          <ImageIcon className="size-5" />
                        </span>
                      )}
                      <span className="min-w-0">
                        <span className="block line-clamp-1 text-sm font-semibold text-ink/70">{image.title}</span>
                        <span className="mt-1 block line-clamp-1 text-xs text-ink/42">{image.summary ?? "插入为 Markdown 图片"}</span>
                        <span className="mt-2 grid gap-2 sm:grid-cols-2">
                          <button
                            type="button"
                            onClick={() => insertImage(image.title, image.url)}
                            className="rounded-md bg-panel px-2 py-1.5 text-xs font-semibold text-ink/65 ring-1 ring-line transition hover:text-doubao"
                          >
                            插入正文
                          </button>
                          <button
                            type="button"
                            onClick={() => {
                              setSelectedImageId(image.id);
                              setStatus(`已设为生成首图：${image.title}`);
                            }}
                            className="rounded-md bg-panel px-2 py-1.5 text-xs font-semibold text-ink/65 ring-1 ring-line transition hover:text-doubao"
                          >
                            设为首图
                          </button>
                        </span>
                      </span>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="mt-3 text-sm leading-6 text-ink/55">图片库暂无素材，先到图片库上传或登记封面、截图、报告配图。</p>
              )}
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
              <button
                type="button"
                onClick={() => void saveContent(true)}
                className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
              >
                <Sparkles className="size-4" />
                按资料生成
              </button>
              <button
                type="button"
                onClick={() => void saveContent(false)}
                className="inline-flex items-center justify-center gap-2 rounded-md bg-panel px-4 py-2 text-sm font-semibold text-ink ring-1 ring-line"
              >
                <Plus className="size-4" />
                保存草稿
              </button>
            </div>
            <p className="text-sm text-ink/55">{status}</p>
          </div>
        </article>

        <div className="grid content-start gap-3">
          {(state?.contentAssets ?? []).map((item) => {
            const markdownImages = extractMarkdownImages(item.body);
            const previewText = stripMarkdownImages(item.body);

            return (
              <article key={item.id} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <div className="flex flex-wrap gap-2">
                      <Badge tone="doubao">{item.type}</Badge>
                      {markdownImages.length > 0 ? <Badge tone="dark">{markdownImages.length} 张配图</Badge> : null}
                    </div>
                    <h2 className="mt-3 text-lg font-semibold">{item.title}</h2>
                  </div>
                  <button
                    type="button"
                    onClick={() => void removeContent(item.id)}
                    className="inline-flex size-9 items-center justify-center rounded-md bg-panel text-ink/55 ring-1 ring-line transition hover:text-doubao"
                    aria-label="删除内容"
                  >
                    <Trash2 className="size-4" />
                  </button>
                </div>
                {item.targetGap ? <p className="mt-3 text-sm text-ink/55">缺口：{item.targetGap}</p> : null}
                {markdownImages.length > 0 ? (
                  <div className="mt-4 grid gap-2 sm:grid-cols-3">
                    {markdownImages.map((image) => (
                      <figure key={`${item.id}-${image.url}`} className="overflow-hidden rounded-md bg-panel ring-1 ring-line">
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={image.url} alt={image.alt} className="aspect-[4/3] w-full object-cover" />
                        <figcaption className="line-clamp-1 px-2 py-1.5 text-xs text-ink/50">{image.alt}</figcaption>
                      </figure>
                    ))}
                  </div>
                ) : null}
                <div className="mt-4 rounded-md bg-panel p-4 ring-1 ring-line">
                  <div className="flex items-center gap-2 text-sm font-semibold text-ink/70">
                    <FileText className="size-4 text-doubao" />
                    草稿预览
                  </div>
                  <p className="mt-3 line-clamp-6 whitespace-pre-line text-sm leading-6 text-ink/65">{previewText || item.body}</p>
                </div>
              </article>
            );
          })}
        </div>
      </div>
    </section>
  );
}
