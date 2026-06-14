<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_knowledge_bases')) {
            Schema::create('task_knowledge_bases', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->foreignId('knowledge_base_id')->constrained('knowledge_bases');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['task_id', 'knowledge_base_id'], 'task_knowledge_bases_unique');
                $table->index(['knowledge_base_id', 'task_id'], 'task_knowledge_bases_base_task_idx');
            });
        }

        $this->backfillLegacyKnowledgeBaseLinks();
    }

    public function down(): void
    {
        Schema::dropIfExists('task_knowledge_bases');
    }

    private function backfillLegacyKnowledgeBaseLinks(): void
    {
        if (
            ! Schema::hasTable('tasks')
            || ! Schema::hasTable('knowledge_bases')
            || ! Schema::hasTable('task_knowledge_bases')
            || ! Schema::hasColumn('tasks', 'knowledge_base_id')
        ) {
            return;
        }

        $now = now();

        DB::table('tasks')
            ->whereNotNull('knowledge_base_id')
            ->orderBy('id')
            ->select(['id', 'knowledge_base_id'])
            ->chunkById(200, function ($tasks) use ($now): void {
                foreach ($tasks as $task) {
                    $taskId = (int) $task->id;
                    $knowledgeBaseId = (int) $task->knowledge_base_id;

                    if ($taskId <= 0 || $knowledgeBaseId <= 0) {
                        continue;
                    }

                    if (! DB::table('knowledge_bases')->where('id', $knowledgeBaseId)->exists()) {
                        continue;
                    }

                    DB::table('task_knowledge_bases')->updateOrInsert(
                        [
                            'task_id' => $taskId,
                            'knowledge_base_id' => $knowledgeBaseId,
                        ],
                        [
                            'sort_order' => 0,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            });
    }
};
