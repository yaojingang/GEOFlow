<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_visibility_runs')) {
            return;
        }

        Schema::table('ai_visibility_runs', function (Blueprint $table): void {
            $table->index(
                ['completed_at', 'created_at', 'status'],
                'ai_visibility_runs_event_date_status_idx',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_visibility_runs')) {
            return;
        }

        Schema::table('ai_visibility_runs', function (Blueprint $table): void {
            $table->dropIndex('ai_visibility_runs_event_date_status_idx');
        });
    }
};
