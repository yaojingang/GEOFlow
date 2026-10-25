<?php

use App\Support\TopicMigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_revisions', function (Blueprint $table): void {
            $table->json('freshness_snapshot_json')->nullable();
        });
        Schema::table('topics', function (Blueprint $table): void {
            $table->timestamp('draft_content_composed_at')->nullable();
            $table->unsignedBigInteger('freshness_revision_id')->nullable();
            $table->string('freshness_status', 20)->nullable();
            $table->timestamp('freshness_checked_at')->nullable();
            $table->timestamp('freshness_next_check_at')->nullable()->index();
        });
        Schema::create('topic_freshness_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->foreignId('topic_revision_id')->constrained('topic_revisions')->cascadeOnDelete();
            $table->string('previous_status', 20)->nullable();
            $table->string('status', 20);
            $table->string('event_hint')->nullable();
            $table->text('coverage_note')->nullable();
            $table->timestamp('changed_at');
            $table->timestamp('reminder_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        TopicMigrationGuard::assertEmpty();
        Schema::dropIfExists('topic_freshness_changes');
        Schema::table('topics', function (Blueprint $table): void {
            $table->dropIndex(['freshness_next_check_at']);
            $table->dropColumn(['draft_content_composed_at', 'freshness_revision_id', 'freshness_status', 'freshness_checked_at', 'freshness_next_check_at']);
        });
        Schema::table('topic_revisions', fn (Blueprint $table) => $table->dropColumn('freshness_snapshot_json'));
    }
};
