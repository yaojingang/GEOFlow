<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('geo_publish_records')) {
            return;
        }

        Schema::table('geo_publish_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('geo_publish_records', 'platform_codes')) {
                $table->json('platform_codes')->nullable()->after('geo_publish_target_id');
            }
            if (! Schema::hasColumn('geo_publish_records', 'handoff_payload')) {
                $table->json('handoff_payload')->nullable()->after('platform_codes');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('geo_publish_records')) {
            return;
        }

        Schema::table('geo_publish_records', function (Blueprint $table): void {
            if (Schema::hasColumn('geo_publish_records', 'handoff_payload')) {
                $table->dropColumn('handoff_payload');
            }
            if (Schema::hasColumn('geo_publish_records', 'platform_codes')) {
                $table->dropColumn('platform_codes');
            }
        });
    }
};
