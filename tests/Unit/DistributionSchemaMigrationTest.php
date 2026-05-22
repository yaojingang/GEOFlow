<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DistributionSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_distribution_migrations_upgrade_existing_legacy_tables(): void
    {
        $this->createLegacyDistributionTables();

        foreach (glob(database_path('migrations/*distribution*.php')) ?: [] as $migrationPath) {
            $migration = require $migrationPath;
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumn('distribution_channels', 'channel_type'));
        $this->assertTrue(Schema::hasColumn('distribution_channels', 'site_settings'));
        $this->assertTrue(Schema::hasColumn('distribution_channels', 'front_mode'));
        $this->assertTrue(Schema::hasColumn('task_distribution_channels', 'trigger'));
        $this->assertTrue(Schema::hasColumn('task_distribution_channels', 'remote_status'));
        $this->assertTrue(Schema::hasColumn('task_distribution_channels', 'failure_policy'));
        $this->assertTrue(Schema::hasColumn('task_distribution_channels', 'max_attempts'));
        $this->assertTrue(Schema::hasColumn('tasks', 'publish_scope'));
        $this->assertTrue(Schema::hasColumn('article_distributions', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('distribution_logs', 'event'));
    }

    private function createLegacyDistributionTables(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('distribution_logs');
        Schema::dropIfExists('article_distributions');
        Schema::dropIfExists('task_distribution_channels');
        Schema::dropIfExists('distribution_channel_secrets');
        Schema::dropIfExists('distribution_channels');
        Schema::enableForeignKeyConstraints();

        Schema::create('distribution_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('domain', 255);
            $table->string('endpoint_url', 500);
            $table->string('template_key', 120)->nullable();
            $table->string('status', 30)->default('active');
            $table->text('description')->nullable();
            $table->string('last_health_status', 30)->nullable();
            $table->timestamp('last_health_checked_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps();
        });

        Schema::create('distribution_channel_secrets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('distribution_channel_id');
            $table->string('key_id', 80);
            $table->text('secret_ciphertext');
            $table->string('status', 30)->default('active');
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_distribution_channels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('distribution_channel_id');
            $table->timestamps();
        });

        Schema::create('article_distributions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('distribution_channel_id');
            $table->string('action', 30)->default('publish');
            $table->string('status', 30)->default('queued');
            $table->string('remote_id', 120)->nullable();
            $table->string('remote_url', 500)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('distribution_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('distribution_channel_id')->nullable();
            $table->unsignedBigInteger('article_distribution_id')->nullable();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('level', 20)->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
