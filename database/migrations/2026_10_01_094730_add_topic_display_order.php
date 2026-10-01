<?php

use App\Support\TopicMigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table): void {
            $table->integer('display_order')->default(0);
            $table->index(['site_key', 'display_order', 'first_published_at', 'id'], 'topics_channel_order_index');
        });
    }

    public function down(): void
    {
        TopicMigrationGuard::assertEmpty();
        Schema::table('topics', function (Blueprint $table): void {
            $table->dropIndex('topics_channel_order_index');
            $table->dropColumn('display_order');
        });
    }
};
