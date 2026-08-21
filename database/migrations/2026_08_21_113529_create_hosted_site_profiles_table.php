<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosted_site_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribution_channel_id')
                ->unique()
                ->constrained('distribution_channels')
                ->cascadeOnDelete();
            $table->string('hostname', 253)->unique();
            $table->string('root_domain', 253)->index();
            $table->string('topic', 160)->default('');
            $table->string('locale', 16)->default('zh_CN');
            $table->string('timezone', 64)->default('Asia/Shanghai');
            $table->unsignedSmallInteger('daily_publish_limit')->default(3);
            $table->unsignedSmallInteger('publish_weight')->default(100);
            $table->unsignedInteger('min_publish_interval_minutes')->default(360);
            $table->unsignedSmallInteger('min_articles_before_index')->default(10);
            $table->string('serving_status', 24)->default('maintenance');
            $table->string('indexing_status', 24)->default('noindex');
            $table->string('quality_status', 24)->default('pending');
            $table->unsignedInteger('settings_version')->default(1);
            $table->unsignedInteger('consecutive_publish_failures')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(
                ['root_domain', 'serving_status', 'indexing_status'],
                'hosted_profiles_root_serving_indexing_idx'
            );
            $table->index(
                ['cooldown_until', 'last_published_at'],
                'hosted_profiles_cooldown_published_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosted_site_profiles');
    }
};
