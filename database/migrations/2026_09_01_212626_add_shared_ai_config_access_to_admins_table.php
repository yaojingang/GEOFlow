<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->foreignId('shared_ai_config_owner_id')
                ->nullable()
                ->index()
                ->constrained('admins')
                ->restrictOnDelete();
            $table->unsignedBigInteger('ai_config_access_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->dropIndex(['shared_ai_config_owner_id']);
            $table->dropConstrainedForeignId('shared_ai_config_owner_id');
            $table->dropColumn('ai_config_access_version');
        });
    }
};
