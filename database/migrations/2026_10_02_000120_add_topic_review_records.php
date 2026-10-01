<?php

use App\Support\TopicMigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', fn (Blueprint $table) => $table->json('review_records')->nullable());
    }

    public function down(): void
    {
        TopicMigrationGuard::assertEmpty();
        Schema::table('topics', fn (Blueprint $table) => $table->dropColumn('review_records'));
    }
};
