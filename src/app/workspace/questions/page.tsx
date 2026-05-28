import { Badge } from "@/components/Badge";
import { QuestionsClient } from "@/components/QuestionsClient";

export default function QuestionsPage() {
  return (
    <div className="p-4 sm:p-6">
      <Badge tone="doubao">Step 4</Badge>
      <h1 className="mt-5 text-4xl font-semibold">豆包问题集</h1>
      <p className="mt-4 max-w-3xl text-ink/65 leading-7">生成购买意图、对比意图、品牌意图和行业意图问题，用来采样豆包真实答案。</p>
      <QuestionsClient />
    </div>
  );
}
