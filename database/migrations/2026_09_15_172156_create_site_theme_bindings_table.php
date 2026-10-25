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
        Schema::create('site_theme_bindings', function (Blueprint $table) {
            $table->string('site_key', 100)->primary();
            $table->uuid('revision_id')->nullable();
            $table->string('theme_id', 80);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->json('settings');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_theme_bindings');
    }
};
