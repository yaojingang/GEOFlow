<?php

use App\Models\AiConversation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new AiConversation)->getTable(), function (Blueprint $table): void {
            $table->json('task_draft')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table((new AiConversation)->getTable(), function (Blueprint $table): void {
            $table->dropColumn('task_draft');
        });
    }
};
