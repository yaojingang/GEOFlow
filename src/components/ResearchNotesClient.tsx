"use client";

import { Archive, FilePlus2, Globe2, Pencil, Save, Trash2 } from "lucide-react";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Badge } from "@/components/Badge";

type ResearchNote = {
  id: string;
  slug: string;
  title: string;
  excerpt: string;
  body: string;
  type: string;
  tags: string[];
  status: string;
  sourceType?: string | null;
  sourceId?: string | null;
  updatedAt: string;
};

type SourceAsset = {
  id: string;
  title: string;
  summary?: string | null;
  processedText?: string | null;
};

type AnswerSample = {
  id: string;
  question: string;
  answer: string;
  brandMentioned: boolean;
};

type Report = {
  id: string;
  title: string;
  summary: string;
  publicSlug?: string | null;
};

type ResearchState = {
  notes: ResearchNote[];
  sourceAssets: SourceAsset[];
  answerSamples: AnswerSample[];
  reports: Report[];
  tags: string[];
};

const emptyForm = {
  id: "",
  title: "",
  excerpt: "",
  body: "",
  type: "研究笔记",
  tags: "豆包, GEO",
  status: "draft",
  sourceType: "",
  sourceId: "",
};

export function ResearchNotesClient() {
  const [state, setState] = useState<ResearchState | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [adminKey, setAdminKey] = useState(() => {
    if (typeof window === "undefined") return "";
    return window.localStorage.getItem("geo-admin-key") ?? "";
  });
  const [status, setStatus] = useState("读取研究节点中...");
  const isEditing = Boolean(form.id);

  const refresh = useCallback(async () => {
    const response = await fetch("/api/workspace/research-notes", { cache: "no-store" });
    const data = (await response.json()) as ResearchState;
    setState(data);
    setStatus("研究节点已读取");
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void refresh(), 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);

  const publishedCount = useMemo(() => state?.notes.filter((note) => note.status === "published").length ?? 0, [state]);

  function editNote(note: ResearchNote) {
    setForm({
      id: note.id,
      title: note.title,
      excerpt: note.excerpt,
      body: note.body,
      type: note.type,
      tags: note.tags.join(", "),
      status: note.status,
      sourceType: note.sourceType ?? "",
      sourceId: note.sourceId ?? "",
    });
    setStatus(`正在编辑：${note.title}`);
  }

  function draftFromSource(source: SourceAsset) {
    setForm({
      ...emptyForm,
      title: `${source.title} 资料观察`,
      excerpt: source.summary ?? "从资料库沉淀出来的豆包研究节点。",
      body: `# ${source.title} 资料观察\n\n## 资料摘要\n\n${source.summary ?? "补充这份资料能证明什么。"}\n\n## 可用于豆包答案的证据\n\n${(source.processedText ?? source.summary ?? "").slice(0, 900)}\n\n## 关联问题\n\n- 豆包在回答相关问题时，是否会引用这类证据？`,
      type: "证据卡",
      tags: "豆包, 证据链, 资料库",
      sourceType: "source",
      sourceId: source.id,
    });
  }

  function draftFromSample(sample: AnswerSample) {
    setForm({
      ...emptyForm,
      title: `${sample.question.slice(0, 28)} 采样观察`,
      excerpt: sample.brandMentioned ? "这条豆包采样已经出现品牌，可继续分析描述质量。" : "这条豆包采样尚未出现品牌，适合分析内容缺口。",
      body: `# ${sample.question}\n\n## 豆包回答\n\n> ${sample.answer.slice(0, 1200)}\n\n## 初步判断\n\n- 品牌是否出现：${sample.brandMentioned ? "是" : "否"}\n- 需要检查：事实是否准确、证据是否充分、是否出现竞品。\n\n## 下一步\n\n把这条观察连接到相关资料和内容资产。`,
      type: "案例观察",
      tags: "豆包, 采样, 案例观察",
      sourceType: "answerSample",
      sourceId: sample.id,
    });
  }

  function draftFromReport(report: Report) {
    setForm({
      ...emptyForm,
      title: `${report.title} 研究摘录`,
      excerpt: report.summary,
      body: `# ${report.title} 研究摘录\n\n${report.summary}\n\n## 公开报告\n\n${report.publicSlug ? `https://geo.youngtuo.win/reports/${report.publicSlug}` : "报告尚未生成公开链接。"}\n\n## 可沉淀结论\n\n- \n- \n- `,
      type: "实验记录",
      tags: "豆包, 报告, 实验记录",
      sourceType: "report",
      sourceId: report.id,
    });
  }

  async function saveNote(nextStatus = form.status) {
    if (!form.title.trim() || !form.body.trim()) {
      setStatus("标题和正文不能为空");
      return;
    }

    window.localStorage.setItem("geo-admin-key", adminKey);
    setStatus(isEditing ? "更新研究节点中..." : "创建研究节点中...");
    const response = await fetch(isEditing ? `/api/workspace/research-notes/${form.id}` : "/api/workspace/research-notes", {
      method: isEditing ? "PATCH" : "POST",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": adminKey,
      },
      body: JSON.stringify({
        title: form.title,
        excerpt: form.excerpt,
        body: form.body,
        type: form.type,
        tags: form.tags,
        status: nextStatus,
        sourceType: form.sourceType || null,
        sourceId: form.sourceId || null,
      }),
    });
    const data = (await response.json()) as { state?: ResearchState; guide?: string; error?: string };

    if (!response.ok || !data.state) {
      setStatus(data.guide ?? data.error ?? "保存失败");
      return;
    }

    setState(data.state);
    setForm(emptyForm);
    setStatus(nextStatus === "published" ? "研究节点已发布" : "研究节点已保存");
  }

  async function removeNote(id: string) {
    setStatus("删除研究节点中...");
    const response = await fetch(`/api/workspace/research-notes/${id}`, {
      method: "DELETE",
      headers: { "x-geo-admin-key": adminKey },
    });
    const data = (await response.json()) as ResearchState & { guide?: string; error?: string };
    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "删除失败");
      return;
    }
    setState(data);
    if (form.id === id) setForm(emptyForm);
    setStatus("研究节点已删除");
  }

  async function archiveNote(note: ResearchNote) {
    setStatus("归档研究节点中...");
    const response = await fetch(`/api/workspace/research-notes/${note.id}`, {
      method: "PATCH",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": adminKey,
      },
      body: JSON.stringify({ status: "archived" }),
    });
    const data = (await response.json()) as { state?: ResearchState; guide?: string; error?: string };
    if (!response.ok || !data.state) {
      setStatus(data.guide ?? data.error ?? "归档失败");
      return;
    }
    setState(data.state);
    if (form.id === note.id) setForm(emptyForm);
    setStatus("研究节点已归档");
  }

  return (
    <section className="mt-6 grid gap-4 xl:grid-cols-[420px_1fr]">
      <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex items-center justify-between gap-3">
          <div>
            <h2 className="text-xl font-semibold">{isEditing ? "编辑研究节点" : "新增研究节点"}</h2>
            <p className="mt-2 text-sm text-ink/55">{status}</p>
          </div>
          <Badge tone="doubao">{publishedCount} 已发布</Badge>
        </div>
        <div className="mt-4 grid gap-3">
          <input
            value={adminKey}
            onChange={(event) => setAdminKey(event.target.value)}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="管理员控制 Key"
            type="password"
          />
          <div className="grid gap-3 sm:grid-cols-[1fr_130px]">
            <input
              value={form.title}
              onChange={(event) => setForm({ ...form, title: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="研究标题"
            />
            <select
              value={form.type}
              onChange={(event) => setForm({ ...form, type: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            >
              {["研究笔记", "豆包机制", "案例观察", "证据卡", "实验记录"].map((type) => (
                <option key={type}>{type}</option>
              ))}
            </select>
          </div>
          <input
            value={form.tags}
            onChange={(event) => setForm({ ...form, tags: event.target.value })}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="标签，用逗号分隔"
          />
          <textarea
            value={form.excerpt}
            onChange={(event) => setForm({ ...form, excerpt: event.target.value })}
            className="min-h-20 rounded-md border-0 bg-panel px-3 py-2 text-sm leading-6 outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="公开摘要"
          />
          <textarea
            value={form.body}
            onChange={(event) => setForm({ ...form, body: event.target.value })}
            className="min-h-96 rounded-md border-0 bg-panel px-3 py-2 font-mono text-sm leading-6 outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="Markdown 正文，支持 [[双链]]"
          />
          <div className="grid gap-2 sm:grid-cols-3">
            <button
              type="button"
              onClick={() => void saveNote("draft")}
              className="inline-flex items-center justify-center gap-2 rounded-md bg-panel px-3 py-2 text-sm font-semibold text-ink ring-1 ring-line"
            >
              <Save className="size-4" />
              存草稿
            </button>
            <button
              type="button"
              onClick={() => void saveNote("published")}
              className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-3 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
            >
              <Globe2 className="size-4" />
              发布
            </button>
            <button
              type="button"
              onClick={() => {
                setForm(emptyForm);
                setStatus("已清空表单");
              }}
              className="inline-flex items-center justify-center gap-2 rounded-md bg-white px-3 py-2 text-sm font-semibold text-ink/65 ring-1 ring-line"
            >
              <FilePlus2 className="size-4" />
              新建
            </button>
          </div>
        </div>
      </article>

      <div className="grid content-start gap-4">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <div className="flex flex-col justify-between gap-3 md:flex-row md:items-end">
            <div>
              <h2 className="text-xl font-semibold">研究节点</h2>
              <p className="mt-2 text-sm text-ink/55">草稿不会出现在公开研究中心。</p>
            </div>
            <a
              href="/doubao-research"
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center justify-center rounded-md bg-doubao px-3 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
            >
              打开公开页
            </a>
          </div>
          <div className="mt-4 grid gap-3">
            {(state?.notes ?? []).map((note) => (
              <div key={note.id} className="rounded-md bg-panel p-4 ring-1 ring-line">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap gap-2">
                      <Badge tone={note.status === "published" ? "doubao" : "dark"}>{note.status === "published" ? "已发布" : note.status === "archived" ? "已归档" : "草稿"}</Badge>
                      <Badge>{note.type}</Badge>
                    </div>
                    <h3 className="mt-3 text-lg font-semibold">{note.title}</h3>
                    <p className="mt-2 line-clamp-2 text-sm leading-6 text-ink/58">{note.excerpt}</p>
                  </div>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={() => editNote(note)}
                      className="inline-flex size-9 items-center justify-center rounded-md bg-white text-ink/60 ring-1 ring-line transition hover:text-doubao"
                      aria-label="编辑"
                    >
                      <Pencil className="size-4" />
                    </button>
                    <button
                      type="button"
                      onClick={() => void archiveNote(note)}
                      className="inline-flex size-9 items-center justify-center rounded-md bg-white text-ink/60 ring-1 ring-line transition hover:text-doubao"
                      aria-label="归档"
                    >
                      <Archive className="size-4" />
                    </button>
                    <button
                      type="button"
                      onClick={() => void removeNote(note.id)}
                      className="inline-flex size-9 items-center justify-center rounded-md bg-white text-ink/60 ring-1 ring-line transition hover:text-doubao"
                      aria-label="删除"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  </div>
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                  {note.tags.map((tag) => (
                    <span key={tag} className="rounded-md bg-white px-2 py-1 text-xs text-ink/55 ring-1 ring-line">
                      #{tag}
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </article>

        <section className="grid gap-4 lg:grid-cols-3">
          <SeedPanel title="从资料起草">
            {(state?.sourceAssets ?? []).slice(0, 5).map((source) => (
              <SeedButton key={source.id} title={source.title} body={source.summary ?? "资料库条目"} onClick={() => draftFromSource(source)} />
            ))}
          </SeedPanel>
          <SeedPanel title="从采样起草">
            {(state?.answerSamples ?? []).slice(0, 5).map((sample) => (
              <SeedButton key={sample.id} title={sample.question} body={sample.brandMentioned ? "已提及品牌" : "未提及品牌"} onClick={() => draftFromSample(sample)} />
            ))}
          </SeedPanel>
          <SeedPanel title="从报告起草">
            {(state?.reports ?? []).slice(0, 5).map((report) => (
              <SeedButton key={report.id} title={report.title} body={report.publicSlug ? "有公开报告" : "内部报告"} onClick={() => draftFromReport(report)} />
            ))}
          </SeedPanel>
        </section>
      </div>
    </section>
  );
}

function SeedPanel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <article className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
      <h2 className="font-semibold">{title}</h2>
      <div className="mt-3 grid gap-2">{children}</div>
    </article>
  );
}

function SeedButton({ title, body, onClick }: { title: string; body: string; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="rounded-md bg-panel p-3 text-left ring-1 ring-line transition hover:bg-doubao/10 hover:ring-doubao/35">
      <p className="line-clamp-1 text-sm font-semibold">{title}</p>
      <p className="mt-1 line-clamp-2 text-xs leading-5 text-ink/52">{body}</p>
    </button>
  );
}
