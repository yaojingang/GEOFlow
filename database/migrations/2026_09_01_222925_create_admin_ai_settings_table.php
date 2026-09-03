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
        Schema::create('admin_ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')
                ->unique()
                ->constrained('admins')
                ->cascadeOnDelete();
            $table->foreignId('default_chat_model_id')
                ->nullable()
                ->constrained('ai_models')
                ->nullOnDelete();
            $table->foreignId('default_embedding_model_id')
                ->nullable()
                ->constrained('ai_models')
                ->nullOnDelete();
            $table->foreignId('updated_by_admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['default_chat_model_id', 'admin_id'], 'admin_ai_settings_chat_admin_index');
            $table->index(['default_embedding_model_id', 'admin_id'], 'admin_ai_settings_embedding_admin_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_ai_settings');
    }
};
