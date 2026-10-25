<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('url_change_scope_states', function (Blueprint $table): void {
            $table->string('scope_key', 100)->primary();
            $table->unsignedBigInteger('data_revision')->default(0);
            $table->timestamp('url_changed_at')->nullable();
            $table->uuid('active_request_id')->nullable();
        });
        Schema::create('url_change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('admin_id')->constrained('admins');
            $table->unsignedInteger('auth_version');
            $table->string('operation', 32);
            $table->string('locale', 10)->default('zh_CN');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('old_value', 255);
            $table->string('new_value', 255);
            $table->string('status', 24)->index();
            $table->json('versions');
            $table->json('sites');
            $table->json('progress');
            $table->json('summary');
            $table->string('nonce_hash', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamp('reports_purged_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('url_change_requests');
        Schema::dropIfExists('url_change_scope_states');
    }
};
