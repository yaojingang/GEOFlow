<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('url_import_jobs')) {
            Schema::create('url_import_jobs', function (Blueprint $table): void {
                $table->id();
                $table->text('url');
                $table->text('normalized_url');
                $table->string('source_domain')->default('');
                $table->string('page_title')->default('');
                $table->string('status', 20)->default('queued');
                $table->string('current_step', 50)->default('queued');
                $table->integer('progress_percent')->default(0);
                $table->text('options_json')->default('');
                $table->text('result_json')->default('');
                $table->text('error_message')->default('');
                $table->string('created_by', 100)->default('');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('url_import_job_logs')) {
            Schema::create('url_import_job_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('job_id')->constrained('url_import_jobs')->cascadeOnDelete();
                $table->string('level', 20)->default('info');
                $table->text('message');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('url_import_job_logs');
        Schema::dropIfExists('url_import_jobs');
    }
};
