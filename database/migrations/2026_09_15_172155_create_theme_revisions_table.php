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
        Schema::create('theme_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('parent_id')->nullable();
            $table->string('theme_id', 80);
            $table->string('state', 32)->default('preparing');
            $table->json('files');
            $table->json('settings');
            $table->json('dependencies');
            $table->char('content_sha256', 64);
            $table->unsignedBigInteger('total_bytes');
            $table->timestamps();
            $table->index(['workspace_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theme_revisions');
    }
};
