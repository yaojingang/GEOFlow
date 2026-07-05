<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_forms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->comment('表单名称');
            $table->string('slug', 120)->unique()->comment('前台访问标识');
            $table->string('status', 20)->default('active')->index()->comment('active/inactive');
            $table->text('description')->nullable()->comment('表单说明');
            $table->string('submit_button_label', 80)->default('提交')->comment('提交按钮文案');
            $table->text('success_message')->nullable()->comment('提交成功文案');
            $table->json('fields')->nullable()->comment('字段配置 JSON');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_forms');
    }
};
