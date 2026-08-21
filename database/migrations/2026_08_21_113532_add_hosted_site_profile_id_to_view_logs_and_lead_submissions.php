<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('view_logs', function (Blueprint $table): void {
            $table->foreignId('hosted_site_profile_id')
                ->nullable()
                ->after('article_id')
                ->constrained('hosted_site_profiles')
                ->nullOnDelete();
        });

        Schema::table('lead_submissions', function (Blueprint $table): void {
            $table->foreignId('hosted_site_profile_id')
                ->nullable()
                ->after('lead_form_id')
                ->constrained('hosted_site_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lead_submissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hosted_site_profile_id');
        });

        Schema::table('view_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hosted_site_profile_id');
        });
    }
};
