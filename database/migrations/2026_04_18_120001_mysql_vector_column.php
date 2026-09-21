<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add the native MySQL/RDS VECTOR column without changing the immutable
 * PostgreSQL pgvector migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            || ! Schema::hasTable('knowledge_chunks')
            || Schema::hasColumn('knowledge_chunks', 'embedding_vector')) {
            return;
        }

        try {
            $probe = DB::selectOne(
                'SELECT VECTOR_DIM(VEC_FROMTEXT(?)) AS vector_dimensions',
                ['[0,0]'],
            );
            if ((int) ($probe->vector_dimensions ?? 0) !== 2) {
                return;
            }
        } catch (Throwable) {
            // Standard MySQL has no native VECTOR type. Keep the migration
            // install-safe there; the adapter will use the normal fallback.
            return;
        }

        DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding_vector VECTOR(3072) NULL');
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            || ! Schema::hasColumn('knowledge_chunks', 'embedding_vector')) {
            return;
        }

        DB::statement('ALTER TABLE knowledge_chunks DROP COLUMN embedding_vector');
    }
};
