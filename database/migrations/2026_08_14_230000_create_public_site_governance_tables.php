<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_facts', function (Blueprint $table): void {
            $table->id();
            $table->string('fact_code', 64)->unique();
            $table->string('entity_type', 40)->index();
            $table->text('statement');
            $table->unsignedTinyInteger('evidence_level')->default(0)->index();
            $table->string('evidence_label', 255)->default('');
            $table->text('evidence_url')->nullable();
            $table->string('visibility', 20)->default('internal')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->string('owner_name', 120)->default('');
            $table->date('effective_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('public_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('page_type', 40)->default('institution')->index();
            $table->string('area', 30)->default('institution')->index();
            $table->string('title', 255);
            $table->string('eyebrow', 120)->default('');
            $table->text('summary')->nullable();
            $table->longText('body')->default('');
            $table->string('seo_title', 255)->default('');
            $table->text('meta_description')->nullable();
            $table->string('cta_label', 120)->default('');
            $table->string('cta_url', 500)->default('');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_placeholder')->default(false);
            $table->string('status', 20)->default('draft')->index();
            $table->char('content_hash', 64)->default('')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('public_fact_page', function (Blueprint $table): void {
            $table->foreignId('public_page_id')->constrained('public_pages')->cascadeOnDelete();
            $table->foreignId('public_fact_id')->constrained('public_facts')->cascadeOnDelete();
            $table->primary(['public_page_id', 'public_fact_id']);
        });

        Schema::create('content_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('public_page_id')->constrained('public_pages')->cascadeOnDelete();
            $table->char('content_hash', 64)->index();
            $table->string('gate', 24);
            $table->string('decision', 20)->default('approved');
            $table->foreignId('reviewer_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('reviewer_name', 120)->default('');
            $table->text('note')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['public_page_id', 'content_hash', 'gate'], 'content_approvals_page_hash_gate_unique');
        });

        Schema::create('publication_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('public_page_id')->constrained('public_pages')->cascadeOnDelete();
            $table->char('content_hash', 64)->index();
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->boolean('is_active')->default(false)->index();
            $table->foreignId('published_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['public_page_id', 'is_active']);
        });

        Schema::table('lead_submissions', function (Blueprint $table): void {
            $table->string('reference_code', 40)->nullable()->unique()->after('id');
            $table->json('attribution')->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('lead_submissions', function (Blueprint $table): void {
            $table->dropUnique(['reference_code']);
            $table->dropColumn(['reference_code', 'attribution']);
        });

        Schema::dropIfExists('publication_snapshots');
        Schema::dropIfExists('content_approvals');
        Schema::dropIfExists('public_fact_page');
        Schema::dropIfExists('public_pages');
        Schema::dropIfExists('public_facts');
    }
};
