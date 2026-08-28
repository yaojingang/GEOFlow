<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneDeletedTasksCommand extends Command
{
    protected $signature = 'geoflow:prune-task-trash';

    protected $description = 'Permanently delete tasks after the trash retention period';

    public function handle(): int
    {
        $cutoff = now()->subDays(Task::TRASH_RETENTION_DAYS)->format('Y-m-d H:i:s.u');
        $pruned = 0;

        DB::table('task_trash_entries')
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($entries) use (&$pruned, $cutoff): void {
                foreach ($entries as $entry) {
                    DB::transaction(function () use ($entry, $cutoff, &$pruned): void {
                        $task = Task::onlyTrashed()
                            ->whereKey((int) $entry->task_id)
                            ->lockForUpdate()
                            ->first();
                        if (! $task) {
                            return;
                        }

                        $expiredEntry = DB::table('task_trash_entries')
                            ->where('task_id', (int) $task->id)
                            ->where('deleted_at', '<=', $cutoff)
                            ->lockForUpdate()
                            ->first(['task_id']);
                        if (! $expiredEntry) {
                            return;
                        }

                        $task->forceDelete();
                        $pruned++;
                    });
                }
            });

        $this->info(sprintf('Permanently deleted %d expired tasks.', $pruned));

        return self::SUCCESS;
    }
}
