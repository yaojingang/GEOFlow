<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_visibility_competitors', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_visibility_competitors', 'source')) {
                $table->string('source', 20)->default('manual')->after('is_active');
            }
        });

        if (! Schema::hasTable('ai_visibility_competitor_detections')) {
            Schema::create('ai_visibility_competitor_detections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id')->unique();
                $table->json('names_json');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_competitor_detections');

        if (Schema::hasColumn('ai_visibility_competitors', 'source')) {
            Schema::table('ai_visibility_competitors', function (Blueprint $table): void {
                $table->dropColumn('source');
            });
        }
    }
};
