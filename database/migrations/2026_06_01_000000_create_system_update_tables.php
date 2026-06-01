<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_update_runs')) {
            Schema::create('system_update_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('run_uuid', 64)->unique();
                $table->string('action', 30)->index();
                $table->string('status', 30)->index();
                $table->string('current_version', 50)->nullable();
                $table->string('target_version', 50)->nullable();
                $table->string('current_commit', 80)->nullable();
                $table->string('target_commit', 80)->nullable();
                $table->string('deployment_mode', 60)->nullable();
                $table->string('risk_level', 20)->nullable();
                $table->json('plan_json')->nullable();
                $table->string('plan_path', 500)->nullable();
                $table->string('backup_path', 500)->nullable();
                $table->string('log_path', 500)->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedBigInteger('started_by_admin_id')->nullable()->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('system_update_backups')) {
            Schema::create('system_update_backups', function (Blueprint $table): void {
                $table->id();
                $table->string('backup_uuid', 64)->unique();
                $table->foreignId('run_id')->nullable()->constrained('system_update_runs')->nullOnDelete();
                $table->string('from_version', 50)->nullable();
                $table->string('to_version', 50)->nullable();
                $table->string('from_commit', 80)->nullable();
                $table->string('to_commit', 80)->nullable();
                $table->string('backup_path', 500);
                $table->string('manifest_path', 500);
                $table->string('files_archive_path', 500)->nullable();
                $table->string('database_dump_path', 500)->nullable();
                $table->unsignedInteger('file_count')->default(0);
                $table->unsignedBigInteger('total_bytes')->default(0);
                $table->string('status', 30)->default('available')->index();
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_update_backups');
        Schema::dropIfExists('system_update_runs');
    }
};
