import Link from "next/link";
import { notFound } from "next/navigation";
import { ArrowLeft, FileText, Link2, Network } from "lucide-react";
import { Badge } from "@/components/Badge";
import { getResearchNoteBySlug } from "@/lib/research-service";

type ResearchNotePageProps = {
  params: Promise<{ slug: string }>;
};

export const dynamic = "force-dynamic";

export default async function ResearchNotePage({ params }: ResearchNotePageProps) {
  const { slug } = await params;
  const result = await getResearchNoteBySlug(slug);

  if (!result) {
    notFound();
  }

  const { note, relatedSources, renderedBody } = result;
  const relatedNotes = [...note.outgoingLinks, ...note.incomingLinks];

  return (
    <main className="min-h-screen bg-paper text-ink">
      <header className="border-b border-line bg-white">
        <div className="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-5 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
          <Link href="/doubao-research" className="inline-flex items-center gap-2 text-sm font-semibold text-doubao">
            <ArrowLeft className="size-4" />
            豆包研究中心
          </Link>
          <Link href="/workspace/research" className="rounded-md bg-panel px-3 py-2 text-sm text-ink/65 ring-1 ring-line transition hover:text-doubao">
            内部编辑台
          </Link>
        </div>
      </header>

      <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:px-8">
        <article className="min-w-0 rounded-lg bg-white p-5 shadow-panel ring-1 ring-line sm:p-7">
          <div className="flex flex-wrap gap-2">
            <Badge tone="doubao">{note.type}</Badge>
            {note.tags.slice(0, 5).map((tag) => (
              <Badge key={tag}>{tag}</Badge>
            ))}
          </div>
          <h1 className="mt-5 text-4xl font-semibold leading-tight text-balance">{note.title}</h1>
          <p className="mt-4 max-w-3xl text-base leading-7 text-ink/62">{note.excerpt}</p>
          <div className="research-prose mt-8" dangerouslySetInnerHTML={{ __html: renderedBody }} />
        </article>

        <aside className="grid content-start gap-4">
          <article className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
            <div className="flex items-center gap-2">
              <Network className="size-4 text-doubao" />
              <h2 className="font-semibold">反向链接</h2>
            </div>
            <div className="mt-4 grid gap-2">
              {relatedNotes.length > 0 ? (
                relatedNotes.map((link) => (
                  <Link key={link.id} href={`/doubao-research/${link.note.slug}`} className="rounded-md bg-panel p-3 text-sm ring-1 ring-line transition hover:bg-doubao/10 hover:text-doubao">
                    <p className="font-semibold">{link.note.title}</p>
                    <p className="mt-1 text-xs text-ink/45">{link.label} · {link.strength}</p>
                  </Link>
                ))
              ) : (
                <p className="rounded-md bg-panel p-3 text-sm text-ink/52 ring-1 ring-line">还没有关联节点。</p>
              )}
            </div>
          </article>

          <article className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
            <div className="flex items-center gap-2">
              <FileText className="size-4 text-doubao" />
              <h2 className="font-semibold">相关证据</h2>
            </div>
            <div className="mt-4 grid gap-2">
              {relatedSources.sourceAssets.slice(0, 4).map((source) => (
                <div key={source.id} className="rounded-md bg-panel p-3 text-xs ring-1 ring-line">
                  <p className="line-clamp-1 font-semibold">{source.title}</p>
                  <p className="mt-1 line-clamp-2 leading-5 text-ink/48">{source.summary ?? source.status}</p>
                </div>
              ))}
              {relatedSources.reports.slice(0, 3).map((report) => (
                <Link key={report.id} href={`/reports/${report.publicSlug}`} className="rounded-md bg-panel p-3 text-xs ring-1 ring-line transition hover:text-doubao">
                  <p className="line-clamp-1 font-semibold">{report.title}</p>
                  <p className="mt-1 text-ink/45">公开报告</p>
                </Link>
              ))}
            </div>
          </article>

          <article className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
            <div className="flex items-center gap-2">
              <Link2 className="size-4 text-doubao" />
              <h2 className="font-semibold">引用链</h2>
            </div>
            <div className="mt-4 grid gap-2">
              {note.outgoingLinks.length > 0 ? (
                note.outgoingLinks.map((link) => (
                  <div key={link.id} className="rounded-md bg-panel p-3 text-xs ring-1 ring-line">
                    <p className="font-semibold">{note.title}</p>
                    <p className="mt-1 text-doubao">→ {link.note.title}</p>
                    <p className="mt-1 text-ink/42">{link.label}</p>
                  </div>
                ))
              ) : (
                <p className="rounded-md bg-panel p-3 text-sm text-ink/52 ring-1 ring-line">这个节点还没有向外引用。</p>
              )}
            </div>
          </article>
        </aside>
      </div>
    </main>
  );
}
