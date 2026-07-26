<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('articles') || Schema::hasColumn('articles', 'source_title_id')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->foreignId('source_title_id')
                ->nullable()
                ->after('task_id')
                ->constrained('titles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('articles') || ! Schema::hasColumn('articles', 'source_title_id')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_title_id');
        });
    }
};
