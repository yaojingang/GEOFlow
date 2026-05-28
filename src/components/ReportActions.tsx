"use client";

import Link from "next/link";
import { useState } from "react";
import { Copy, Printer } from "lucide-react";

export function ReportActions({ slug }: { slug: string }) {
  const [copied, setCopied] = useState(false);

  async function copyLink() {
    const url = `${window.location.origin}/reports/${slug}`;
    await navigator.clipboard.writeText(url);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1600);
  }

  return (
    <div className="flex flex-wrap gap-2 print:hidden">
      <button
        type="button"
        onClick={() => void copyLink()}
        className="inline-flex items-center gap-2 rounded-md bg-white px-4 py-2 text-sm font-semibold text-ink ring-1 ring-line transition hover:text-doubao"
      >
        <Copy className="size-4" />
        {copied ? "已复制" : "复制链接"}
      </button>
      <Link
        href={`/api/reports/${slug}/markdown`}
        className="rounded-md bg-panel px-4 py-2 text-sm font-semibold text-ink ring-1 ring-line transition hover:text-doubao"
      >
        Markdown
      </Link>
      <button
        type="button"
        onClick={() => window.print()}
        className="inline-flex items-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao"
      >
        <Printer className="size-4" />
        打印/PDF
      </button>
    </div>
  );
}
