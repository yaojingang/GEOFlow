<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_source_providers')) {
            return;
        }

        Schema::create('ai_source_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('provider_key', 80);
            $table->string('endpoint_url', 500);
            $table->text('api_key');
            $table->string('status', 40)->default('active');
            $table->unsignedInteger('daily_limit')->default(0);
            $table->unsignedInteger('used_today')->default(0);
            $table->unsignedBigInteger('total_used')->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['provider_key', 'status'], 'ai_source_providers_key_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_source_providers');
    }
};
