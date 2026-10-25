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
        Schema::create('theme_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('site_key', 100);
            $table->uuid('workspace_id');
            $table->uuid('revision_id');
            $table->uuid('previous_revision_id')->nullable();
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('binding_version');
            $table->json('changes');
            $table->char('plan_sha256', 64);
            $table->string('kind', 32)->default('publish');
            $table->timestamps();
            $table->index(['site_key', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theme_releases');
    }
};
