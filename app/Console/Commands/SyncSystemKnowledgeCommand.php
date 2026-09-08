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
        {--media : Import the bundled, hash-verified knowledge screenshots}
        {--verify : Read only and verify the synchronized official version or preserved customization}
        {--json : Emit a structured synchronization report}';

    protected $description = 'Create or safely update GEOFlow system knowledge without overwriting customized content';

    public function handle(SystemKnowledgeBaseManager $manager, SystemKnowledgeMediaManager $media): int
    {
        if ($this->option('verify')) {
            $key = trim((string) $this->option('key'));
            $binding = $manager->binding($key);
            $definition = $manager->definition($key);
            $complete = $binding !== null && $binding->knowledgeBase !== null
                && $binding->official_version === $definition['official_version']
                && hash_equals((string) $binding->official_content_hash, $definition['content_hash'])
                && ($binding->customized_at !== null || hash_equals($definition['content_hash'], hash('sha256', (string) $binding->knowledgeBase->content)));
            $report = ['schema_version' => 1, 'status' => $complete ? 'pass' : 'fail', 'key' => $key];
            $this->line($this->option('json') ? json_encode($report, JSON_THROW_ON_ERROR) : 'System knowledge: '.$report['status']);

            return $complete ? self::SUCCESS : self::FAILURE;
        }
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
