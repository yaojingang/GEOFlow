<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_publications', function (Blueprint $table): void {
            $table->json('publication_payload')->nullable()->after('source_snapshot');
            $table->json('execution_receipt')->nullable()->after('result_note');
            $table->foreignId('browser_claimed_by_token_id')
                ->nullable()
                ->after('execution_receipt')
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
            $table->timestamp('browser_claimed_at')->nullable()->after('browser_claimed_by_token_id');
            $table->timestamp('browser_last_seen_at')->nullable()->after('browser_claimed_at');

            $table->index(
                ['assigned_admin_id', 'status', 'browser_claimed_by_token_id'],
                'manual_publications_browser_queue',
            );
        });
    }

    public function down(): void
    {
        Schema::table('manual_publications', function (Blueprint $table): void {
            $table->dropIndex('manual_publications_browser_queue');
            $table->dropConstrainedForeignId('browser_claimed_by_token_id');
            $table->dropColumn([
                'publication_payload',
                'execution_receipt',
                'browser_claimed_at',
                'browser_last_seen_at',
            ]);
        });
    }
};
