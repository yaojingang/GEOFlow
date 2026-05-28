"use client";

import { useState } from "react";
import { Check, Copy, Download, Loader2, Paperclip, Sparkles, X } from "lucide-react";
import type { GetNoteDraft } from "@/lib/getnote-generator";

type GetNoteGenerateResponse = GetNoteDraft & {
  markdown?: string;
};

export function GetNoteConsole() {
  const [content, setContent] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [draft, setDraft] = useState<GetNoteDraft | null>(null);
  const [markdown, setMarkdown] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [copied, setCopied] = useState(false);

  async function submit() {
    setLoading(true);
    setError("");
    setDraft(null);
    setMarkdown("");
    setCopied(false);
    try {
      const body = file ? new FormData() : JSON.stringify({ mode: "text", content, context: "文章转笔记" });
      const headers: HeadersInit = {};
      if (file && body instanceof FormData) {
        body.set("file", file);
        body.set("content", content);
        body.set("context", "文章转笔记");
      } else {
        headers["Content-Type"] = "application/json";
      }
      const response = await fetch("/api/getnote/generate", {
        method: "POST",
        headers,
        body,
      });
      const data = (await response.json()) as GetNoteGenerateResponse | { error?: string };
      if (!response.ok || isErrorResponse(data)) {
        throw new Error("error" in data && data.error ? data.error : "生成失败");
      }
      setDraft(data);
      setMarkdown(data.markdown || toMarkdown(data));
    } catch (err) {
      setError(err instanceof Error ? err.message : "生成失败");
    } finally {
      setLoading(false);
    }
  }

  async function copyMarkdown() {
    if (!markdown) return;
    try {
      await navigator.clipboard.writeText(markdown);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1600);
    } catch {
      setError("复制失败，请手动选择结果内容复制。");
    }
  }

  function downloadMarkdown() {
    if (!markdown) return;
    const blob = new Blob([markdown], { type: "text/markdown;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `${safeFileName(draft?.title)}.md`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <section className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
        <textarea
          value={content}
          onChange={(event) => setContent(event.target.value)}
          placeholder="粘贴文章、网页链接、小红书/抖音/YouTube 链接；也可以上传 PDF、DOCX、TXT、MD、HTML、JSON、CSV。"
          rows={18}
          className="w-full resize-none rounded-md border border-line bg-paper p-3 text-sm leading-6 text-ink outline-none transition placeholder:text-ink/35 focus:border-doubao focus:ring-4 focus:ring-doubao/10"
        />

        <div className="mt-3 flex items-center gap-2">
          <label className="inline-flex h-10 cursor-pointer items-center gap-2 rounded-md border border-line bg-paper px-3 text-sm font-semibold text-ink transition hover:border-doubao hover:text-doubao">
            <Paperclip className="size-4" />
            上传文件
            <input
              type="file"
              className="sr-only"
              accept=".pdf,.docx,.txt,.md,.markdown,.html,.htm,.json,.jsonl,.csv,.tsv,.xml,.log,.yaml,.yml,image/*,audio/*,video/*"
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            />
          </label>
          {file ? (
            <div className="flex min-w-0 flex-1 items-center justify-between gap-2 rounded-md bg-panel px-3 py-2 text-sm text-ink ring-1 ring-line">
              <span className="truncate">{file.name}</span>
              <button
                type="button"
                onClick={() => setFile(null)}
                className="grid size-6 shrink-0 place-items-center rounded-md text-ink/60 transition hover:bg-white hover:text-ink"
                aria-label="移除文件"
              >
                <X className="size-4" />
              </button>
            </div>
          ) : null}
        </div>

        {error ? <p className="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700 ring-1 ring-red-100">{error}</p> : null}

        <button
          type="button"
          onClick={submit}
          disabled={loading || (content.trim().length === 0 && !file)}
          className="mt-4 inline-flex h-11 w-full items-center justify-center gap-2 rounded-md bg-doubao px-4 text-sm font-semibold text-paper shadow-doubao transition hover:bg-ink disabled:cursor-not-allowed disabled:opacity-55"
        >
          {loading ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}
          生成
        </button>
      </section>

      <section className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
        {draft ? (
          <div>
            <div className="mb-3 flex flex-wrap justify-end gap-2">
              <button
                type="button"
                onClick={() => void copyMarkdown()}
                className="inline-flex h-9 items-center gap-2 rounded-md bg-panel px-3 text-sm font-semibold text-ink/70 ring-1 ring-line transition hover:text-doubao"
              >
                {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                {copied ? "已复制" : "复制"}
              </button>
              <button
                type="button"
                onClick={downloadMarkdown}
                className="inline-flex h-9 items-center gap-2 rounded-md bg-panel px-3 text-sm font-semibold text-ink/70 ring-1 ring-line transition hover:text-doubao"
              >
                <Download className="size-4" />
                下载 .md
              </button>
            </div>
            <article className="whitespace-pre-wrap text-sm leading-7 text-ink">{formatNote(draft)}</article>
          </div>
        ) : (
          <div className="flex min-h-[480px] items-center justify-center rounded-md bg-panel p-6 text-center ring-1 ring-line">
            <p className="text-sm font-semibold text-ink/52">生成结果会显示在这里</p>
          </div>
        )}
      </section>
    </div>
  );
}

function isErrorResponse(value: GetNoteGenerateResponse | { error?: string }): value is { error?: string } {
  return "error" in value;
}

function formatNote(draft: GetNoteDraft) {
  const parts = [draft.title, "", draft.summary.trim()];
  return parts.join("\n");
}

function toMarkdown(draft: GetNoteDraft) {
  const sourceLabel: Record<GetNoteDraft["source"], string> = {
    notebooklm: "NotebookLM",
    model: "GPT",
    rules: "rules",
  };
  return [`# ${draft.title.trim() || "GetNote"}`, "", draft.summary.trim(), "", `来源: ${sourceLabel[draft.source]}`].join("\n").trim();
}

function safeFileName(title?: string) {
  const cleaned = (title || "getnote").replace(/[\\/:*?"<>|]/g, "").replace(/\s+/g, "-").slice(0, 80);
  return cleaned || "getnote";
}
