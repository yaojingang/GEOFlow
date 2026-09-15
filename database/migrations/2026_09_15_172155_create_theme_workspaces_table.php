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
        Schema::create('theme_workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('instance_id');
            $table->unsignedBigInteger('admin_id');
            $table->string('site_key', 100);
            $table->string('theme_id', 80);
            $table->string('source', 32);
            $table->uuid('revision_id')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->string('state', 32)->default('draft');
            $table->unsignedBigInteger('code_token_id')->nullable();
            $table->timestamp('code_authorized_until')->nullable();
            $table->json('plan')->nullable();
            $table->timestamps();
            $table->index(['admin_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theme_workspaces');
    }
};
