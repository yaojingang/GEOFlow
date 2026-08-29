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
            || ! Schema::hasColumn('article_ai_quality_checks', 'algorithm_version')) {
            return;
        }

        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->string('algorithm_version', 100)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('article_ai_quality_checks')
            || ! Schema::hasColumn('article_ai_quality_checks', 'algorithm_version')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::table('article_ai_quality_checks')
                ->whereRaw('CHAR_LENGTH(algorithm_version) > 40')
                ->update(['algorithm_version' => DB::raw('LEFT(algorithm_version, 40)')]);
        } else {
            DB::table('article_ai_quality_checks')
                ->whereRaw('LENGTH(algorithm_version) > 40')
                ->update(['algorithm_version' => DB::raw('SUBSTR(algorithm_version, 1, 40)')]);
        }

        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->string('algorithm_version', 40)->change();
        });
    }
};
