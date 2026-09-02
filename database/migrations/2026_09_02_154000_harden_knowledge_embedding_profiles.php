<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->unsignedSmallInteger('chunk_sync_embedding_profile_version')->nullable();
            $table->unsignedBigInteger('chunk_sync_embedding_model_id')->nullable();
            $table->string('chunk_sync_embedding_config_revision', 64)->nullable();
            $table->unsignedBigInteger('chunk_embedding_model_id')->nullable();
            $table->unsignedSmallInteger('chunk_embedding_profile_version')->nullable();
            $table->string('chunk_embedding_profile_digest', 64)->nullable();
            $table->index(
                ['chunk_embedding_profile_version', 'chunk_embedding_profile_digest'],
                'knowledge_bases_embedding_profile_index',
            );
        });

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('embedding_profile_version')->nullable();
            $table->string('embedding_profile_digest', 64)->nullable();
            $table->string('embedding_config_revision', 64)->nullable();
            $table->index(
                ['knowledge_base_id', 'generation_key', 'embedding_profile_version', 'embedding_profile_digest'],
                'knowledge_chunks_serving_profile_index',
            );
        });

        Schema::table('knowledge_chunk_sync_rows', function (Blueprint $table): void {
            $table->unsignedSmallInteger('embedding_profile_version')->nullable();
            $table->string('embedding_profile_digest', 64)->nullable();
            $table->string('embedding_config_revision', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chunk_sync_rows', function (Blueprint $table): void {
            $table->dropColumn([
                'embedding_profile_version',
                'embedding_profile_digest',
                'embedding_config_revision',
            ]);
        });

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->dropIndex('knowledge_chunks_serving_profile_index');
            $table->dropColumn([
                'embedding_profile_version',
                'embedding_profile_digest',
                'embedding_config_revision',
            ]);
        });

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropIndex('knowledge_bases_embedding_profile_index');
            $table->dropColumn([
                'chunk_sync_embedding_profile_version',
                'chunk_sync_embedding_model_id',
                'chunk_sync_embedding_config_revision',
                'chunk_embedding_model_id',
                'chunk_embedding_profile_version',
                'chunk_embedding_profile_digest',
            ]);
        });
    }
};
