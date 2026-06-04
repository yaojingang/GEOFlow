<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_models')) {
            return;
        }

        Schema::table('ai_models', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_models', 'max_tokens')) {
                // 单个模型的最大输出 token 数；留空表示沿用 config('geoflow.content_max_tokens') 兜底值。
                $table->unsignedInteger('max_tokens')->nullable()->after('daily_limit');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_models')) {
            return;
        }

        Schema::table('ai_models', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_models', 'max_tokens')) {
                $table->dropColumn('max_tokens');
            }
        });
    }
};
