<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_profiles') || Schema::hasColumn('brand_profiles', 'extended_profile')) {
            return;
        }

        Schema::table('brand_profiles', function (Blueprint $table): void {
            $table->json('extended_profile')->nullable()->after('extra_facts');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('brand_profiles') || ! Schema::hasColumn('brand_profiles', 'extended_profile')) {
            return;
        }

        Schema::table('brand_profiles', function (Blueprint $table): void {
            $table->dropColumn('extended_profile');
        });
    }
};
