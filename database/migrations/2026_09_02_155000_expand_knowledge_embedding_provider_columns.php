<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->string('chunk_embedding_provider', 500)->nullable()->change();
        });

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->string('embedding_provider', 500)->default('')->change();
        });

        Schema::table('knowledge_chunk_sync_rows', function (Blueprint $table): void {
            $table->string('embedding_provider', 500)->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chunk_sync_rows', function (Blueprint $table): void {
            $table->string('embedding_provider', 255)->default('')->change();
        });

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->string('embedding_provider', 255)->default('')->change();
        });

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->string('chunk_embedding_provider', 255)->nullable()->change();
        });
    }
};
