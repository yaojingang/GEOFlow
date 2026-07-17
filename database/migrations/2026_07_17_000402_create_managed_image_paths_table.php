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
        Schema::create('managed_image_paths', function (Blueprint $table) {
            $table->id();
            $table->char('path_hash', 64)->unique();
            $table->text('file_path');
            $table->char('content_sha256', 64)->nullable()->index();
            $table->string('state', 20)->default('unknown')->index();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('managed_image_paths');
    }
};
