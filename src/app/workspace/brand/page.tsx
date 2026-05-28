import { Badge } from "@/components/Badge";
import { BrandFactsClient } from "@/components/BrandFactsClient";

export default function BrandPage() {
  return (
    <div className="p-4 sm:p-6">
      <Badge>Step 3</Badge>
      <h1 className="mt-5 text-4xl font-semibold">品牌事实库</h1>
      <p className="mt-4 max-w-3xl text-ink/65 leading-7">把资料提炼成品牌事实、产品优势、可信来源、禁用说法和纠错任务，让豆包可以稳定采纳。</p>
      <BrandFactsClient />
    </div>
  );
}
