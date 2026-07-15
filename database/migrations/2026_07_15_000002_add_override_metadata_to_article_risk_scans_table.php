<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_risk_scans', function (Blueprint $table): void {
            $table->boolean('is_overridden')->default(false)->index();
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('article_risk_scans', function (Blueprint $table): void {
            $table->dropIndex(['is_overridden']);
            $table->dropConstrainedForeignId('overridden_by_admin_id');
            $table->dropColumn([
                'is_overridden',
                'override_reason',
                'overridden_at',
            ]);
        });
    }
};
