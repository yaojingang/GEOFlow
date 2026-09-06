<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_visibility_competitors')) {
            return;
        }

        Schema::create('ai_visibility_competitors', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->json('aliases')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_competitors');
    }
};
