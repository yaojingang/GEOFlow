<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_preparations', function (Blueprint $table): void {
            $table->string('epoch', 32)->primary();
            $table->string('transaction_id', 128);
            $table->string('admin_digest_before', 64);
            $table->string('admin_digest_after', 64);
            $table->json('report');
            $table->timestamp('created_at');
        });
        Schema::create('recovery_quarantines', function (Blueprint $table): void {
            $table->id();
            $table->string('epoch', 32)->index();
            $table->string('source_table', 80);
            $table->string('source_id', 128);
            $table->string('status', 30)->default('held');
            $table->json('summary');
            $table->string('source_sha256', 64);
            $table->unique(['epoch', 'source_table', 'source_id'], 'recovery_quarantine_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_quarantines');
        Schema::dropIfExists('recovery_preparations');
    }
};
