"use client";

import { BookOpenCheck, CheckCircle2, RefreshCw, Search, ThumbsDown, Trash2, TrendingUp } from "lucide-react";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Badge } from "@/components/Badge";

type LessonOutcome = "worked" | "partial" | "did_not_work";

type GeoLesson = {
  id: string;
  title: string;
  tactic: string;
  scenario: string;
  outcome: string;
  verificationStatus: string;
  confidence: number;
  evidenceUrl?: string | null;
  reportId?: string | null;
  workedCount: number;
  partialCount: number;
  didNotWorkCount: number;
  notes?: string | null;
  createdAt: string;
  updatedAt: string;
};

const emptyForm = {
  title: "",
  tactic: "",
  scenario: "geo.youngtuo.win 豆包可见度优化",
  outcome: "worked" as LessonOutcome,
  evidenceUrl: "",
  reportId: "",
  notes: "",
};

const outcomeOptions: Array<{ value: LessonOutcome; label: string }> = [
  { value: "worked", label: "有效" },
  { value: "partial", label: "部分有效" },
  { value: "did_not_work", label: "无效" },
];

function statusLabel(status: string) {
  if (status === "manual_verified") return "已验证";
  if (status === "partial_verified") return "部分验证";
  if (status === "refuted") return "已否定";
  return "未验证";
}

function outcomeIcon(outcome: string) {
  if (outcome.includes("无效")) return ThumbsDown;
  if (outcome.includes("部分")) return TrendingUp;
  return CheckCircle2;
}

