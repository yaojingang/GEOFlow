<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_visibility_topics')) {
            Schema::create('ai_visibility_topics', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120)->unique();
                $table->text('description')->nullable();
                $table->json('brand_aliases')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_visibility_topic_keywords')) {
            Schema::create('ai_visibility_topic_keywords', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ai_visibility_topic_id')->constrained('ai_visibility_topics')->cascadeOnDelete();
                $table->string('keyword', 255);
                $table->char('keyword_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('ai_visibility_runs')
            && ! Schema::hasColumn('ai_visibility_runs', 'keyword_hash')) {
            Schema::table('ai_visibility_runs', function (Blueprint $table): void {
                $table->foreignId('ai_visibility_topic_id')->nullable()->after('keyword')->constrained('ai_visibility_topics')->nullOnDelete();
                $table->char('keyword_hash', 64)->nullable()->after('ai_visibility_topic_id');
                $table->index('keyword_hash', 'ai_visibility_runs_keyword_hash_idx');
                $table->index('ai_visibility_topic_id', 'ai_visibility_runs_topic_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_visibility_runs')
            && Schema::hasColumn('ai_visibility_runs', 'keyword_hash')) {
            Schema::table('ai_visibility_runs', function (Blueprint $table): void {
                $table->dropIndex('ai_visibility_runs_topic_idx');
                $table->dropForeign(['ai_visibility_topic_id']);
                $table->dropIndex('ai_visibility_runs_keyword_hash_idx');
                $table->dropColumn(['ai_visibility_topic_id', 'keyword_hash']);
            });
        }

        Schema::dropIfExists('ai_visibility_topic_keywords');
        Schema::dropIfExists('ai_visibility_topics');
    }
};
