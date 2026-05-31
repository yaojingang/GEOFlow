<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\BrandProfile;
use App\Models\GeoAiPlatform;
use App\Models\GeoAiSearchRun;
use App\Models\GeoArticleDraft;
use App\Models\GeoCitationSource;
use App\Models\GeoPublishRecord;
use App\Models\GeoTask;
use App\Models\GeoWritingTask;
use App\Models\Organization;
use App\Services\Geo\GeoTopicToPublishPackagePipeline;
use App\Services\Geo\GeoWebWorkbenchClient;
use App\Services\Geo\GeoYixiaoerDistributionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class GeoFlowCliCommand extends Command
{
    protected $signature = 'geoamplify:cli
        {action=status : status|diagnosis|topic-pipeline|submit-wxmp-draft|web-workbench-status|schema}
        {--json= : JSON payload string}
        {--file= : JSON payload file path}
        {--admin= : Admin username or ID}
        {--platform=* : Platform code override, repeatable}
        {--dry-run : Create records without running/submitting when supported}
        {--pretty : Pretty-print JSON output}';

    protected $description = 'Unified machine-readable CLI for external GEOAmplify callers.';

    protected $aliases = ['geoflow:cli'];

    public function handle(
        GeoTopicToPublishPackagePipeline $topicPipeline,
        GeoYixiaoerDistributionService $yixiaoer,
        GeoWebWorkbenchClient $webWorkbench
    ): int {
        $action = $this->normalizeAction((string) $this->argument('action'));

        try {
            $payload = $this->payload(in_array($action, ['diagnosis', 'topic-pipeline', 'submit-wxmp-draft'], true));
            $result = match ($action) {
                'status' => $this->statusAction($payload),
                'schema' => $this->schemaAction(),
                'diagnosis' => $this->diagnosisAction($payload),
                'topic-pipeline' => $this->topicPipelineAction($payload, $topicPipeline),
                'submit-wxmp-draft' => $this->submitWxmpDraftAction($payload, $yixiaoer),
                'web-workbench-status' => $this->webWorkbenchStatusAction($payload, $webWorkbench),
                default => throw new InvalidArgumentException('不支持的 action: '.$action),
            };

            $this->outputJson($result);

            return Command::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->outputJson([
                'ok' => false,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);

            return Command::INVALID;
        } catch (Throwable $exception) {
            $this->outputJson([
                'ok' => false,
                'action' => $action,
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function statusAction(array $payload): array
    {
        $admin = $this->resolveAdmin($payload, false);
        $organization = $admin instanceof Admin ? $this->resolveOrganization($admin, $payload, false) : null;

        return [
            'ok' => true,
            'action' => 'status',
            'app' => [
                'name' => 'GEOAmplify',
                'site_name' => (string) config('geoflow.site_name', config('app.name')),
                'env' => (string) config('app.env'),
                'url' => (string) config('app.url'),
            ],
            'admin' => $admin instanceof Admin ? $this->adminPayload($admin) : null,
            'organization' => $organization instanceof Organization ? $this->organizationPayload($organization) : null,
            'counts' => [
                'active_admins' => Admin::query()->where('status', 'active')->count(),
                'organizations' => Organization::query()->count(),
                'brand_profiles' => BrandProfile::query()->count(),
                'active_ai_models' => AiModel::query()->where('status', 'active')->count(),
                'active_geo_platforms' => GeoAiPlatform::query()->where('status', 'active')->count(),
                'diagnosis_tasks' => GeoTask::query()->count(),
                'search_runs' => GeoAiSearchRun::query()->count(),
                'citation_sources' => GeoCitationSource::query()->count(),
                'writing_tasks' => GeoWritingTask::query()->count(),
                'article_drafts' => GeoArticleDraft::query()->count(),
                'publish_records' => GeoPublishRecord::query()->count(),
            ],
            'actions' => $this->actions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaAction(): array
    {
        return [
            'ok' => true,
            'action' => 'schema',
            'command' => 'php artisan geoamplify:cli <action> --json=\'{...}\' --pretty',
            'actions' => $this->actions(),
            'payloads' => [
                'diagnosis' => ['brand_name', 'products', 'advantages', 'service_area', 'keywords|questions|keywords_text', 'platform_codes', 'no_run'],
                'topic-pipeline' => ['topic', 'platform_codes', 'max_references', 'organization_id?', 'brand_profile_id?'],
                'submit-wxmp-draft' => ['draft_id', 'platform_codes?'],
                'web-workbench-status' => ['limit?'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function diagnosisAction(array $payload): array
    {
        $params = [
            '--json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
        $adminRef = $this->adminRef($payload);
        if ($adminRef !== '') {
            $params['--admin'] = $adminRef;
        }
        $platforms = $this->platformCodes($payload, false);
        if ($platforms !== []) {
            $params['--platform'] = $platforms;
        }
        if ((bool) ($payload['no_run'] ?? false) || (bool) $this->option('dry-run')) {
            $params['--no-run'] = true;
        }

        $output = new BufferedOutput;
        $exitCode = Artisan::call('geoamplify:geo-run', $params, $output);
        $rawOutput = $output->fetch();
        $child = json_decode($rawOutput, true);

        return [
            'ok' => $exitCode === Command::SUCCESS && is_array($child) && (bool) ($child['ok'] ?? false),
            'action' => 'diagnosis',
            'exit_code' => $exitCode,
            'result' => is_array($child) ? $child : ['raw_output' => $rawOutput],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function topicPipelineAction(array $payload, GeoTopicToPublishPackagePipeline $pipeline): array
    {
        $topic = trim((string) ($payload['topic'] ?? $payload['question'] ?? ''));
        if ($topic === '') {
            throw new InvalidArgumentException('topic-pipeline 需要 topic');
        }

        $platformCodes = $this->platformCodes($payload, true);
        if (count($platformCodes) < 2) {
            throw new InvalidArgumentException('topic-pipeline 至少需要两个 platform_codes');
        }

        $admin = $this->resolveAdmin($payload, true);
        $organization = $this->resolveOrganization($admin, $payload, true);
        $brandProfile = $this->resolveBrandProfile($organization, $payload);
        $maxReferences = max(1, min(5, (int) ($payload['max_references'] ?? 3)));

        $draft = $pipeline->run($admin, $organization, $brandProfile, $topic, $platformCodes, $maxReferences);
        $draft->loadMissing('writingTask');
        $brief = (array) ($draft->writingTask?->brief ?? []);
        $package = (array) ($brief['publish_package'] ?? []);

        return [
            'ok' => true,
            'action' => 'topic-pipeline',
            'admin_id' => (int) $admin->id,
            'organization_id' => (int) $organization->id,
            'brand_profile_id' => (int) $brandProfile->id,
            'platform_codes' => $platformCodes,
            'draft' => $this->draftPayload($draft),
            'references_count' => count((array) ($brief['references'] ?? [])),
            'publish_package' => $package,
            'edit_url' => route('admin.geo.article-drafts.edit', ['draftId' => (int) $draft->id]),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function submitWxmpDraftAction(array $payload, GeoYixiaoerDistributionService $yixiaoer): array
    {
        $draftId = (int) ($payload['draft_id'] ?? 0);
        if ($draftId <= 0) {
            throw new InvalidArgumentException('submit-wxmp-draft 需要 draft_id');
        }
        if ((bool) $this->option('dry-run')) {
            return [
                'ok' => true,
                'action' => 'submit-wxmp-draft',
                'dry_run' => true,
                'draft_id' => $draftId,
            ];
        }

        $platformCodes = $this->stringList($payload['platform_codes'] ?? ['weixingongzhonghao']);
        $draft = GeoArticleDraft::query()->with('writingTask')->findOrFail($draftId);
        $record = $yixiaoer->submitOfficialAccountArticleDraft($draft, $platformCodes);

        return [
            'ok' => true,
            'action' => 'submit-wxmp-draft',
            'record' => $this->publishRecordPayload($record),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function webWorkbenchStatusAction(array $payload, GeoWebWorkbenchClient $webWorkbench): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 5)));

        return [
            'ok' => true,
            'action' => 'web-workbench-status',
            'result' => $webWorkbench->status($limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(bool $required): array
    {
        $json = trim((string) ($this->option('json') ?? ''));
        $file = trim((string) ($this->option('file') ?? ''));
        if ($json !== '' && $file !== '') {
            throw new InvalidArgumentException('--json 和 --file 只能选择一种');
        }
        if ($file !== '') {
            if (! is_readable($file)) {
                throw new InvalidArgumentException('JSON 文件不可读取: '.$file);
            }
            $json = trim((string) file_get_contents($file));
        }
        if ($json === '') {
            if ($required) {
                throw new InvalidArgumentException('此 action 需要 --json 或 --file');
            }

            return [];
        }

        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            throw new InvalidArgumentException('JSON 格式无效: '.json_last_error_msg());
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveAdmin(array $payload, bool $required): ?Admin
    {
        $adminRef = $this->adminRef($payload);
        $query = Admin::query()->where('status', 'active');
        if ($adminRef !== '') {
            $admin = ctype_digit($adminRef)
                ? (clone $query)->whereKey((int) $adminRef)->first()
                : (clone $query)->where('username', $adminRef)->first();

            if (! $admin instanceof Admin) {
                throw new InvalidArgumentException('管理员不存在或未启用: '.$adminRef);
            }

            return $admin;
        }

        $admin = $query->orderBy('id')->first();
        if (! $admin instanceof Admin && $required) {
            throw new InvalidArgumentException('没有可用管理员，请传入 --admin');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveOrganization(?Admin $admin, array $payload, bool $required): ?Organization
    {
        $organizationId = (int) ($payload['organization_id'] ?? 0);
        if ($organizationId > 0) {
            $organization = Organization::query()->find($organizationId);
            if (! $organization instanceof Organization) {
                throw new InvalidArgumentException('组织不存在: '.$organizationId);
            }

            return $organization;
        }

        $organization = $admin instanceof Admin
            ? Organization::query()->where('owner_admin_id', $admin->id)->orderBy('id')->first()
            : Organization::query()->orderBy('id')->first();
        if (! $organization instanceof Organization && $required) {
            throw new InvalidArgumentException('没有可用组织，请先创建品牌资料或传入 organization_id');
        }

        return $organization;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveBrandProfile(Organization $organization, array $payload): BrandProfile
    {
        $brandProfileId = (int) ($payload['brand_profile_id'] ?? 0);
        $query = BrandProfile::query()->where('organization_id', $organization->id);
        $brandProfile = $brandProfileId > 0
            ? (clone $query)->whereKey($brandProfileId)->first()
            : (clone $query)->latest()->first();
        if (! $brandProfile instanceof BrandProfile) {
            throw new InvalidArgumentException('没有可用品牌资料，请先保存品牌知识库');
        }

        return $brandProfile;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function platformCodes(array $payload, bool $required): array
    {
        $platforms = collect((array) $this->option('platform'))
            ->map(static fn (mixed $code): string => trim((string) $code))
            ->filter()
            ->values()
            ->all();
        if ($platforms === []) {
            $platforms = $this->stringList($payload['platform_codes'] ?? $payload['platforms'] ?? []);
        }
        $platforms = collect($platforms)->unique()->values()->all();
        if ($platforms === [] && $required) {
            throw new InvalidArgumentException('请提供 platform_codes');
        }

        return $platforms;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,，、]+/u', $value) ?: [];
        }

        return collect((array) $value)
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function adminRef(array $payload): string
    {
        return trim((string) ($this->option('admin') ?: ($payload['admin'] ?? $payload['admin_id'] ?? $payload['admin_username'] ?? '')));
    }

    private function normalizeAction(string $action): string
    {
        $action = str_replace('_', '-', trim($action));

        return match ($action) {
            'topic', 'topic-pipeline-run' => 'topic-pipeline',
            'wxmp', 'submit-wxmp', 'yixiaoer', 'submit-yixiaoer' => 'submit-wxmp-draft',
            'web-workbench', 'workbench-status' => 'web-workbench-status',
            'geo-run', 'one-shot', 'one-shot-diagnosis' => 'diagnosis',
            default => $action,
        };
    }

    /**
     * @return list<string>
     */
    private function actions(): array
    {
        return ['status', 'schema', 'diagnosis', 'topic-pipeline', 'submit-wxmp-draft', 'web-workbench-status'];
    }

    /**
     * @return array{id:int,username:string,display_name:string}
     */
    private function adminPayload(Admin $admin): array
    {
        return [
            'id' => (int) $admin->id,
            'username' => (string) $admin->username,
            'display_name' => (string) $admin->display_name,
        ];
    }

    /**
     * @return array{id:int,name:string,points:int,status:string}
     */
    private function organizationPayload(Organization $organization): array
    {
        return [
            'id' => (int) $organization->id,
            'name' => (string) $organization->name,
            'points' => (int) $organization->points,
            'status' => (string) $organization->status,
        ];
    }

    /**
     * @return array{id:int,title:string,status:string,writing_task_id:int|null}
     */
    private function draftPayload(GeoArticleDraft $draft): array
    {
        return [
            'id' => (int) $draft->id,
            'title' => (string) $draft->title,
            'status' => (string) $draft->status,
            'writing_task_id' => $draft->geo_writing_task_id ? (int) $draft->geo_writing_task_id : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishRecordPayload(GeoPublishRecord $record): array
    {
        return [
            'id' => (int) $record->id,
            'draft_id' => (int) $record->geo_article_draft_id,
            'status' => (string) $record->status,
            'target_url' => (string) $record->target_url,
            'platform_codes' => (array) $record->platform_codes,
            'error_message' => $record->error_message,
            'submitted_at' => $record->submitted_at?->toDateTimeString(),
            'published_at' => $record->published_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function outputJson(array $payload): void
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ((bool) $this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $this->line(json_encode($payload, $flags) ?: '{"ok":false,"error":"json_encode_failed"}');
    }
}
