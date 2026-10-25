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
        Schema::table('hosted_site_allocation_requests', function (Blueprint $table): void {
            $table->json('workflow_fence')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hosted_site_allocation_requests', function (Blueprint $table): void {
            $table->dropColumn('workflow_fence');
        });
    }
};
