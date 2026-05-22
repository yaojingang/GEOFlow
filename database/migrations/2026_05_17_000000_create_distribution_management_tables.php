<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distribution_channels')) {
            Schema::create('distribution_channels', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('domain', 255);
                $table->string('endpoint_url', 500);
                $table->string('channel_type', 60)->default('geoflow_agent');
                $table->string('template_key', 120)->nullable();
                $table->json('site_settings')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->text('description')->nullable();
                $table->string('last_health_status', 30)->nullable();
                $table->timestamp('last_health_checked_at')->nullable();
                $table->text('last_error_message')->nullable();
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('distribution_channel_secrets')) {
            Schema::create('distribution_channel_secrets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('distribution_channel_id')->constrained('distribution_channels')->cascadeOnDelete();
                $table->string('key_id', 80)->unique();
                $table->text('secret_ciphertext');
                $table->string('status', 30)->default('active')->index();
                $table->json('scopes')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('task_distribution_channels')) {
            Schema::create('task_distribution_channels', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->foreignId('distribution_channel_id')->constrained('distribution_channels')->cascadeOnDelete();
                $table->string('trigger', 60)->default('after_local_publish');
                $table->string('remote_status', 40)->default('follow_local');
                $table->string('failure_policy', 60)->default('ignore_distribution_failure');
                $table->unsignedSmallInteger('max_attempts')->default(3);
                $table->timestamps();

                $table->unique(['task_id', 'distribution_channel_id'], 'task_distribution_channels_unique');
            });
        }

        if (! Schema::hasTable('article_distributions')) {
            Schema::create('article_distributions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
                $table->foreignId('distribution_channel_id')->constrained('distribution_channels')->cascadeOnDelete();
                $table->string('action', 30)->default('publish');
                $table->string('status', 30)->default('queued')->index();
                $table->string('remote_id', 120)->nullable();
                $table->string('remote_url', 500)->nullable();
                $table->string('idempotency_key', 120)->unique();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->timestamp('next_retry_at')->nullable()->index();
                $table->timestamp('last_attempt_at')->nullable();
                $table->text('last_error_message')->nullable();
                $table->string('payload_hash', 64)->nullable();
                $table->timestamps();

                $table->unique(['article_id', 'distribution_channel_id', 'action'], 'article_distribution_unique');
            });
        }

        if (! Schema::hasTable('distribution_logs')) {
            Schema::create('distribution_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('distribution_channel_id')->nullable()->index();
                $table->unsignedBigInteger('article_distribution_id')->nullable()->index();
                $table->unsignedBigInteger('article_id')->nullable()->index();
                $table->string('level', 20)->default('info');
                $table->string('event', 120)->nullable();
                $table->text('message');
                $table->json('context')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_logs');
        Schema::dropIfExists('article_distributions');
        Schema::dropIfExists('task_distribution_channels');
        Schema::dropIfExists('distribution_channel_secrets');
        Schema::dropIfExists('distribution_channels');
    }
};
