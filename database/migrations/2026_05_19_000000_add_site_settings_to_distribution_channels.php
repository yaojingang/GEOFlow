<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distribution_channels') || Schema::hasColumn('distribution_channels', 'site_settings')) {
            return;
        }

        Schema::table('distribution_channels', function (Blueprint $table): void {
            $table->json('site_settings')->nullable()->after('template_key');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('distribution_channels') || ! Schema::hasColumn('distribution_channels', 'site_settings')) {
            return;
        }

        Schema::table('distribution_channels', function (Blueprint $table): void {
            $table->dropColumn('site_settings');
        });
    }
};
