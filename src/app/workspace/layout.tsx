import Link from "next/link";
import { dashboardNav } from "@/data/workspace";

export default function WorkspaceLayout({ children }: { children: React.ReactNode }) {
  return (
    <main className="min-h-screen bg-paper text-ink">
      <div className="grid min-h-screen lg:grid-cols-[260px_1fr]">
        <aside className="border-b border-line bg-white px-4 py-4 shadow-soft lg:border-b-0 lg:py-5">
          <Link href="/" className="flex items-center gap-3 border-b border-line pb-5">
            <div className="flex size-9 items-center justify-center rounded-md bg-doubao/10 text-xs font-bold text-doubao shadow-soft">geo</div>
            <div>
              <p className="font-semibold">geo.youngtuo.win</p>
              <p className="text-xs text-ink/45">客户项目工作台</p>
            </div>
          </Link>
          <nav className="mt-4 flex gap-1 overflow-x-auto pb-1 lg:grid lg:overflow-visible lg:pb-0">
            {dashboardNav.map((item) => (
              <Link key={item.href} href={item.href} className="flex shrink-0 items-center gap-3 rounded-md px-3 py-2.5 text-sm text-ink/66 transition hover:bg-panel hover:text-ink">
                <item.icon className="size-4 text-doubao" />
                {item.label}
              </Link>
            ))}
          </nav>
        </aside>
        {children}
      </div>
    </main>
  );
}
