<?php

use App\Support\TopicMigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t): void {
            $t->string('name', 200)->change();
            $t->unsignedBigInteger('title_library_id')->nullable()->change();
            $t->unsignedBigInteger('prompt_id')->nullable()->change();
            $t->unsignedBigInteger('ai_model_id')->nullable()->change();
            $t->string('content_type', 16)->default('article')->index();
            $t->string('target_site_key', 80)->nullable();
            $t->unsignedInteger('topic_limit')->default(10);
            $t->json('topic_settings')->nullable();
            $t->unsignedInteger('topic_config_version')->default(1);
        });
        Schema::table('topics', function (Blueprint $t): void {
            $t->timestamp('result_completed_at')->nullable();
            $t->unsignedInteger('control_version')->default(0);
            $t->unsignedBigInteger('approved_revision_id')->nullable();
            $t->foreignId('approved_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedInteger('manual_edit_version')->default(0);
            $t->foreignId('maintenance_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $t->timestamp('maintenance_paused_at')->nullable();
            $t->json('maintenance_settings')->nullable();
            $t->string('last_maintenance_signature', 64)->nullable();
            $t->timestamp('next_maintenance_at')->nullable();
        });
        Schema::table('task_runs', function (Blueprint $t): void {
            $t->string('content_type', 16)->default('article');
            $t->foreignId('topic_id')->nullable()->constrained('topics')->nullOnDelete();
        });
        Schema::create('topic_import_batches', function (Blueprint $t): void {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('owner_admin_id')->constrained('admins');
            $t->string('site_key', 80);
            $t->json('settings');
            $t->json('rows');
            $t->string('status', 32)->default('pending');
            $t->unsignedInteger('generation')->default(1);
            $t->timestamps();
        });
        Schema::create('topic_build_runs', function (Blueprint $t): void {
            $t->id();
            $t->string('request_key', 190)->unique();
            $t->string('site_key', 80);
            $t->foreignId('topic_id')->nullable()->constrained('topics');
            $t->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $t->foreignId('task_run_id')->nullable()->constrained('task_runs')->nullOnDelete();
            $t->foreignId('title_id')->nullable()->constrained('titles')->nullOnDelete();
            $t->foreignId('batch_id')->nullable()->constrained('topic_import_batches');
            $t->unsignedInteger('row_number')->nullable();
            $t->foreignId('owner_admin_id')->constrained('admins');
            $t->json('identity');
            $t->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $t->unsignedInteger('expected_version')->nullable();
            $t->unsignedInteger('expected_control_version')->nullable();
            $t->unsignedInteger('config_version')->nullable();
            $t->uuid('expected_execution_lease_token')->nullable();
            $t->timestamp('publication_completed_at')->nullable();
            $t->json('input');
            $t->json('telemetry')->nullable();
            $t->string('status', 32)->default('pending');
            $t->string('phase', 32)->default('waiting');
            $t->json('result')->nullable();
            $t->text('error')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['task_id', 'title_id']);
            $t->index(['topic_id', 'status']);
            $t->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        TopicMigrationGuard::assertEmpty();
        Schema::table('topics', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('maintenance_task_id');
            $t->dropConstrainedForeignId('approved_by_admin_id');
            $t->dropColumn(['result_completed_at', 'control_version', 'approved_revision_id', 'approved_at', 'manual_edit_version', 'maintenance_paused_at', 'maintenance_settings', 'last_maintenance_signature', 'next_maintenance_at']);
        });
        Schema::dropIfExists('topic_build_runs');
        Schema::dropIfExists('topic_import_batches');
        Schema::table('task_runs', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('topic_id');
            $t->dropColumn('content_type');
        });
        Schema::table('tasks', function (Blueprint $t): void {
            $t->dropIndex(['content_type']);
            $t->dropColumn(['content_type', 'target_site_key', 'topic_limit', 'topic_settings', 'topic_config_version']);
        });
    }
};
