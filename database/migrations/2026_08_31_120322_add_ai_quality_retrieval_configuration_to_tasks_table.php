<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('ai_quality_retrieval_mode', 32)->nullable()->after('ai_quality_enabled');
            $table->unsignedBigInteger('ai_quality_policy_version')->default(1)->after('ai_quality_retrieval_mode');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['ai_quality_retrieval_mode', 'ai_quality_policy_version']);
        });
    }
};
