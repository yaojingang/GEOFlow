<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->string('state', 20)->default('completed')->index();
            $table->char('owner_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->dropIndex(['state']);
            $table->dropIndex(['lease_expires_at']);
            $table->dropColumn(['state', 'owner_token', 'lease_expires_at']);
        });
    }
};
