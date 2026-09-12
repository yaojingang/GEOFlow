<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_publications') && ! Schema::hasColumn('manual_publications', 'source_distribution_id')) {
            Schema::table('manual_publications', function (Blueprint $table): void {
                $table->unsignedBigInteger('source_distribution_id')->nullable()->after('article_id');
                $table->index('source_distribution_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('manual_publications') && Schema::hasColumn('manual_publications', 'source_distribution_id')) {
            Schema::table('manual_publications', function (Blueprint $table): void {
                $table->dropIndex(['source_distribution_id']);
                $table->dropColumn('source_distribution_id');
            });
        }
    }
};
