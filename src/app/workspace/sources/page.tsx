import { Badge } from "@/components/Badge";
import { SourcesClient } from "@/components/SourcesClient";

export default function SourcesPage() {
  return (
    <div className="p-4 sm:p-6">
      <Badge tone="doubao">Step 2</Badge>
      <h1 className="mt-5 text-4xl font-semibold">上传资料</h1>
      <p className="mt-4 max-w-3xl text-ink/65 leading-7">上传官网链接、PDF、产品手册、案例、FAQ、社媒链接和过往文章。系统会得到资料库和可追溯证据台账。</p>
      <SourcesClient />
    </div>
  );
}
