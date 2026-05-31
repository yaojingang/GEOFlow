<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('geo_ai_search_runs')) {
            return;
        }

        Schema::table('geo_ai_search_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('geo_ai_search_runs', 'target_keyword_hit_rate')) {
                $table->unsignedTinyInteger('target_keyword_hit_rate')->nullable();
            }

            if (! Schema::hasColumn('geo_ai_search_runs', 'keyword_hit_rate')) {
                $table->unsignedTinyInteger('keyword_hit_rate')->nullable();
            }

            if (! Schema::hasColumn('geo_ai_search_runs', 'previous_keyword_hit_rate')) {
                $table->unsignedTinyInteger('previous_keyword_hit_rate')->nullable();
            }

            if (! Schema::hasColumn('geo_ai_search_runs', 'baseline_keyword_hit_rate')) {
                $table->unsignedTinyInteger('baseline_keyword_hit_rate')->nullable();
            }

            if (! Schema::hasColumn('geo_ai_search_runs', 'keyword_hit_rate_delta')) {
                $table->smallInteger('keyword_hit_rate_delta')->nullable();
            }

            if (! Schema::hasColumn('geo_ai_search_runs', 'keyword_hit_count')) {
                $table->unsignedInteger('keyword_hit_count')->default(0);
            }

            if (! Schema::hasColumn('geo_ai_search_runs', 'keyword_check_count')) {
                $table->unsignedInteger('keyword_check_count')->default(0);
            }

            if (! Schema::hasColumn('geo_ai_search_runs', 'optimization_directions')) {
                $table->json('optimization_directions')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('geo_ai_search_runs')) {
            return;
        }

        Schema::table('geo_ai_search_runs', function (Blueprint $table): void {
            foreach ([
                'target_keyword_hit_rate',
                'keyword_hit_rate',
                'previous_keyword_hit_rate',
                'baseline_keyword_hit_rate',
                'keyword_hit_rate_delta',
                'keyword_hit_count',
                'keyword_check_count',
                'optimization_directions',
            ] as $column) {
                if (Schema::hasColumn('geo_ai_search_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
