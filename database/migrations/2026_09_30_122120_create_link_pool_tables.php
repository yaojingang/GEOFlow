<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('link_pool_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('target_site_key', 64);
            $table->unsignedBigInteger('article_id');
            $table->text('input_url');
            $table->string('input_title', 120);
            $table->string('display_title', 120);
            $table->string('topic', 120)->default('');
            $table->unsignedTinyInteger('priority')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['target_site_key', 'article_id'], 'link_target_site_article_unique');
            $table->index(['target_site_key', 'status'], 'link_target_site_status_index');
        });

        Schema::create('link_pool_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('created_by');
            $table->json('created_target_ids_json');
            $table->json('counts_json');
            $table->timestamp('undone_at')->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::create('link_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('source_site_key', 64);
            $table->string('name', 120);
            $table->string('mode', 20);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->unsignedTinyInteger('limit')->default(4);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('source_rules_json');
            $table->json('exclusions_json');
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['source_site_key', 'status', 'priority'], 'link_campaign_site_status_priority');
        });

        Schema::create('link_campaign_drafts', function (Blueprint $table): void {
            $table->unsignedBigInteger('campaign_id')->primary();
            $table->unsignedInteger('base_revision');
            $table->json('payload_json');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('link_campaign_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('target_id');
            $table->unsignedSmallInteger('weight')->default(1);
            $table->unsignedTinyInteger('pin_order')->nullable();
            $table->string('cross_site_review_status', 20)->default('not_required');
            $table->text('editorial_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unique(['campaign_id', 'target_id'], 'link_campaign_target_unique');
            $table->index('target_id');
        });

        Schema::create('link_campaign_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('source_article_id');
            $table->unique(['campaign_id', 'source_article_id'], 'link_campaign_source_unique');
        });

        Schema::create('link_selection_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('source_site_key', 64);
            $table->unsignedBigInteger('source_article_id');
            $table->string('rule_fingerprint', 64);
            $table->unsignedInteger('sequence');
            $table->timestamp('generated_at');
            $table->timestamp('rotate_after');
            $table->index(['source_site_key', 'source_article_id'], 'link_batch_source_index');
            $table->index('generated_at', 'link_batch_generated_index');
        });

        Schema::create('link_selection_heads', function (Blueprint $table): void {
            $table->string('source_site_key', 64);
            $table->unsignedBigInteger('source_article_id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedInteger('revision')->default(1);
            $table->primary(['source_site_key', 'source_article_id'], 'link_head_source_primary');
            $table->index('batch_id');
        });

        Schema::create('link_placements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->string('source_site_key', 64);
            $table->unsignedBigInteger('source_article_id');
            $table->string('target_site_key', 64);
            $table->unsignedBigInteger('target_article_id');
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedTinyInteger('slot');
            $table->string('reason_code', 40);
            $table->integer('score')->default(0);
            $table->timestamp('selected_at');
            $table->unique(['batch_id', 'target_site_key', 'target_article_id'], 'link_placement_target_unique');
            $table->index(['target_site_key', 'target_article_id'], 'link_placement_inbound_index');
        });

        Schema::create('link_pool_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('event_type', 16);
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('placement_id');
            $table->string('source_site_key', 64);
            $table->string('target_site_key', 64);
            $table->unsignedBigInteger('source_article_id');
            $table->unsignedBigInteger('target_article_id');
            $table->unsignedBigInteger('campaign_id');
            $table->timestamp('created_at')->index();
        });

        Schema::create('link_pool_html_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamp('created_at')->index();
        });

        Schema::create('link_metrics_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('metric_key', 64)->unique();
            $table->date('date');
            $table->string('source_site_key', 64);
            $table->string('target_site_key', 64);
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('source_article_id');
            $table->unsignedBigInteger('target_article_id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedInteger('html_served')->default(0);
            $table->unsignedInteger('viewable')->default(0);
            $table->unsignedInteger('human_clicks')->default(0);
            $table->index(['date', 'source_site_key'], 'link_metrics_date_site_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('link_metrics_daily');
        Schema::dropIfExists('link_pool_html_deliveries');
        Schema::dropIfExists('link_pool_events');
        Schema::dropIfExists('link_placements');
        Schema::dropIfExists('link_selection_heads');
        Schema::dropIfExists('link_selection_batches');
        Schema::dropIfExists('link_campaign_sources');
        Schema::dropIfExists('link_campaign_targets');
        Schema::dropIfExists('link_campaign_drafts');
        Schema::dropIfExists('link_campaigns');
        Schema::dropIfExists('link_pool_import_batches');
        Schema::dropIfExists('link_pool_targets');
    }
};
