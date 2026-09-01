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
        Schema::table('article_ai_quality_checks', function (Blueprint $table) {
            $table->string('evaluation_mode', 32)->default('primary')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('article_ai_quality_checks')
            ->where('evaluation_mode', 'optimization_candidate')
            ->update(['evaluation_mode' => 'shadow']);
        DB::table('article_ai_quality_checks')
            ->where('evaluation_mode', 'optimization_final')
            ->update(['evaluation_mode' => 'primary']);
        Schema::table('article_ai_quality_checks', function (Blueprint $table) {
            $table->string('evaluation_mode', 20)->default('primary')->change();
        });
    }
};
