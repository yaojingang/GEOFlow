<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->string('chunk_serving_generation', 64)->nullable()->after('chunk_source_hash');
            $table->char('chunk_serving_source_hash', 64)->nullable()->after('chunk_serving_generation');
            $table->char('chunk_manifest_hash', 64)->nullable()->after('chunk_serving_source_hash');
        });

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->string('generation_key', 64)->nullable()->after('knowledge_base_id');
        });
        $this->dropLegacyChunkIndexUnique();
        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->unique(
                ['knowledge_base_id', 'generation_key', 'chunk_index'],
                'knowledge_chunks_generation_index_unique'
            );
            $table->index(
                ['knowledge_base_id', 'generation_key', 'chunk_index'],
                'knowledge_chunks_serving_generation_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->dropIndex('knowledge_chunks_serving_generation_idx');
            $table->dropUnique('knowledge_chunks_generation_index_unique');
            $table->dropColumn('generation_key');
            $table->unique(['knowledge_base_id', 'chunk_index']);
        });

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropColumn([
                'chunk_serving_generation',
                'chunk_serving_source_hash',
                'chunk_manifest_hash',
            ]);
        });
    }

    private function dropLegacyChunkIndexUnique(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE knowledge_chunks DROP CONSTRAINT IF EXISTS knowledge_chunks_knowledge_base_id_chunk_index_key');
            DB::statement('ALTER TABLE knowledge_chunks DROP CONSTRAINT IF EXISTS knowledge_chunks_knowledge_base_id_chunk_index_unique');

            return;
        }

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->dropUnique(['knowledge_base_id', 'chunk_index']);
        });
    }
};
