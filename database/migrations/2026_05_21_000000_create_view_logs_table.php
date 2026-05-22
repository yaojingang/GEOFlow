<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('view_logs')) {
            return;
        }

        Schema::create('view_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->string('source', 32)->default('local')->index();
            $table->string('method', 16)->default('GET');
            $table->string('path', 2048)->default('');
            $table->string('route_name', 128)->nullable()->index();
            $table->unsignedSmallInteger('status_code')->default(200)->index();
            $table->string('ip_address', 64)->default('')->index();
            $table->text('user_agent')->nullable();
            $table->string('referer', 2048)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('view_logs');
    }
};
