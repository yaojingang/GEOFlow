<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('title_generation_runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('manual_retry_count')->default(0)->after('batch_attempt_count');
        });

        Schema::create('title_generation_run_keywords', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('title_generation_run_id')->constrained('title_generation_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('source_keyword_id');
            $table->string('keyword', 200);

            $table->unique(
                ['title_generation_run_id', 'source_keyword_id'],
                'title_generation_run_keywords_source_unique',
            );
            $table->index(
                ['title_generation_run_id', 'id'],
                'title_generation_run_keywords_cursor_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_generation_run_keywords');
        Schema::table('title_generation_runs', function (Blueprint $table): void {
            $table->dropColumn('manual_retry_count');
        });
    }
};
