<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const THEME_ID = 'toutiao-news-20260426';

    public function up(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }

        $now = now();
        $existing = DB::table('site_settings')->where('setting_key', 'active_theme')->first();

        if ($existing === null) {
            DB::table('site_settings')->insert([
                'setting_key' => 'active_theme',
                'setting_value' => self::THEME_ID,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        if (trim((string) ($existing->setting_value ?? '')) === '') {
            DB::table('site_settings')
                ->where('setting_key', 'active_theme')
                ->update([
                    'setting_value' => self::THEME_ID,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }

        DB::table('site_settings')
            ->where('setting_key', 'active_theme')
            ->where('setting_value', self::THEME_ID)
            ->update([
                'setting_value' => '',
                'updated_at' => now(),
            ]);
    }
};
