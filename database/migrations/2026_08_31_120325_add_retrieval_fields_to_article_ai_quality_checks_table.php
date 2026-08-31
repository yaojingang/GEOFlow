<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->string('requested_retrieval_mode', 32)->nullable()->after('inspection_scope');
            $table->string('effective_retrieval_mode', 32)->nullable()->after('requested_retrieval_mode');
            $table->string('retrieval_strategy_version', 40)->nullable()->after('effective_retrieval_mode');
            $table->string('retrieval_failure_code', 80)->nullable()->after('retrieval_strategy_version');
            $table->char('retrieval_basis_hash', 64)->nullable()->after('retrieval_failure_code');
            $table->index(
                ['effective_retrieval_mode', 'created_at'],
                'article_ai_quality_retrieval_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->dropIndex('article_ai_quality_retrieval_created_idx');
            $table->dropColumn([
                'requested_retrieval_mode',
                'effective_retrieval_mode',
                'retrieval_strategy_version',
                'retrieval_failure_code',
                'retrieval_basis_hash',
            ]);
        });
    }
};
