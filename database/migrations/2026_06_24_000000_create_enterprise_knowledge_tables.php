<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enterprise_knowledge_projects')) {
            Schema::create('enterprise_knowledge_projects', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->string('status', 40)->default('draft');
                $table->longText('draft_content')->nullable();
                $table->longText('structured_json')->nullable();
                $table->longText('validation_json')->nullable();
                $table->foreignId('published_knowledge_base_id')->nullable()->constrained('knowledge_bases')->nullOnDelete();
                $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
                $table->text('error_message')->nullable();
                $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamps();

                $table->index(['status', 'updated_at'], 'enterprise_knowledge_projects_status_updated_idx');
            });
        }

        if (! Schema::hasTable('enterprise_knowledge_sources')) {
            Schema::create('enterprise_knowledge_sources', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('enterprise_knowledge_project_id')
                    ->constrained('enterprise_knowledge_projects')
                    ->cascadeOnDelete();
                $table->string('original_name', 255);
                $table->string('file_path', 500)->nullable();
                $table->string('file_type', 40)->default('text');
                $table->longText('content');
                $table->unsignedInteger('character_count')->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['enterprise_knowledge_project_id', 'sort_order'], 'enterprise_knowledge_sources_project_sort_idx');
            });
        }

        if (! Schema::hasTable('enterprise_knowledge_revisions')) {
            Schema::create('enterprise_knowledge_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('enterprise_knowledge_project_id')
                    ->constrained('enterprise_knowledge_projects')
                    ->cascadeOnDelete();
                $table->longText('content');
                $table->string('summary', 255)->nullable();
                $table->string('source', 40)->default('manual');
                $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('content_hash', 64)->nullable();
                $table->timestamps();

                $table->index(['enterprise_knowledge_project_id', 'created_at'], 'enterprise_knowledge_revisions_project_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_knowledge_revisions');
        Schema::dropIfExists('enterprise_knowledge_sources');
        Schema::dropIfExists('enterprise_knowledge_projects');
    }
};
