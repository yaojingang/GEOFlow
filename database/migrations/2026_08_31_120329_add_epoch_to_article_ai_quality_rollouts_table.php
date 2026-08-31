<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_ai_quality_rollouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('epoch')->default(1)->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('article_ai_quality_rollouts', function (Blueprint $table): void {
            $table->dropColumn('epoch');
        });
    }
};
