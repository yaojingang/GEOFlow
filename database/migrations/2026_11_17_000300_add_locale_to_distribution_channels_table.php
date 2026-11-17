<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 分发渠道内容语言：中文原文按渠道语言翻译后投递。
     */
    public function up(): void
    {
        if (Schema::hasColumn('distribution_channels', 'locale')) {
            return;
        }

        Schema::table('distribution_channels', function (Blueprint $table): void {
            $table->string('locale', 10)->default('zh')->after('channel_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('distribution_channels', 'locale')) {
            return;
        }

        Schema::table('distribution_channels', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
