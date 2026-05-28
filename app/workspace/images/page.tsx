import { Badge } from "@/components/Badge";
import { ImageLibraryClient } from "@/components/ImageLibraryClient";
import { getWorkspaceState } from "@/lib/workspace-service";

export const dynamic = "force-dynamic";

function isImageSource(item: Awaited<ReturnType<typeof getWorkspaceState>>["sourceAssets"][number]) {
  return item.kind === "image" || Boolean(item.mimeType?.startsWith("image/"));
}

export default async function ImagesPage() {
  const state = await getWorkspaceState();
  const imageCount = state.sourceAssets.filter(isImageSource).length;

  return (
    <div className="p-4 sm:p-6">
      <section className="rounded-lg bg-white p-6 shadow-panel ring-1 ring-line">
        <Badge tone="doubao">图片库</Badge>
        <h1 className="mt-5 text-4xl font-semibold">图片库</h1>
        <p className="mt-4 max-w-3xl text-ink/65 leading-7">
          管理官网封面、报告配图、案例截图和社媒封面。图片素材会进入资料体系，后续用于内容生产和发布分发。
        </p>
        <div className="mt-5 grid gap-3 sm:grid-cols-3">
          {[
            ["图片素材", `${imageCount}`, "可用于内容和社媒"],
            ["资料总量", `${state.stats.sourceCount}`, "包含图片与文本资料"],
            ["内容资产", `${state.stats.contentCount}`, "可搭配图片发布"],
          ].map(([label, value, note]) => (
            <article key={label} className="rounded-md bg-panel p-4 ring-1 ring-line">
              <p className="text-sm text-ink/50">{label}</p>
              <p className="mt-2 text-2xl font-semibold text-doubao">{value}</p>
              <p className="mt-1 text-xs text-ink/45">{note}</p>
            </article>
          ))}
        </div>
      </section>

      <ImageLibraryClient />
    </div>
  );
}
