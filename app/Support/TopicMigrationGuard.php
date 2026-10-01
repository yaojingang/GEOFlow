<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class TopicMigrationGuard
{
    public static function assertEmpty(): void
    {
        foreach ([
            'topics', 'topic_revisions', 'topic_revision_articles', 'topic_source_invalidations',
            'topic_freshness_changes', 'topic_paths', 'topic_build_runs', 'topic_import_batches',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('专题数据已存在，请先停止任务并保留兼容迁移，避免删除已保存内容。');
            }
        }
        foreach (['tasks', 'task_runs'] as $table) {
            if (Schema::hasColumn($table, 'content_type') && DB::table($table)->where('content_type', 'topic')->exists()) {
                throw new RuntimeException('专题数据已存在，请先停止任务并保留兼容迁移，避免删除已保存内容。');
            }
        }
    }
}
