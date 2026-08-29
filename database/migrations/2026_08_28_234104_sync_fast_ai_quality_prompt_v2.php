<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SYSTEM_KEY = 'article_quality.cn_ads_knowledge.v1';

    public function up(): void
    {
        $this->syncFromResource('article-quality-cn-v1.txt', '2.0.0');
    }

    public function down(): void
    {
        $this->syncFromResource('article-quality-cn-v1-legacy.txt', '1.0.0');
    }

    private function syncFromResource(string $filename, string $version): void
    {
        if (! Schema::hasTable('prompts') || ! Schema::hasColumn('prompts', 'system_key')) {
            return;
        }

        $content = file_get_contents(resource_path('prompts/'.$filename));
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Versioned AI quality prompt resource is unavailable.');
        }

        $query = DB::table('prompts')->where('system_key', self::SYSTEM_KEY);
        $values = [
            'name' => '知识库与广告法综合质检（默认）',
            'type' => 'quality_check',
            'content' => trim($content),
            'variables' => json_encode([
                'article_title',
                'article_excerpt',
                'article_outline',
                'article_content',
                'keywords',
                'meta_description',
                'fact_candidates',
                'knowledge',
                'advertising_rules',
                'inspection_date',
                'publication_context',
                'segment_index',
                'segment_count',
                'segment_start_offset',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'system_version' => $version,
            'updated_at' => now(),
        ];
        if (! $query->exists()) {
            $values['created_at'] = now();
        }

        DB::table('prompts')->updateOrInsert(['system_key' => self::SYSTEM_KEY], $values);
    }
};
