<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_models') || Schema::hasColumn('ai_models', 'usage_date')) {
            return;
        }

        Schema::table('ai_models', function (Blueprint $table): void {
            $table->date('usage_date')->nullable()->after('used_today');
        });

        DB::table('ai_models')
            ->where('used_today', '>', 0)
            ->update(['usage_date' => now()->toDateString()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_models') || ! Schema::hasColumn('ai_models', 'usage_date')) {
            return;
        }

        Schema::table('ai_models', function (Blueprint $table): void {
            $table->dropColumn('usage_date');
        });
    }
};
