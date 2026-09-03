<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_knowledge_projects', function (Blueprint $table): void {
            $table->unsignedInteger('execution_attempt')->default(0)->after('execution_lease_token');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_knowledge_projects', function (Blueprint $table): void {
            $table->dropColumn('execution_attempt');
        });
    }
};
