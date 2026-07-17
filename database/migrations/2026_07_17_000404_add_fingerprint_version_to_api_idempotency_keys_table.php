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
        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            // Historical body-only rows remain identifiable after the drained security upgrade.
            $table->unsignedTinyInteger('fingerprint_version')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // A v2 hash cannot be reclassified as body-only v1 without losing replay safety.
        if (DB::table('api_idempotency_keys')->where('fingerprint_version', '<>', 1)->exists()) {
            throw new RuntimeException('Cannot roll back idempotency fingerprint versions while v2 rows exist.');
        }

        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->dropColumn('fingerprint_version');
        });
    }
};
