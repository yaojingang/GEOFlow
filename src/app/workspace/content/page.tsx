import { Badge } from "@/components/Badge";
import { ContentClient } from "@/components/ContentClient";

export default function ContentPage() {
  return (
    <div className="p-4 sm:p-6">
      <Badge>Step 7</Badge>
      <h1 className="mt-5 text-4xl font-semibold">内容生产</h1>
      <p className="mt-4 max-w-3xl text-ink/65 leading-7">根据 P0/P1 缺口生成 FAQ、解释页、对比页、榜单页、品牌事实页和社媒内容包。</p>
      <ContentClient />
    </div>
  );
}
