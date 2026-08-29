<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_publication_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manual_publication_id')->constrained('manual_publications')->cascadeOnDelete();
            $table->foreignId('changed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('completion_url', 1000)->nullable();
            $table->text('result_note')->nullable();
            $table->timestamp('created_at');

            $table->index(['manual_publication_id', 'created_at'], 'manual_publication_transition_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_publication_transitions');
    }
};
