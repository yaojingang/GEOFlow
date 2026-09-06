<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_visibility_runs', function (Blueprint $table): void {
            $table->foreignId('parent_run_id')->nullable()->constrained('ai_visibility_runs')->cascadeOnDelete();
            $table->index(['parent_run_id', 'provider_type', 'status'], 'ai_visibility_parent_provider_status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_visibility_runs', function (Blueprint $table): void {
            $table->dropIndex('ai_visibility_parent_provider_status');
            $table->dropConstrainedForeignId('parent_run_id');
        });
    }
};
