<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('link_pool_targets', function (Blueprint $table): void {
            $table->unsignedBigInteger('article_id')->nullable()->change();
            $table->string('target_type', 20)->default('article');
            $table->string('url_sha256', 64)->nullable()->unique();
            $table->string('health_status', 20)->default('not_required');
            $table->unsignedSmallInteger('health_http_status')->nullable();
            $table->string('health_message', 255)->nullable();
            $table->timestamp('health_checked_at')->nullable();
        });
        foreach (['link_placements', 'link_pool_events', 'link_metrics_daily'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('target_article_id')->nullable()->change();
                $table->unsignedBigInteger('target_id')->nullable()->index();
            });
            DB::table($name)->update(['target_id' => DB::raw('(SELECT id FROM link_pool_targets WHERE link_pool_targets.target_site_key = '.$name.'.target_site_key AND link_pool_targets.article_id = '.$name.'.target_article_id)')]);
        }
        Schema::table('link_placements', function (Blueprint $table): void {
            $table->unique(['batch_id', 'target_id'], 'link_placement_target_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('link_pool_targets')->where('target_type', 'external')->exists()
            || DB::table('link_placements')->whereNull('target_article_id')->exists()
            || DB::table('link_pool_events')->whereNull('target_article_id')->exists()
            || DB::table('link_metrics_daily')->whereNull('target_article_id')->exists()) {
            throw new RuntimeException('External link data exists; keep this migration and apply a forward repair.');
        }
        Schema::table('link_placements', function (Blueprint $table): void {
            $table->dropUnique('link_placement_target_id_unique');
        });
        foreach (['link_placements', 'link_pool_events', 'link_metrics_daily'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_target_id_index');
                $table->dropColumn('target_id');
                $table->unsignedBigInteger('target_article_id')->nullable(false)->change();
            });
        }
        Schema::table('link_pool_targets', function (Blueprint $table): void {
            $table->dropUnique(['url_sha256']);
            $table->dropColumn(['target_type', 'url_sha256', 'health_status', 'health_http_status', 'health_message', 'health_checked_at']);
            $table->unsignedBigInteger('article_id')->nullable(false)->change();
        });
    }
};
