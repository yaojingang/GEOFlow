import { Badge } from "@/components/Badge";
import { ReportsClient } from "@/components/ReportsClient";

export default function ReportsPage() {
  return (
    <div className="p-4 sm:p-6">
      <Badge tone="doubao">Reports</Badge>
      <h1 className="mt-5 text-4xl font-semibold">报告中心</h1>
      <p className="mt-4 max-w-3xl text-ink/65 leading-7">输出客户可读的 HTML / PDF / Markdown 报告，包含来源台账、采样声明、差距分析和下一步行动。</p>
      <ReportsClient />
    </div>
  );
}
