<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->string('ai_workspace_readiness_status', 30)->nullable();
            $table->json('ai_workspace_readiness_profile')->nullable();
            $table->timestamp('ai_workspace_readiness_checked_at', 6)->nullable();
            $table->timestamp('ai_workspace_readiness_expires_at', 6)->nullable();
            $table->string('ai_workspace_readiness_failure_code', 80)->nullable();
            $table->index(
                ['ai_workspace_readiness_status', 'ai_workspace_readiness_expires_at'],
                'ai_models_workspace_readiness_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->dropIndex('ai_models_workspace_readiness_idx');
            $table->dropColumn([
                'ai_workspace_readiness_status',
                'ai_workspace_readiness_profile',
                'ai_workspace_readiness_checked_at',
                'ai_workspace_readiness_expires_at',
                'ai_workspace_readiness_failure_code',
            ]);
        });
    }
};
