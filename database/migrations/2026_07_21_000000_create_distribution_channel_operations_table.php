<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_channel_operations')) {
            return;
        }

        Schema::create('distribution_channel_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribution_channel_id')
                ->constrained('distribution_channels')
                ->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->string('operation', 80)->index();
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(
                ['distribution_channel_id', 'expires_at'],
                'distribution_channel_operations_channel_expiry_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_channel_operations');
    }
};
