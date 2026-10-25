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
        Schema::create('management_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('instance_id');
            $table->unsignedBigInteger('admin_id');
            $table->string('client_request_id', 128);
            $table->string('operation', 100);
            $table->char('request_hash', 64);
            $table->json('required_scopes');
            $table->string('state', 32);
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('task_run_id')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['instance_id', 'admin_id', 'client_request_id'], 'management_operations_actor_request_unique');
            $table->index(['admin_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('management_operations');
    }
};
