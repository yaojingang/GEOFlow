<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->foreignId('owner_admin_id')
                ->nullable()
                ->constrained('admins')
                ->restrictOnDelete();
            $table->enum('access_scope', ['user_content', 'system_only'])
                ->default('user_content');
            $table->timestamp('archived_at')->nullable();
            $table->index(
                ['owner_admin_id', 'access_scope', 'status', 'model_type', 'failover_priority', 'id'],
                'ai_models_owner_access_candidates_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->dropIndex('ai_models_owner_access_candidates_index');
            $table->dropConstrainedForeignId('owner_admin_id');
            $table->dropColumn(['access_scope', 'archived_at']);
        });
    }
};
