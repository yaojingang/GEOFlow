<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('article_ai_quality_rollout_events')) {
            return;
        }

        Schema::create('article_ai_quality_rollout_events', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 40);
            $table->string('track', 40)->nullable();
            $table->unsignedTinyInteger('from_percent')->nullable();
            $table->unsignedTinyInteger('to_percent')->nullable();
            $table->string('incident_code', 120)->nullable();
            $table->string('evaluation_path', 1000)->nullable();
            $table->json('before_state');
            $table->json('after_state');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_ai_quality_rollout_events');
    }
};
