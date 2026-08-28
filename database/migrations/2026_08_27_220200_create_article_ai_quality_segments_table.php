<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('article_ai_quality_segments')) {
            return;
        }

        Schema::create('article_ai_quality_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_ai_quality_check_id')
                ->constrained('article_ai_quality_checks')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('segment_index');
            $table->unsignedInteger('start_offset');
            $table->unsignedInteger('end_offset');
            $table->char('input_hash', 64);
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->longText('model_result')->nullable();
            $table->longText('validated_result')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['article_ai_quality_check_id', 'segment_index'],
                'article_ai_quality_segment_unique',
            );
            $table->index(['status', 'updated_at'], 'article_ai_quality_segment_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_ai_quality_segments');
    }
};
