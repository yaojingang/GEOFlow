<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_fact_libraries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_base_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('workflow_status', 32)->default('idle');
            $table->string('serving_status', 32)->default('unavailable');
            $table->unsignedInteger('working_version')->default(1);
            $table->char('active_hash', 64)->nullable();
            $table->char('source_hash', 64)->nullable();
            $table->longText('active_health_json')->nullable();
            $table->timestamps();
            $table->index(['workflow_status', 'serving_status']);
        });

        Schema::create('knowledge_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_id')->constrained('knowledge_fact_libraries')->cascadeOnDelete();
            $table->string('stable_key', 160);
            $table->string('label');
            $table->string('subject');
            $table->string('predicate');
            $table->string('value_type', 32);
            $table->string('locale', 16)->default('zh_CN');
            $table->longText('aliases_json')->nullable();
            $table->string('importance', 16)->default('normal');
            $table->string('usage_scope', 32)->default('quality_only');
            $table->string('review_status', 16)->default('draft');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->unique(['library_id', 'stable_key']);
            $table->index(['library_id', 'review_status', 'is_enabled']);
        });

        Schema::create('knowledge_fact_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fact_id')->constrained('knowledge_facts')->cascadeOnDelete();
            $table->longText('canonical_value_json');
            $table->text('canonical_answer');
            $table->string('temporal_kind', 16)->default('timeless');
            $table->longText('scope_json')->nullable();
            $table->char('scope_hash', 64);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->longText('comparison_policy_json')->nullable();
            $table->string('review_status', 16)->default('draft');
            $table->string('conflict_status', 16)->default('clear');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['fact_id', 'review_status', 'conflict_status']);
            $table->index(['fact_id', 'scope_hash', 'valid_from', 'valid_to'], 'knowledge_fact_values_interval_idx');
        });

        Schema::create('knowledge_fact_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('value_id')->constrained('knowledge_fact_values')->cascadeOnDelete();
            $table->foreignId('knowledge_chunk_id')->nullable()->constrained('knowledge_chunks')->nullOnDelete();
            $table->char('source_hash', 64);
            $table->char('content_hash', 64);
            $table->longText('source_locator_json')->nullable();
            $table->text('excerpt');
            $table->char('excerpt_hash', 64);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['value_id', 'is_primary']);
            $table->index(['source_hash', 'content_hash']);
        });

        Schema::create('knowledge_fact_library_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_id')->constrained('knowledge_fact_libraries')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->char('library_hash', 64);
            $table->char('source_hash', 64);
            $table->longText('manifest_json');
            $table->foreignId('published_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('published_at');
            $table->foreignId('restored_from_revision_id')->nullable()->constrained('knowledge_fact_library_revisions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['library_id', 'version']);
            $table->unique(['library_id', 'library_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_fact_library_revisions');
        Schema::dropIfExists('knowledge_fact_evidences');
        Schema::dropIfExists('knowledge_fact_values');
        Schema::dropIfExists('knowledge_facts');
        Schema::dropIfExists('knowledge_fact_libraries');
    }
};
