<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_channels') && ! Schema::hasColumn('distribution_channels', 'channel_config')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->json('channel_config')->nullable()->after('site_settings');
            });
        }

        if (Schema::hasTable('article_distributions') && ! Schema::hasColumn('article_distributions', 'remote_meta')) {
            Schema::table('article_distributions', function (Blueprint $table): void {
                $table->json('remote_meta')->nullable()->after('remote_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('article_distributions') && Schema::hasColumn('article_distributions', 'remote_meta')) {
            Schema::table('article_distributions', function (Blueprint $table): void {
                $table->dropColumn('remote_meta');
            });
        }

        if (Schema::hasTable('distribution_channels') && Schema::hasColumn('distribution_channels', 'channel_config')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->dropColumn('channel_config');
            });
        }
    }
};
