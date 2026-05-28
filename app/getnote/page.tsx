import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { GetNoteConsole } from "@/components/GetNoteConsole";

export const metadata = {
  title: "Get Note | geo.youngtuo.win",
  description: "生成 Get Note 笔记草稿。",
};

export default function GetNotePage() {
  return (
    <main className="min-h-screen bg-paper text-ink">
      <header className="border-b border-line bg-white">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
          <div>
            <p className="text-lg font-semibold">Get Note</p>
            <p className="mt-1 text-sm text-ink/52">生成笔记草稿</p>
          </div>
          <Link href="/" className="inline-flex items-center gap-2 rounded-md bg-panel px-3 py-2 text-sm font-semibold text-ink/68 ring-1 ring-line transition hover:text-doubao">
            <ArrowLeft className="size-4" />
            GEO
          </Link>
        </div>
      </header>

      <section className="mx-auto max-w-5xl px-4 py-6 sm:px-6">
        <GetNoteConsole />
      </section>
    </main>
  );
}
