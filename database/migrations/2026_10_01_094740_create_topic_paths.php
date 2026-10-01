<?php

use App\Support\TopicMigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', fn (Blueprint $t) => $t->unsignedInteger('path_generation')->default(1));
        Schema::table('topic_revisions', fn (Blueprint $t) => $t->unsignedInteger('path_generation')->default(1));
        Schema::create('topic_paths', function (Blueprint $t): void {
            $t->id();
            $t->string('site_key', 80);
            $t->string('slug', 120);
            $t->foreignId('topic_id')->constrained('topics')->restrictOnDelete();
            $t->unsignedInteger('generation');
            $t->timestamps();
            $t->unique(['site_key', 'slug']);
            $t->index('topic_id');
        });
        DB::table('topics')->orderBy('id')->chunkById(100, function ($topics): void {
            foreach ($topics as $topic) {
                DB::table('topic_paths')->insert(['site_key' => $topic->site_key, 'slug' => $topic->slug, 'topic_id' => $topic->id, 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        TopicMigrationGuard::assertEmpty();
        Schema::dropIfExists('topic_paths');
        Schema::table('topic_revisions', fn (Blueprint $t) => $t->dropColumn('path_generation'));
        Schema::table('topics', fn (Blueprint $t) => $t->dropColumn('path_generation'));
    }
};
