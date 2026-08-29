<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SYSTEM_KEY = 'article_quality.cn_ads_knowledge.v1';

    public function up(): void
    {
        $this->sync('2.1.0', $this->currentPrompt());
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
            $this->currentPrompt(),
        );
        $this->sync('2.0.0', $content);
    }

    private function currentPrompt(): string
    {
        $content = file_get_contents(resource_path('prompts/article-quality-cn-v1.txt'));
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Versioned AI quality prompt resource is unavailable.');
        }

        return trim($content);
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
