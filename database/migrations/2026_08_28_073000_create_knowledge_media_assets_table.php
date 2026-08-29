<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_media_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->restrictOnDelete();
            $table->string('asset_key', 120);
            $table->unsignedInteger('asset_version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('knowledge_media_assets')->nullOnDelete();
            $table->string('section_key', 160);
            $table->string('route_name', 180);
            $table->string('title', 180);
            $table->string('alt_text', 500);
            $table->text('caption')->nullable();
            $table->json('keywords_json')->nullable();
            $table->string('storage_path', 500);
            $table->string('thumbnail_path', 500)->nullable();
            $table->string('mime_type', 50);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('content_hash', 64);
            $table->string('locale', 16)->default('zh_CN');
            $table->string('official_version', 40)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->string('captured_app_version', 80)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['knowledge_base_id', 'asset_key', 'locale', 'asset_version'],
                'knowledge_media_asset_version_unique',
            );
            $table->index(['knowledge_base_id', 'locale', 'is_active'], 'knowledge_media_active_index');
            $table->index(['knowledge_base_id', 'section_key', 'is_active'], 'knowledge_media_section_index');
            $table->index('content_hash', 'knowledge_media_content_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_media_assets');
    }
};
