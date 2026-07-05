<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_form_id')->nullable()->constrained('lead_forms')->nullOnDelete();
            $table->string('status', 20)->default('new')->index()->comment('new/contacted/qualified/invalid/converted');
            $table->json('payload')->nullable()->comment('提交字段 JSON');
            $table->string('source_url', 500)->nullable()->index()->comment('来源页面');
            $table->string('ip_address', 45)->default('')->comment('提交 IP');
            $table->text('user_agent')->nullable()->comment('User-Agent');
            $table->text('note')->nullable()->comment('后台备注');
            $table->foreignId('handled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('handled_at')->nullable()->comment('最近处理时间');
            $table->timestamps();

            $table->index(['lead_form_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_submissions');
    }
};
