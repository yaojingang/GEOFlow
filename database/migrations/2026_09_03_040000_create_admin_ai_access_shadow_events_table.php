<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ai_access_shadow_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->unsignedBigInteger('execution_admin_id');
            $table->string('execution_admin_role', 32);
            $table->unsignedBigInteger('ai_config_access_version');
            $table->string('capability', 32);
            $table->unsignedBigInteger('legacy_preferred_model_id')->nullable();
            $table->unsignedBigInteger('safe_preferred_model_id')->nullable();
            $table->string('safe_model_source', 16)->nullable();
            $table->string('comparison', 24);
            $table->unsignedInteger('inaccessible_legacy_model_count')->default(0);
            $table->unsignedInteger('missing_owner_model_count')->default(0);
            $table->timestamp('created_at');

            $table->index(['comparison', 'created_at'], 'admin_ai_shadow_comparison_created_index');
            $table->index(['execution_admin_id', 'created_at'], 'admin_ai_shadow_admin_created_index');
            $table->index(['safe_model_source', 'created_at'], 'admin_ai_shadow_source_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ai_access_shadow_events');
    }
};
