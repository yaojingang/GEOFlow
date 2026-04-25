<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为 sqlite 等非 pgsql 测试/开发环境补齐 article_images 表。
     */
    public function up(): void
    {
        if (
            DB::getDriverName() === 'pgsql'
            || Schema::hasTable('article_images')
            || ! Schema::hasTable('articles')
            || ! Schema::hasTable('images')
        ) {
            return;
        }

        Schema::create('article_images', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('image_id');
            $table->integer('position')->default(0);
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->foreign('article_id')->references('id')->on('articles')->onDelete('cascade');
            $table->foreign('image_id')->references('id')->on('images');
        });
    }

    /**
     * 回滚非 pgsql 环境下补齐的 article_images 表。
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            return;
        }

        Schema::dropIfExists('article_images');
    }
};
