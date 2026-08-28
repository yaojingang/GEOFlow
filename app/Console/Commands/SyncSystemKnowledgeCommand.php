<?php

namespace App\Console\Commands;

use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use App\Services\AiWorkspace\SystemKnowledgeMediaManager;
use Illuminate\Console\Command;
use Throwable;

final class SyncSystemKnowledgeCommand extends Command
{
    protected $signature = 'geoflow:sync-system-knowledge
        {--key=ai_workspace_manual : Stable system knowledge key}
        {--media : Import the bundled, hash-verified knowledge screenshots}';

    protected $description = 'Create or safely update GEOFlow system knowledge without overwriting customized content';

    public function handle(SystemKnowledgeBaseManager $manager, SystemKnowledgeMediaManager $media): int
    {
        try {
            $result = $manager->sync(trim((string) $this->option('key')));
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $state = $result['created']
            ? 'created'
            : ($result['updated'] ? 'updated' : ($result['customized'] ? 'customized-preserved' : 'current'));
        $this->components->info(sprintf(
            'System knowledge [%s] is %s; index request: %s.',
            (string) $result['binding']->system_key,
            $state,
            $result['index_requested'] ? 'queued' : 'unchanged',
        ));

        if ((bool) $this->option('media')) {
            try {
                $mediaResult = $media->syncBundled();
                $this->components->info(sprintf(
                    'Knowledge media synchronized: %d imported, %d updated, %d unchanged.',
                    $mediaResult['imported'],
                    $mediaResult['updated'],
                    $mediaResult['unchanged'],
                ));
            } catch (Throwable $exception) {
                report($exception);
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
