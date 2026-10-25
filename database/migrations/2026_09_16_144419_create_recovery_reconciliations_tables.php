<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_reconciliations', function (Blueprint $table): void {
            $table->string('epoch', 32)->primary();
            $table->string('proof_sha256', 64);
            $table->json('report');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('recovery_reconciliation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('decision_id')->unique();
            $table->string('epoch', 32)->index();
            $table->unsignedBigInteger('quarantine_id');
            $table->string('source_table', 80);
            $table->string('source_id', 128);
            $table->string('source_sha256', 64);
            $table->string('disposition', 30);
            $table->string('request_sha256', 64);
            $table->json('report');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_reconciliation_decisions');
        Schema::dropIfExists('recovery_reconciliations');
    }
};
