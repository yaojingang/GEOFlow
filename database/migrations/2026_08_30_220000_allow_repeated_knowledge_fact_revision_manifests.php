<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_fact_library_revisions', function (Blueprint $table): void {
            $table->dropUnique(['library_id', 'library_hash']);
            $table->index(['library_id', 'library_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_fact_library_revisions', function (Blueprint $table): void {
            $table->dropIndex(['library_id', 'library_hash']);
            $table->unique(['library_id', 'library_hash']);
        });
    }
};
