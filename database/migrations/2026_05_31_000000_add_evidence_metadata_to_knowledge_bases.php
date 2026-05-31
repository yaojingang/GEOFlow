<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_bases')) {
            return;
        }

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_bases', 'source_name')) {
                $table->string('source_name', 150)->nullable();
            }
            if (! Schema::hasColumn('knowledge_bases', 'source_url')) {
                $table->string('source_url', 500)->nullable();
            }
            if (! Schema::hasColumn('knowledge_bases', 'source_type')) {
                $table->string('source_type', 50)->default('document');
            }
            if (! Schema::hasColumn('knowledge_bases', 'business_line')) {
                $table->string('business_line', 100)->nullable();
            }
            if (! Schema::hasColumn('knowledge_bases', 'effective_date')) {
                $table->date('effective_date')->nullable();
            }
            if (! Schema::hasColumn('knowledge_bases', 'risk_level')) {
                $table->string('risk_level', 20)->default('medium');
            }
            if (! Schema::hasColumn('knowledge_bases', 'review_status')) {
                $table->string('review_status', 20)->default('unreviewed');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_bases')) {
            return;
        }

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            foreach (['review_status', 'risk_level', 'effective_date', 'business_line', 'source_type', 'source_url', 'source_name'] as $column) {
                if (Schema::hasColumn('knowledge_bases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
