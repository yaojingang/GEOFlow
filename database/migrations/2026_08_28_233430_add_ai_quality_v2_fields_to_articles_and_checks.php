<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->longText('generation_evidence_snapshot')->nullable()->after('ai_quality_policy_snapshot');
        });

        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->boolean('gate_applied')->default(true)->after('algorithm_version');
            $table->string('evaluation_mode', 20)->default('primary')->after('gate_applied');
            $table->foreignId('baseline_check_id')
                ->nullable()
                ->after('evaluation_mode')
                ->constrained('article_ai_quality_checks')
                ->nullOnDelete();
            $table->string('scoring_version', 20)->default('v1')->after('baseline_check_id');
            $table->decimal('confidence', 5, 4)->nullable()->after('scoring_version');
            $table->json('gate_reasons')->nullable()->after('confidence');
            $table->unsignedSmallInteger('truncated_issue_count')->default(0)->after('gate_reasons');
            $table->index(
                ['article_id', 'gate_applied', 'evaluation_mode', 'id'],
                'article_ai_quality_gate_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->dropIndex('article_ai_quality_gate_lookup_idx');
            $table->dropConstrainedForeignId('baseline_check_id');
            $table->dropColumn([
                'gate_applied',
                'evaluation_mode',
                'scoring_version',
                'confidence',
                'gate_reasons',
                'truncated_issue_count',
            ]);
        });

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('generation_evidence_snapshot');
        });
    }
};
