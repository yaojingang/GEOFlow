<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('article_ai_quality_rollouts')) {
            return;
        }

        Schema::create('article_ai_quality_rollouts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedTinyInteger('principle_percent')->default(0);
            $table->unsignedTinyInteger('execution_percent')->default(0);
            $table->unsignedTinyInteger('scoring_percent')->default(0);
            $table->unsignedTinyInteger('shadow_percent')->default(0);
            $table->boolean('sampled_auto_release_enabled')->default(true);
            $table->boolean('frozen')->default(false);
            $table->string('incident_code', 120)->nullable();
            $table->string('latest_evaluation_path', 1000)->nullable();
            $table->timestamp('latest_evaluation_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_ai_quality_rollouts');
    }
};
