<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_distributions', function (Blueprint $table): void {
            $table->index(
                ['distribution_channel_id', 'status', 'id'],
                'article_distributions_permalink_refresh_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('article_distributions', function (Blueprint $table): void {
            $table->dropIndex('article_distributions_permalink_refresh_idx');
        });
    }
};
