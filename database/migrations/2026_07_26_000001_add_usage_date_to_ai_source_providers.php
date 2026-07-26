<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_source_providers')) {
            return;
        }

        if (! Schema::hasColumn('ai_source_providers', 'usage_date')) {
            Schema::table('ai_source_providers', function (Blueprint $table): void {
                $table->date('usage_date')->nullable()->after('used_today');
            });
        }

        DB::table('ai_source_providers')
            ->where('used_today', '>', 0)
            ->whereNull('usage_date')
            ->update(['usage_date' => now()->toDateString()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_source_providers') || ! Schema::hasColumn('ai_source_providers', 'usage_date')) {
            return;
        }

        Schema::table('ai_source_providers', function (Blueprint $table): void {
            $table->dropColumn('usage_date');
        });
    }
};
