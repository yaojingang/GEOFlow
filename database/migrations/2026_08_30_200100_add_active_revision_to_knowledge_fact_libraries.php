<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_fact_libraries', function (Blueprint $table): void {
            $table->foreignId('active_revision_id')->nullable()->after('working_version')
                ->constrained('knowledge_fact_library_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_fact_libraries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_revision_id');
        });
    }
};
