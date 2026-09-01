<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SYSTEM_KEY = 'article_quality.cn_ads_knowledge.v1';

    public function up(): void
    {
        $this->sync('2.1.0', $this->promptV21());
    }

    public function down(): void
    {
        $content = str_replace(
            [
                "- reviewed_claim_hashes：本分段已经逐项核查的高物质性事实 claim_hash 数组；即使未发现问题也必须列出。\n",
                '4. 当前分段中的每个高物质性数据主张均已检查，并完整写入 reviewed_claim_hashes。',
            ],
            [
                '',
                '4. 当前分段中的每个物质性数据主张均已检查。',
            ],
            $this->promptV21(),
        );
        $this->sync('2.0.0', $content);
    }

    private function promptV21(): string
    {
        $content = file_get_contents(resource_path('prompts/versions/article-quality-cn-v2.2.0.txt'));
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Versioned AI quality prompt resource is unavailable.');
        }

        return str_replace(
            [
                '中国大陆广告合规风险、广告可识别性风险和内容完整性',
                '## C. 广告与可识别性风险',
                "7. 发布语境已确认需要广告可识别性或广告标识，但当前配置未满足时记录 ad_identifiability。\n8. 发布语境信息不足时写入 uncertainties，不能自行假设渠道已经提供或缺少广告标识。\n9. legal_refs 只能使用规则数据块出现的规则名称或条款。",
                'ad_industry_specific、ad_identifiability、content_integrity。',
            ],
            [
                '中国大陆广告合规风险、发布标识风险和内容完整性',
                '## C. 广告与发布标识风险',
                "7. 发布语境已确认需要广告可识别性或广告标识，但当前配置未满足时记录 ad_identifiability。\n8. 发布语境已确认适用 AI 生成合成内容标识规则，但当前配置未满足时记录 ai_generated_disclosure。\n9. 发布语境信息不足时写入 uncertainties，不能自行假设渠道已经提供或缺少标识。\n10. legal_refs 只能使用规则数据块出现的规则名称或条款。",
                'ad_industry_specific、ad_identifiability、ai_generated_disclosure、content_integrity。',
            ],
            trim($content),
        );
    }

    private function sync(string $version, string $content): void
    {
        if (! Schema::hasTable('prompts') || ! Schema::hasColumn('prompts', 'system_key')) {
            return;
        }

        DB::table('prompts')
            ->where('system_key', self::SYSTEM_KEY)
            ->update([
                'content' => trim($content),
                'system_version' => $version,
                'updated_at' => now(),
            ]);
    }
};