export function LessonsClient() {
  const [lessons, setLessons] = useState<GeoLesson[]>([]);
  const [form, setForm] = useState(emptyForm);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("读取经验库中...");

  const refresh = useCallback(async () => {
    const response = await fetch("/api/workspace/lessons", { cache: "no-store" });
    const data = (await response.json()) as { data?: GeoLesson[]; error?: string };
    setLessons(data.data ?? []);
    setStatus(data.error ?? "经验库已读取");
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void refresh(), 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);

  const filteredLessons = useMemo(() => {
    const text = query.trim().toLowerCase();
    if (!text) return lessons;
    return lessons.filter((lesson) =>
      [lesson.title, lesson.tactic, lesson.scenario, lesson.outcome, lesson.notes ?? ""].some((value) => value.toLowerCase().includes(text)),
    );
  }, [lessons, query]);

  const stats = useMemo(() => {
    return lessons.reduce(
      (acc, lesson) => {
        acc.worked += lesson.workedCount;
        acc.partial += lesson.partialCount;
        acc.failed += lesson.didNotWorkCount;
        acc.confidence += lesson.confidence;
        return acc;
      },
      { worked: 0, partial: 0, failed: 0, confidence: 0 },
    );
  }, [lessons]);

  async function saveLesson() {
    if (!form.title.trim() || !form.tactic.trim() || !form.scenario.trim()) return;
    setStatus("沉淀经验中...");

    const response = await fetch("/api/workspace/lessons", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: JSON.stringify(form),
    });
    const data = (await response.json()) as { data?: GeoLesson[]; guide?: string; error?: string };
    if (!response.ok || !data.data) {
      setStatus(data.guide ?? data.error ?? "保存失败，请检查管理员 Key");
      return;
    }
    setLessons(data.data);
    setForm(emptyForm);
    setStatus("经验已进入 Agent 记忆");
  }

  async function confirmLesson(id: string, outcome: LessonOutcome) {
    setStatus("更新验证结果中...");
    const response = await fetch("/api/workspace/lessons", {
      method: "PATCH",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: JSON.stringify({ id, outcome }),
    });
    const data = (await response.json()) as { data?: GeoLesson[]; guide?: string; error?: string };
    if (!response.ok || !data.data) {
      setStatus(data.guide ?? data.error ?? "更新失败");
      return;
    }
    setLessons(data.data);
    setStatus("验证结果已更新");
  }

  async function removeLesson(id: string) {
    setStatus("删除经验中...");
    const response = await fetch(`/api/workspace/lessons?id=${encodeURIComponent(id)}`, {
      method: "DELETE",
      headers: { "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "" },
    });
    const data = (await response.json()) as { data?: GeoLesson[]; guide?: string; error?: string };
    if (!response.ok || !data.data) {
      setStatus(data.guide ?? data.error ?? "删除失败");
      return;
    }
    setLessons(data.data);
    setStatus("经验已删除");
  }

  return (
    <section className="mt-6 grid gap-4">
      <div className="grid gap-4 md:grid-cols-4">
        {[
          ["经验数", lessons.length, "Agent 可读取"],
          ["有效反馈", stats.worked, "可优先复用"],
          ["部分有效", stats.partial, "需补证据"],
          ["无效反馈", stats.failed, "避免重复做"],
        ].map(([label, value, note]) => (
          <article key={label} className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
            <p className="text-xs uppercase tracking-[0.16em] text-ink/38">{label}</p>
            <p className="mt-3 text-3xl font-semibold text-doubao">{value}</p>
            <p className="mt-2 text-sm text-ink/52">{note}</p>
          </article>
        ))}
      </div>

      <div className="grid gap-4 xl:grid-cols-[420px_1fr]">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <div className="flex items-center justify-between gap-3">
            <h2 className="text-xl font-semibold">手动沉淀</h2>
            <Badge tone="doubao">明确反馈后写入</Badge>
          </div>
          <div className="mt-4 grid gap-3">
            <input
              value={form.title}
              onChange={(event) => setForm({ ...form, title: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="经验标题，例如 FAQ 页面提升豆包提及"
            />
            <textarea
              value={form.tactic}
              onChange={(event) => setForm({ ...form, tactic: event.target.value })}
              className="min-h-28 rounded-md border-0 bg-panel px-3 py-2 text-sm leading-6 outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="做了什么动作"
            />
            <input
              value={form.scenario}
              onChange={(event) => setForm({ ...form, scenario: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="适用场景"
            />
            <select
              value={form.outcome}
              onChange={(event) => setForm({ ...form, outcome: event.target.value as LessonOutcome })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            >
              {outcomeOptions.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label}
                </option>
              ))}
            </select>
            <input
              value={form.evidenceUrl}
              onChange={(event) => setForm({ ...form, evidenceUrl: event.target.value })}
              className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="证据 URL，可选"
            />
            <textarea
              value={form.notes}
              onChange={(event) => setForm({ ...form, notes: event.target.value })}
              className="min-h-24 rounded-md border-0 bg-panel px-3 py-2 text-sm leading-6 outline-none ring-1 ring-line focus:ring-doubao"
              placeholder="补充说明，可选"
            />
            <button
              type="button"
              onClick={() => void saveLesson()}
              className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
            >
              <BookOpenCheck className="size-4" />
              写入经验库
            </button>
            <p className="text-sm text-ink/55">{status}</p>
          </div>
        </article>

        <section className="grid content-start gap-3">
          <div className="flex flex-col gap-3 rounded-lg bg-white p-4 shadow-soft ring-1 ring-line sm:flex-row sm:items-center">
            <div className="flex flex-1 items-center gap-2 rounded-md bg-panel px-3 py-2 ring-1 ring-line">
              <Search className="size-4 text-doubao" />
              <input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                className="min-w-0 flex-1 bg-transparent text-sm outline-none"
                placeholder="搜索动作、场景、结果"
              />
            </div>
            <button
              type="button"
              onClick={() => void refresh()}
              className="inline-flex items-center justify-center gap-2 rounded-md bg-panel px-4 py-2 text-sm font-semibold text-ink ring-1 ring-line"
            >
              <RefreshCw className="size-4" />
              刷新
            </button>
          </div>

          {filteredLessons.map((lesson) => {
            const Icon = outcomeIcon(lesson.outcome);
            return (
              <article key={lesson.id} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge tone={lesson.verificationStatus === "refuted" ? "dark" : "doubao"}>{statusLabel(lesson.verificationStatus)}</Badge>
                      <span className="rounded-md bg-panel px-2 py-1 text-xs font-semibold text-ink/55 ring-1 ring-line">置信度 {lesson.confidence}</span>
                    </div>
                    <h2 className="mt-3 text-lg font-semibold text-ink">{lesson.title}</h2>
                  </div>
                  <button
                    type="button"
                    onClick={() => void removeLesson(lesson.id)}
                    className="inline-flex size-9 items-center justify-center rounded-md bg-panel text-ink/55 ring-1 ring-line transition hover:text-doubao"
                    aria-label="删除经验"
                  >
                    <Trash2 className="size-4" />
                  </button>
                </div>
                <div className="mt-4 grid gap-3 text-sm leading-6 text-ink/64">
                  <p>
                    <span className="font-semibold text-ink">场景：</span>
                    {lesson.scenario}
                  </p>
                  <p>
                    <span className="font-semibold text-ink">动作：</span>
                    {lesson.tactic}
                  </p>
                  <p className="flex items-center gap-2">
                    <Icon className="size-4 text-doubao" />
                    <span>
                      <span className="font-semibold text-ink">结果：</span>
                      {lesson.outcome}
                    </span>
                  </p>
                  {lesson.notes ? <p className="rounded-md bg-panel p-3 ring-1 ring-line">{lesson.notes}</p> : null}
                </div>
                <div className="mt-4 flex flex-wrap gap-2">
                  {outcomeOptions.map((item) => (
                    <button
                      key={item.value}
                      type="button"
                      onClick={() => void confirmLesson(lesson.id, item.value)}
                      className="rounded-md bg-panel px-3 py-2 text-xs font-semibold text-ink/65 ring-1 ring-line transition hover:text-doubao"
                    >
                      标记{item.label}
                    </button>
                  ))}
                  {lesson.evidenceUrl ? (
                    <a className="rounded-md bg-panel px-3 py-2 text-xs font-semibold text-doubao ring-1 ring-line" href={lesson.evidenceUrl} target="_blank" rel="noreferrer">
                      查看证据
                    </a>
                  ) : null}
                </div>
              </article>
            );
          })}

          {filteredLessons.length === 0 ? (
            <article className="rounded-lg bg-white p-8 text-center shadow-soft ring-1 ring-line">
              <BookOpenCheck className="mx-auto size-8 text-doubao" />
              <p className="mt-3 text-sm text-ink/58">还没有匹配的 GEO 经验。等客户反馈“这个动作有效/无效”后再沉淀，质量会更高。</p>
            </article>
          ) : null}
        </section>
      </div>
    </section>
  );
}
