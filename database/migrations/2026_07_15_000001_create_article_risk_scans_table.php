<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_risk_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->unsignedInteger('match_count')->default(0);
            $table->json('matches');
            $table->char('content_hash', 64);
            $table->char('dictionary_hash', 64);
            $table->string('trigger', 30);
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['article_id', 'scanned_at']);
            $table->index(['status', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_risk_scans');
    }
};
