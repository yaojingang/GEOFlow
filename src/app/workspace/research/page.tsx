import { Badge } from "@/components/Badge";
import { ResearchNotesClient } from "@/components/ResearchNotesClient";

export const dynamic = "force-dynamic";

export default function WorkspaceResearchPage() {
  return (
    <div className="p-4 sm:p-6">
      <Badge tone="doubao">Research</Badge>
      <h1 className="mt-5 text-4xl font-semibold">豆包研究中心</h1>
      <p className="mt-4 max-w-3xl text-ink/65 leading-7">
        把资料、豆包采样、报告和 Agent 对话里的稳定结论沉淀成公开研究节点。草稿留在工作台，发布后进入 `/doubao-research`。
      </p>
      <ResearchNotesClient />
    </div>
  );
}
