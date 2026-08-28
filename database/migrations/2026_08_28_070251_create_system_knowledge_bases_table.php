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
        Schema::create('system_knowledge_bases', function (Blueprint $table): void {
            $table->string('system_key', 100)->primary();
            $table->foreignId('knowledge_base_id')
                ->unique()
                ->constrained('knowledge_bases')
                ->restrictOnDelete();
            $table->string('official_version', 50);
            $table->char('official_content_hash', 64);
            $table->timestamp('customized_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['official_version', 'customized_at'], 'system_knowledge_version_customized_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_knowledge_bases');
    }
};
