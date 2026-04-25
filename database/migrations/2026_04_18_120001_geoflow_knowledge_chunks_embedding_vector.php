<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 为 `knowledge_chunks` 添加 `embedding_vector` 列（维度 3072），对齐 {@see bak/includes/database_admin.php} ensurePgvectorSchema。
 *
 * 高维向量在常见 pgvector 版本下可能无法建 HNSW/IVFFlat，故仅加列；注释见 down/up 内说明。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasVector = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM pg_extension WHERE extname = 'vector'
            ) as ok
        ");
        if (! $hasVector || ! $hasVector->ok) {
            return;
        }

        $hasColumn = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name = 'knowledge_chunks' AND column_name = 'embedding_vector'
            ) as ok
        ");
        if (! $hasColumn || ! $hasColumn->ok) {
            DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding_vector vector(3072)');
        }

        // pgvector 0.8.x 对 HNSW/IVFFlat 索引维度常见上限为 2000；3072 维仅存列不建 ANN 索引。
        // 若需近似检索索引：升级 pgvector、或改用 ≤2000 维模型后再建 HNSW/IVFFlat。
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasColumn = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name = 'knowledge_chunks' AND column_name = 'embedding_vector'
            ) as ok
        ");
        if ($hasColumn && $hasColumn->ok) {
            DB::statement('ALTER TABLE knowledge_chunks DROP COLUMN embedding_vector');
        }
    }
};
