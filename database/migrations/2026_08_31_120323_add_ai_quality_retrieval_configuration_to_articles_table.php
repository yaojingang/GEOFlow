<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->string('ai_quality_retrieval_mode_override', 32)->nullable()->after('ai_quality_required_at_creation');
            $table->unsignedBigInteger('ai_quality_policy_version')->default(1)->after('ai_quality_retrieval_mode_override');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn(['ai_quality_retrieval_mode_override', 'ai_quality_policy_version']);
        });
    }
};
