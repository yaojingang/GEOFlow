import { Badge } from "@/components/Badge";
import { MonitorClient } from "@/components/MonitorClient";

export default function MonitorPage() {
  return (
    <div className="p-4 sm:p-6">
      <Badge tone="doubao">Step 5 + 9</Badge>
      <h1 className="mt-5 text-4xl font-semibold">豆包监测</h1>
      <p className="mt-4 max-w-3xl text-ink/65 leading-7">记录豆包答案、品牌提及率、推荐排名、竞品命中和错误事实，形成 Day 7/14/30 趋势。</p>
      <MonitorClient />
    </div>
  );
}
