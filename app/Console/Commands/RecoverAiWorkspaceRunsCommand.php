<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class RecoverAiWorkspaceRunsCommand extends Command
{
    protected $signature = 'geoflow:recover-ai-workspace {--limit=50}';

    protected $description = 'Legacy AI workspace workflow recovery is disabled';

    public function handle(): int
    {
        $this->components->info('旧版 AI 工作流已停用，无需恢复 Run 或 Step。');

        return self::SUCCESS;
    }
}
