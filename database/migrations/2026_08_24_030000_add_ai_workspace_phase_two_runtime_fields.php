<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            $table->string('resolution_source', 30)->nullable();
            $table->timestamp('resolution_started_at', 6)->nullable();
            $table->timestamp('resolution_finished_at', 6)->nullable();
            $table->timestamp('queued_at', 6)->nullable();
            $table->timestamp('first_token_at', 6)->nullable();
            $table->unsignedBigInteger('answer_chunk_sequence')->default(0);
            $table->boolean('answer_is_partial')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'resolution_source',
                'resolution_started_at',
                'resolution_finished_at',
                'queued_at',
                'first_token_at',
                'answer_chunk_sequence',
                'answer_is_partial',
            ]);
        });
    }
};
