<?php

use App\Support\TopicMigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table): void {
            $table->id();
            $table->string('site_key', 80);
            $table->string('slug', 120);
            $table->string('title');
            $table->char('normalized_title_key', 64);
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->json('draft_payload');
            $table->json('draft_source_hashes')->nullable();
            $table->json('draft_score_binding')->nullable();
            $table->json('publication_requests')->nullable();
            $table->json('withdrawal_requests')->nullable();
            $table->unsignedInteger('draft_version')->default(1);
            $table->unsignedBigInteger('public_revision_id')->nullable();
            $table->unsignedBigInteger('pending_revision_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('published_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('withdrawn_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['site_key', 'slug']);
            $table->unique(['site_key', 'normalized_title_key'], 'topics_site_title_unique');
            $table->index(['site_key', 'deleted_at', 'public_revision_id']);
        });

        Schema::create('topic_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('draft_version');
            $table->json('payload');
            $table->string('request_id', 100)->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['topic_id', 'number']);
            $table->unique(['topic_id', 'draft_version']);
            $table->unique(['topic_id', 'request_id']);
        });

        Schema::create('topic_revision_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_revision_id')->constrained('topic_revisions')->cascadeOnDelete();
            $table->unsignedBigInteger('article_id')->index();
            $table->unsignedInteger('sort_order');
            $table->string('group', 100)->default('');
            $table->text('reason')->nullable();
            $table->char('content_hash', 64);
            $table->json('snapshot');
            $table->unique(['topic_revision_id', 'article_id']);
            $table->unique(['topic_revision_id', 'sort_order']);
        });

        Schema::create('topic_source_invalidations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->foreignId('topic_revision_id')->nullable()->constrained('topic_revisions')->cascadeOnDelete();
            $table->string('basis_key', 100);
            $table->unsignedBigInteger('article_id');
            $table->string('reason', 40);
            $table->timestamp('invalidated_at');
            $table->unique(['basis_key', 'article_id'], 'topic_source_invalidations_basis_article_unique');
            $table->index(['topic_id', 'topic_revision_id']);
        });

        Schema::table('topics', function (Blueprint $table): void {
            $table->foreign('public_revision_id')->references('id')->on('topic_revisions')->nullOnDelete();
            $table->foreign('pending_revision_id')->references('id')->on('topic_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        TopicMigrationGuard::assertEmpty();
        Schema::table('topics', function (Blueprint $table): void {
            $table->dropForeign(['public_revision_id']);
            $table->dropForeign(['pending_revision_id']);
        });
        Schema::dropIfExists('topic_source_invalidations');
        Schema::dropIfExists('topic_revision_articles');
        Schema::dropIfExists('topic_revisions');
        Schema::dropIfExists('topics');
    }
};
