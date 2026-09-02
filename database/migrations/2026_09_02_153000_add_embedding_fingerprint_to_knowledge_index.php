<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->string('chunk_embedding_fingerprint', 64)->nullable();
            $table->unsignedInteger('chunk_embedding_dimensions')->nullable();
            $table->string('chunk_embedding_provider', 255)->nullable();
            $table->index('chunk_embedding_fingerprint', 'knowledge_bases_embedding_fingerprint_index');
        });

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->string('embedding_fingerprint', 64)->default('');
        });

        Schema::table('knowledge_chunk_sync_rows', function (Blueprint $table): void {
            $table->string('embedding_fingerprint', 64)->default('');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chunk_sync_rows', function (Blueprint $table): void {
            $table->dropColumn('embedding_fingerprint');
        });

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->dropColumn('embedding_fingerprint');
        });

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropIndex('knowledge_bases_embedding_fingerprint_index');
            $table->dropColumn([
                'chunk_embedding_fingerprint',
                'chunk_embedding_dimensions',
                'chunk_embedding_provider',
            ]);
        });
    }
};
