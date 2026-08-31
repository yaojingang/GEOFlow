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
        Schema::table('article_ai_quality_rollouts', function (Blueprint $table) {
            $table->unsignedTinyInteger('atomic_shadow_percent')->default(0)->after('shadow_percent');
            $table->unsignedTinyInteger('atomic_fact_percent')->default(0)->after('atomic_shadow_percent');
            $table->boolean('atomic_fact_frozen')->default(false)->after('atomic_fact_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_ai_quality_rollouts', function (Blueprint $table) {
            $table->dropColumn(['atomic_shadow_percent', 'atomic_fact_percent', 'atomic_fact_frozen']);
        });
    }
};
