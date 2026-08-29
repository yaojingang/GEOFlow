<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('article_ai_quality_checks')
            || ! Schema::hasColumn('article_ai_quality_checks', 'active_dedupe_key')) {
            return;
        }

        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->char('active_dedupe_key', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('article_ai_quality_checks')
            || ! Schema::hasColumn('article_ai_quality_checks', 'active_dedupe_key')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::table('article_ai_quality_checks')
                ->whereNotNull('active_dedupe_key')
                ->whereRaw('CHAR_LENGTH(active_dedupe_key) > 40')
                ->update(['active_dedupe_key' => null]);
        } else {
            DB::table('article_ai_quality_checks')
                ->whereNotNull('active_dedupe_key')
                ->update(['active_dedupe_key' => null]);
        }

        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->string('active_dedupe_key', 40)->nullable()->change();
        });
    }
};
