<?php

use App\Support\TopicMigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_build_runs', fn (Blueprint $t) => $t->uuid('dispatch_key')->nullable());
    }

    public function down(): void
    {
        TopicMigrationGuard::assertEmpty();
        Schema::table('topic_build_runs', fn (Blueprint $t) => $t->dropColumn('dispatch_key'));
    }
};
