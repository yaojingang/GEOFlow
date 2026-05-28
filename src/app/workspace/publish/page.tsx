import { Badge } from "@/components/Badge";
import { getWorkspaceState } from "@/lib/workspace-service";

export const dynamic = "force-dynamic";

function dateLabel(value: Date) {
  return value.toISOString().slice(0, 10);
}

export default async function PublishPage() {
  const state = await getWorkspaceState();
  const latestReport = state.reports.find((report) => report.publicSlug);
  const latestContent = state.contentAssets[0];
  const visibleSocials = state.socialAccounts.filter((item) => item.isVisible && (item.url || item.handle));
  const missingSocials = state.socialAccounts.filter((item) => !item.isVisible || (!item.url && !item.handle)).slice(0, 4);
  const publishItems = [
    {
      title: "客户展示端",
      status: "可用",
      body: "公开首页、工作台和客户报告入口已运行在 geo.youngtuo.win。",
      href: "https://geo.youngtuo.win",
      action: "打开首页",
    },
    {
      title: "最新客户报告",
      status: latestReport ? "可分享" : "待生成",
      body: latestReport ? `${latestReport.title} · ${dateLabel(latestReport.createdAt)}` : "先到报告中心生成一份 verified 报告。",
      href: latestReport?.publicSlug ? `/reports/${latestReport.publicSlug}` : "/workspace/reports",
      action: latestReport ? "打开报告" : "去生成报告",
    },
    {
      title: "内容草稿",
      status: latestContent ? "有草稿" : "待生成",
      body: latestContent ? `${latestContent.type} · ${latestContent.title}` : "先按缺口生成 FAQ、对比页或案例页草稿。",
      href: "/workspace/content",
      action: latestContent ? "查看内容" : "生成草稿",
    },
    {
      title: "豆包研究中心",
      status: "可发布",
      body: "公开研究馆展示整理后的研究节点、证据、反向链接和轻量图谱。",
      href: "/doubao-research",
      action: "打开研究中心",
    },
    {
      title: "社媒分发",
      status: visibleSocials.length > 0 ? `${visibleSocials.length} 个可见账号` : "待配置",
      body: visibleSocials.length > 0 ? visibleSocials.map((item) => item.platform).join(" / ") : "先到设置里填写账号名、主页链接并勾选展示给客户。",
      href: "/workspace/settings",
      action: "配置社媒",
    },
  ];

  return (
    <div className="p-4 sm:p-6">
      <Badge>Step 8</Badge>
      <h1 className="mt-5 text-4xl font-semibold">发布与分发</h1>
      <p className="mt-4 max-w-3xl text-ink/65 leading-7">发布到客户展示端、客户官网、WordPress、社媒草稿和报告链接。危险操作必须二次确认。</p>
      <section className="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        {publishItems.map((item) => (
          <article key={item.title} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
            <div className="flex items-center justify-between gap-3">
              <h2 className="font-semibold">{item.title}</h2>
              <Badge tone={item.status.includes("待") ? "dark" : "doubao"}>{item.status}</Badge>
            </div>
            <p className="mt-4 min-h-16 text-sm leading-6 text-ink/62">{item.body}</p>
            <a
              href={item.href}
              target={item.href.startsWith("http") ? "_blank" : undefined}
              rel={item.href.startsWith("http") ? "noreferrer" : undefined}
              className="mt-4 inline-flex w-full items-center justify-center rounded-md bg-doubao px-3 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
            >
              {item.action}
            </a>
          </article>
        ))}
      </section>

      <section className="mt-6 grid gap-4 lg:grid-cols-[1fr_360px]">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <h2 className="text-xl font-semibold">交付清单</h2>
          <div className="mt-4 grid gap-3">
            {[
              ["报告链接", latestReport ? "已准备" : "待生成", latestReport?.publicSlug ? `https://geo.youngtuo.win/reports/${latestReport.publicSlug}` : "报告中心生成后自动出现"],
              ["Markdown 导出", latestReport ? "已准备" : "待生成", latestReport?.publicSlug ? `/api/reports/${latestReport.publicSlug}/markdown` : "用于发给客户或归档到 Obsidian"],
              ["内容资产", latestContent ? "已准备" : "待生成", latestContent ? `${state.stats.contentCount} 篇草稿，可继续审核发布` : "内容生产页生成后进入清单"],
              ["社媒主页", visibleSocials.length > 0 ? "部分配置" : "待配置", visibleSocials.length > 0 ? visibleSocials.map((item) => item.platform).join("、") : "至少配置一个客户可见账号"],
            ].map(([name, status, detail]) => (
              <div key={name} className="grid gap-2 rounded-md bg-panel p-4 ring-1 ring-line md:grid-cols-[120px_100px_1fr]">
                <p className="font-medium">{name}</p>
                <p className="text-sm text-doubao">{status}</p>
                <p className="break-all text-sm leading-6 text-ink/58">{detail}</p>
              </div>
            ))}
          </div>
        </article>

        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <h2 className="text-xl font-semibold">还需补齐</h2>
          <div className="mt-4 grid gap-3">
            {missingSocials.length > 0 ? (
              missingSocials.map((item) => (
                <div key={item.platform} className="rounded-md bg-panel p-4 ring-1 ring-line">
                  <p className="font-medium">{item.platform}</p>
                  <p className="mt-2 text-sm leading-6 text-ink/58">填写账号名或主页链接，并选择是否展示给客户。</p>
                </div>
              ))
            ) : (
              <p className="rounded-md bg-panel p-4 text-sm text-ink/58 ring-1 ring-line">社媒账号已具备基础展示条件。</p>
            )}
          </div>
        </article>
      </section>
    </div>
  );
}
