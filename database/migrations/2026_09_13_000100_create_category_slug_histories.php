<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_slug_histories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedBigInteger('original_category_id');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at');
            $table->timestamp('last_used_at')->nullable();
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_slug_histories');
    }
};
