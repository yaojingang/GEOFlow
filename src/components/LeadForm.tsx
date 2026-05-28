"use client";

import { useState } from "react";
import { FileSearch } from "lucide-react";

const emptyForm = {
  name: "",
  phone: "",
  company: "",
  industry: "",
  goal: "",
};

export function LeadForm() {
  const [form, setForm] = useState(emptyForm);
  const [status, setStatus] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setStatus("提交中...");

    const response = await fetch("/api/leads", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(form),
    });
    const data = (await response.json()) as { error?: string; nextStep?: string; leadId?: string };
    setSubmitting(false);

    if (!response.ok) {
      setStatus(data.error ?? "提交失败，请检查信息后再试。");
      return;
    }

    setForm(emptyForm);
    setStatus(data.nextStep ?? "已收到，我们会整理初诊断清单。");
  }

  return (
    <form onSubmit={(event) => void submit(event)} className="grid gap-4 rounded-lg bg-white p-5 shadow-panel ring-1 ring-line">
      <div className="grid gap-4 sm:grid-cols-2">
        <input
          value={form.name}
          onChange={(event) => setForm({ ...form, name: event.target.value })}
          className="rounded-md border-0 bg-paper px-3 py-3 text-sm text-ink outline-none ring-1 ring-line/10 focus:ring-doubao"
          placeholder="姓名"
          required
        />
        <input
          value={form.phone}
          onChange={(event) => setForm({ ...form, phone: event.target.value })}
          className="rounded-md border-0 bg-paper px-3 py-3 text-sm text-ink outline-none ring-1 ring-line/10 focus:ring-doubao"
          placeholder="手机"
          required
        />
      </div>
      <input
        value={form.company}
        onChange={(event) => setForm({ ...form, company: event.target.value })}
        className="rounded-md border-0 bg-paper px-3 py-3 text-sm text-ink outline-none ring-1 ring-line/10 focus:ring-doubao"
        placeholder="公司"
        required
      />
      <input
        value={form.industry}
        onChange={(event) => setForm({ ...form, industry: event.target.value })}
        className="rounded-md border-0 bg-paper px-3 py-3 text-sm text-ink outline-none ring-1 ring-line/10 focus:ring-doubao"
        placeholder="行业"
        required
      />
      <textarea
        value={form.goal}
        onChange={(event) => setForm({ ...form, goal: event.target.value })}
        className="min-h-28 rounded-md border-0 bg-paper px-3 py-3 text-sm text-ink outline-none ring-1 ring-line/10 focus:ring-doubao"
        placeholder="你希望豆包在什么问题里推荐你？"
        required
      />
      <button
        type="submit"
        disabled={submitting}
        className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-5 py-3 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink disabled:cursor-not-allowed disabled:opacity-60"
      >
        {submitting ? "提交中" : "立即获取豆包 GEO 初诊断"}
        <FileSearch className="size-4" />
      </button>
      {status ? <p className="rounded-md bg-panel p-3 text-sm leading-6 text-ink/65 ring-1 ring-line">{status}</p> : null}
    </form>
  );
}
