<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_channels')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                if (! Schema::hasColumn('distribution_channels', 'channel_type')) {
                    $table->string('channel_type', 60)->default('geoflow_agent');
                }
                if (! Schema::hasColumn('distribution_channels', 'site_settings')) {
                    $table->json('site_settings')->nullable();
                }
            });
        }

        if (Schema::hasTable('task_distribution_channels')) {
            Schema::table('task_distribution_channels', function (Blueprint $table): void {
                if (! Schema::hasColumn('task_distribution_channels', 'trigger')) {
                    $table->string('trigger', 60)->default('after_local_publish');
                }
                if (! Schema::hasColumn('task_distribution_channels', 'remote_status')) {
                    $table->string('remote_status', 40)->default('follow_local');
                }
                if (! Schema::hasColumn('task_distribution_channels', 'failure_policy')) {
                    $table->string('failure_policy', 60)->default('ignore_distribution_failure');
                }
                if (! Schema::hasColumn('task_distribution_channels', 'max_attempts')) {
                    $table->unsignedSmallInteger('max_attempts')->default(3);
                }
            });
        }

        if (Schema::hasTable('article_distributions')) {
            Schema::table('article_distributions', function (Blueprint $table): void {
                if (! Schema::hasColumn('article_distributions', 'idempotency_key')) {
                    $table->string('idempotency_key', 120)->nullable();
                }
            });

            if (Schema::hasColumn('article_distributions', 'idempotency_key')) {
                DB::table('article_distributions')
                    ->whereNull('idempotency_key')
                    ->orWhere('idempotency_key', '')
                    ->orderBy('id')
                    ->get(['id', 'article_id', 'distribution_channel_id', 'action'])
                    ->each(function (object $row): void {
                        DB::table('article_distributions')
                            ->where('id', (int) $row->id)
                            ->update([
                                'idempotency_key' => sprintf(
                                    'article-%d-channel-%d-%s-distribution-%d-v1',
                                    (int) $row->article_id,
                                    (int) $row->distribution_channel_id,
                                    (string) ($row->action ?: 'publish'),
                                    (int) $row->id
                                ),
                            ]);
                    });
            }
        }

        if (Schema::hasTable('distribution_logs')) {
            Schema::table('distribution_logs', function (Blueprint $table): void {
                if (! Schema::hasColumn('distribution_logs', 'event')) {
                    $table->string('event', 120)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('distribution_logs') && Schema::hasColumn('distribution_logs', 'event')) {
            Schema::table('distribution_logs', function (Blueprint $table): void {
                $table->dropColumn('event');
            });
        }

        if (Schema::hasTable('article_distributions') && Schema::hasColumn('article_distributions', 'idempotency_key')) {
            Schema::table('article_distributions', function (Blueprint $table): void {
                $table->dropColumn('idempotency_key');
            });
        }

        if (Schema::hasTable('task_distribution_channels')) {
            Schema::table('task_distribution_channels', function (Blueprint $table): void {
                foreach (['trigger', 'remote_status', 'failure_policy', 'max_attempts'] as $column) {
                    if (Schema::hasColumn('task_distribution_channels', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('distribution_channels') && Schema::hasColumn('distribution_channels', 'channel_type')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->dropColumn('channel_type');
            });
        }

        if (Schema::hasTable('distribution_channels') && Schema::hasColumn('distribution_channels', 'site_settings')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->dropColumn('site_settings');
            });
        }
    }
};
