<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_publication_personas', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->text('bio')->nullable();
            $table->string('tone', 120)->nullable();
            $table->string('domain', 255)->nullable();
            $table->text('disclosure_text')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_publication_personas');
    }
};
