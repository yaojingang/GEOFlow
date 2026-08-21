<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_publication_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('persona_id')->constrained('manual_publication_personas')->restrictOnDelete();
            $table->string('platform', 60)->index();
            $table->string('custom_platform', 120)->nullable();
            $table->string('account_name', 160);
            $table->string('profile_url', 1000)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['persona_id', 'is_active']);
            $table->index(['platform', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_publication_accounts');
    }
};
