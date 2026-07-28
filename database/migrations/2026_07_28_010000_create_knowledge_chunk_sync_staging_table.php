<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->string('chunk_sync_status', 20)->default('idle');
            $table->string('chunk_sync_token', 64)->nullable();
            $table->string('chunk_source_hash', 64)->default('');
            $table->text('chunk_sync_error')->nullable();
            $table->boolean('chunk_sync_require_real_embedding')->default(false);
            $table->timestamp('chunk_synced_at')->nullable();
            $table->index(
                ['chunk_sync_status', 'updated_at', 'id'],
                'knowledge_bases_chunk_sync_recovery_index'
            );
        });

        Schema::create('knowledge_chunk_sync_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->string('sync_token', 64);
            $table->integer('chunk_index');
            $table->text('content');
            $table->string('content_hash', 64)->default('');
            $table->string('chunk_title', 255)->default('');
            $table->string('section_path', 500)->default('');
            $table->string('chunk_strategy', 50)->default('structured_rule');
            $table->text('metadata_json')->nullable();
            $table->string('source_hash', 64)->default('');
            $table->integer('token_count')->default(0);
            $table->text('embedding_json')->nullable();
            $table->integer('embedding_model_id')->nullable();
            $table->integer('embedding_dimensions')->default(0);
            $table->string('embedding_provider', 255)->default('');
            $table->text('embedding_vector')->nullable();
            $table->timestamps();

            $table->unique(
                ['knowledge_base_id', 'sync_token', 'chunk_index'],
                'knowledge_chunk_sync_rows_version_index_unique'
            );
            $table->index(
                ['knowledge_base_id', 'sync_token', 'id'],
                'knowledge_chunk_sync_rows_version_id_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunk_sync_rows');

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropIndex('knowledge_bases_chunk_sync_recovery_index');
            $table->dropColumn([
                'chunk_sync_status',
                'chunk_sync_token',
                'chunk_source_hash',
                'chunk_sync_error',
                'chunk_sync_require_real_embedding',
                'chunk_synced_at',
            ]);
        });
    }
};
