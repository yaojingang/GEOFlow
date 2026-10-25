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
        Schema::table('articles', function (Blueprint $table): void {
            // Historical drafts remain held until explicit reconciliation.
            $table->string('publication_intent', 16)->default('hold');
            $table->unsignedBigInteger('workflow_version')->default(1);
            $table->index(['task_id', 'publication_intent', 'status', 'id'], 'articles_publication_candidates');
        });
        Schema::table('tasks', fn (Blueprint $table) => $table->unsignedBigInteger('automation_version')->default(1));
        if (! Schema::hasTable('article_reviews')) {
            Schema::create('article_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('admins');
                $table->string('review_status', 20);
                $table->text('review_note')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
        Schema::table('article_reviews', function (Blueprint $table): void {
            $table->string('content_hash', 64)->nullable();
            $table->bigInteger('admin_id')->nullable()->change();
        });
        DB::table('articles')->where('status', 'published')->update(['publication_intent' => 'none']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_reviews', fn (Blueprint $table) => $table->dropColumn('content_hash'));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('automation_version'));
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex('articles_publication_candidates');
            $table->dropColumn(['publication_intent', 'workflow_version']);
        });
    }
};
