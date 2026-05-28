import { Badge } from "@/components/Badge";
import { LessonsClient } from "@/components/LessonsClient";

export default function LessonsPage() {
  return (
    <div className="p-4 sm:p-6">
      <Badge tone="doubao">Agent memory</Badge>
      <h1 className="mt-5 text-4xl font-semibold">GEO 经验库</h1>
      <p className="mt-4 max-w-3xl text-ink/65 leading-7">
        只沉淀被明确反馈过的优化动作：有效、部分有效或无效。Agent 会优先读取这些经验，再决定下一步该跑采样、生成内容还是提醒补证据。
      </p>
      <LessonsClient />
    </div>
  );
}
