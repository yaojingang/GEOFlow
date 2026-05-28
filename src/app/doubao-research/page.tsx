import Link from "next/link";
import { ArrowRight, Bot, FileText, Network, Search, Tags } from "lucide-react";
import { Badge } from "@/components/Badge";
import { getResearchIndex } from "@/lib/research-service";

export const dynamic = "force-dynamic";

function dateLabel(value?: string | null) {
  if (!value) return "未发布";
  return value.slice(0, 10);
}

export default async function DoubaoResearchPage() {
  const index = await getResearchIndex();
  const featured = index.notes.find((note) => note.slug === "doubao-api-vs-web-search-rank-gap-report") ?? index.notes[0];
  const noteTypes = Array.from(new Set(index.notes.map((note) => note.type)));

  return (
    <main className="min-h-screen bg-paper text-ink">
      <header className="border-b border-line bg-white">
        <div className="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
          <Link href="/" className="flex items-center gap-3">
            <div className="flex size-9 items-center justify-center rounded-md bg-doubao/10 text-xs font-bold text-doubao shadow-soft">geo</div>
            <div>
              <p className="text-sm font-semibold">豆包研究中心</p>
              <p className="text-xs text-ink/45">Doubao Research Center</p>
            </div>
          </Link>
          <nav className="flex flex-wrap gap-2 text-sm">
            <Link href="/workspace/research" className="rounded-md bg-panel px-3 py-2 text-ink/65 ring-1 ring-line transition hover:text-doubao">
              内部编辑台
            </Link>
            <Link href="/workspace" className="rounded-md bg-doubao px-3 py-2 font-semibold text-paper shadow-doubao transition hover:bg-ink">
              返回工作台
            </Link>
          </nav>
        </div>
      </header>

      <section className="answer-grid border-b border-line">
        <div className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[260px_1fr_300px] lg:px-8">
          <aside className="grid content-start gap-4">
            <div>
              <Badge tone="doubao">独立子站 · Obsidian knowledge base</Badge>
              <h1 className="mt-5 text-4xl font-semibold leading-tight text-balance">豆包研究中心</h1>
              <p className="mt-4 text-sm leading-6 text-ink/62">这是 GEOFlow 的独立研究子页面。公开层像 Obsidian 一样展示研究节点、资料、证据、反向链接和知识图谱；内核仍来自你和 Agent 的研究对话。</p>
            </div>
            <div className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
              <div className="flex items-center gap-2">
                <Search className="size-4 text-doubao" />
                <h2 className="font-semibold">知识库导航</h2>
              </div>
              <div className="mt-3 grid gap-2">
                {noteTypes.map((type) => (
                  <a key={type} href={`#type-${type}`} className="rounded-md bg-panel px-3 py-2 text-sm text-ink/65 ring-1 ring-line transition hover:bg-doubao/10 hover:text-doubao">
                    {type}
                  </a>
                ))}
              </div>
            </div>
          </aside>

          <section className="min-w-0">
            {featured ? (
              <Link href={`/doubao-research/${featured.slug}`} className="block rounded-lg bg-white p-6 shadow-panel ring-1 ring-line transition hover:-translate-y-1 hover:ring-doubao/35">
                <div className="flex flex-wrap gap-2">
                  <Badge tone="doubao">研究报告</Badge>
                  <Badge>{featured.type}</Badge>
                </div>
                <h2 className="mt-5 text-3xl font-semibold text-balance">{featured.title}</h2>
                <p className="mt-4 max-w-3xl text-base leading-7 text-ink/64">{featured.excerpt}</p>
                <div className="mt-5 flex flex-wrap gap-2">
                  {featured.tags.map((tag) => (
                    <span key={tag} className="rounded-md bg-panel px-2.5 py-1 text-xs text-ink/55 ring-1 ring-line">
                      #{tag}
                    </span>
                  ))}
                </div>
                <div className="mt-6 inline-flex items-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao">
                  打开报告节点 <ArrowRight className="size-4" />
                </div>
              </Link>
            ) : null}

            <div className="mt-5 grid gap-4">
              {noteTypes.map((type) => (
                <section key={type} id={`type-${type}`} className="grid gap-3">
                  <h2 className="text-xl font-semibold">{type}</h2>
                  {index.notes
                    .filter((note) => note.type === type)
                    .map((note) => (
                      <Link key={note.id} href={`/doubao-research/${note.slug}`} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line transition hover:-translate-y-0.5 hover:ring-doubao/35">
                        <div className="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                          <div>
                            <h3 className="text-xl font-semibold">{note.title}</h3>
                            <p className="mt-2 line-clamp-2 text-sm leading-6 text-ink/60">{note.excerpt}</p>
                          </div>
                          <span className="shrink-0 text-xs text-ink/42">{dateLabel(note.publishedAt)}</span>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-2">
                          {note.tags.slice(0, 5).map((tag) => (
                            <span key={tag} className="rounded-md bg-panel px-2 py-1 text-xs text-ink/52 ring-1 ring-line">
                              #{tag}
                            </span>
                          ))}
                          <span className="inline-flex items-center gap-1 text-xs font-semibold text-doubao">
                            阅读节点 <ArrowRight className="size-3" />
                          </span>
                        </div>
                      </Link>
                    ))}
                </section>
              ))}
            </div>
          </section>

          <aside className="grid content-start gap-4">
            <article className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
              <div className="flex items-center gap-2">
                <Network className="size-4 text-doubao" />
                <h2 className="font-semibold">轻量图谱</h2>
              </div>
              <div className="mt-4 grid gap-2">
                {index.links.slice(0, 8).map((link) => (
                  <div key={link.id} className="rounded-md bg-panel p-3 text-xs ring-1 ring-line">
                    <p className="font-semibold text-ink/75">{link.from.title}</p>
                    <p className="mt-1 text-doubao">→ {link.to.title}</p>
                    <p className="mt-1 text-ink/42">{link.label} · {link.strength}</p>
                  </div>
                ))}
              </div>
            </article>

            <article className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
              <div className="flex items-center gap-2">
                <Bot className="size-4 text-doubao" />
                <h2 className="font-semibold">最新豆包样本</h2>
              </div>
              <div className="mt-4 grid gap-2">
                {index.answerSamples.slice(0, 4).map((sample) => (
                  <div key={sample.id} className="rounded-md bg-panel p-3 text-xs ring-1 ring-line">
                    <p className="line-clamp-2 font-semibold leading-5">{sample.question}</p>
                    <p className="mt-2 text-ink/45">{sample.brandMentioned ? "已提及品牌" : "未提及品牌"}</p>
                  </div>
                ))}
              </div>
            </article>

            <article className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
              <div className="flex items-center gap-2">
                <FileText className="size-4 text-doubao" />
                <h2 className="font-semibold">证据来源</h2>
              </div>
              <div className="mt-4 grid gap-2">
                {index.sourceAssets.slice(0, 4).map((source) => (
                  <div key={source.id} className="rounded-md bg-panel p-3 text-xs ring-1 ring-line">
                    <p className="line-clamp-1 font-semibold">{source.title}</p>
                    <p className="mt-1 line-clamp-2 leading-5 text-ink/48">{source.summary ?? source.status}</p>
                  </div>
                ))}
              </div>
            </article>

            <article className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line">
              <div className="flex items-center gap-2">
                <Tags className="size-4 text-doubao" />
                <h2 className="font-semibold">标签</h2>
              </div>
              <div className="mt-4 flex flex-wrap gap-2">
                {index.tags.map((tag) => (
                  <span key={tag} className="rounded-md bg-panel px-2 py-1 text-xs text-ink/55 ring-1 ring-line">
                    #{tag}
                  </span>
                ))}
              </div>
            </article>
          </aside>
        </div>
      </section>
    </main>
  );
}
