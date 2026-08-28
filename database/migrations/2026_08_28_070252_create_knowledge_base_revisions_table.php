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
        Schema::create('knowledge_base_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->longText('content');
            $table->char('content_hash', 64);
            $table->string('source', 20);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('restored_from_revision_id')->nullable()->constrained('knowledge_base_revisions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['knowledge_base_id', 'revision_number'], 'knowledge_base_revision_number_unique');
            $table->index(['knowledge_base_id', 'created_at'], 'knowledge_base_revision_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_revisions');
    }
};
