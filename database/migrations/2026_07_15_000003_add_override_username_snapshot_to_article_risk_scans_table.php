<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_risk_scans', function (Blueprint $table): void {
            $table->string('overridden_by_username', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('article_risk_scans', function (Blueprint $table): void {
            $table->dropColumn('overridden_by_username');
        });
    }
};
