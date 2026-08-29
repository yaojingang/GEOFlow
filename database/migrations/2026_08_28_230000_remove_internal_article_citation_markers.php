<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHINESE_PROMPT_RULE = '【正文引用标注约束】最终文章中不得出现任何内部证据编号、引用占位符或编号引用标记，包括 [K1]、[K2][K3]、【K1】、（K1）及同类形式。需要说明依据时使用自然语言，不添加 K 编号。';

    private const ENGLISH_PROMPT_RULE = '[Citation Marker Constraint] The final article must not contain internal evidence IDs, citation placeholders, or numbered citation markers, including [K1], [K2][K3], 【K1】, （K1）, or equivalent forms. Express attribution in natural language without K-number labels.';

    /** @var array<string, string> */
    private const DEFAULT_PROMPT_RULES = [
        'GEO营销学·信任型正文生成' => self::CHINESE_PROMPT_RULE,
        'GEO榜单型正文生成' => self::CHINESE_PROMPT_RULE,
        'GEO Marketing · Trust-Based Article Generation (English)' => self::ENGLISH_PROMPT_RULE,
        'GEO Ranking-Style Article Generation (English)' => self::ENGLISH_PROMPT_RULE,
    ];

    public function up(): void
    {
        $this->appendDefaultPromptRules();
    }

    public function down(): void
    {
        // 安全约束可能在迁移前已经存在，回滚不删除用户已有的同义规则。
    }

    private function appendDefaultPromptRules(): void
    {
        if (! Schema::hasTable('prompts')) {
            return;
        }

        foreach (self::DEFAULT_PROMPT_RULES as $name => $rule) {
            DB::table('prompts')
                ->where('type', 'content')
                ->where('name', $name)
                ->orderBy('id')
                ->eachById(function (object $prompt) use ($rule): void {
                    $this->appendRule((int) $prompt->id, $rule);
                }, 100, 'id', 'id');
        }
    }

    private function appendRule(int $promptId, string $rule): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $original = (string) DB::table('prompts')->where('id', $promptId)->value('content');
            $content = rtrim($original);
            if (str_contains($content, $rule)) {
                return;
            }
            $next = $content === '' ? $rule : $content."\n\n".$rule;
            $updated = DB::table('prompts')
                ->where('id', $promptId)
                ->where('content', $original)
                ->update(['content' => $next, 'updated_at' => now()]);
            if ($updated === 1) {
                return;
            }
        }

        throw new RuntimeException("提示词 {$promptId} 在迁移期间持续发生并发修改");
    }
};
