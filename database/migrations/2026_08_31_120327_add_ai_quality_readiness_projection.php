<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->char('ai_quality_content_hash', 64)->default('')->after('content');
            $table->unsignedBigInteger('ai_quality_content_length')->default(0)->after('ai_quality_content_hash');
        });
        Schema::table('knowledge_fact_libraries', function (Blueprint $table): void {
            $table->unsignedInteger('active_fact_count')->default(0)->after('active_hash');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_fact_libraries', function (Blueprint $table): void {
            $table->dropColumn('active_fact_count');
        });
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropColumn(['ai_quality_content_hash', 'ai_quality_content_length']);
        });
    }
};
