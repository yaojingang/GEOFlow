<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_seller_profiles')) {
            return;
        }

        Schema::create('crm_seller_profiles', static function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('name', 160);
            $table->json('payload');
            $table->boolean('is_default')->default(false)->index();
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_seller_profiles');
    }
};
