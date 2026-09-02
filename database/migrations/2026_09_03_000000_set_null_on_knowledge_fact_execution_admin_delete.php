<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_fact_generation_runs', function (Blueprint $table): void {
            $table->dropForeign(['model_access_admin_id']);
            $table->foreign('model_access_admin_id')
                ->references('id')
                ->on('admins')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_fact_generation_runs', function (Blueprint $table): void {
            $table->dropForeign(['model_access_admin_id']);
            $table->foreign('model_access_admin_id')
                ->references('id')
                ->on('admins')
                ->restrictOnDelete();
        });
    }
};
