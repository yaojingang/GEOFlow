<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\BrandProfile;
use App\Models\GeoAiPlatform;
use App\Models\GeoKeyword;
use App\Models\GeoTask;
use App\Models\Organization;
use App\Services\Geo\GeoDiagnosisRunner;
use App\Services\Geo\GeoWebWorkbenchClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class GeoFlowOneShotDiagnosisCommand extends Command
{
    protected $signature = 'geoflow:geo-run
        {--json= : 一次性诊断 JSON 内容}
        {--file= : 一次性诊断 JSON 文件路径}
        {--admin= : 管理员用户名或 ID，未传时使用第一个 active 管理员}
        {--platform=* : 覆盖 JSON 内的平台编码，可重复传入}
        {--no-run : 只保存品牌和关键词并创建任务，不立即执行}
        {--pretty : 格式化 JSON 输出}';

    protected $description = 'Import GEO brand/keywords from one JSON payload, create a diagnosis task, and optionally run it immediately';

    public function handle(GeoDiagnosisRunner $runner): int
    {
        try {
            $payload = $this->payload();
            $admin = $this->resolveAdmin($payload);
            $platforms = $this->platformCodes($payload);
            $keywords = $this->keywordPayloads($payload);

            $shouldRun = ! (bool) $this->option('no-run');
            $created = DB::transaction(fn (): array => $this->createDiagnosis($payload, $admin, $platforms, $keywords));
            $task = $created['task'];

            if ($shouldRun) {
                $organization = $task->organization()->firstOrFail();
                if ((int) $organization->points < (int) $task->points_cost) {
                    throw new InvalidArgumentException('点数不足，无法执行诊断任务。任务已创建为 pending，task_id='.$task->id);
                }

                try {
                    $task = $runner->run($task);
                } catch (Throwable $exception) {
                    $task->forceFill([
                        'status' => 'failed',
                        'finished_at' => now(),
                        'error_message' => $this->errorPreview($exception),
                    ])->save();

                    throw $exception;
                }
            }

            $task = $task->fresh(['report']) ?? $task;
            $this->outputJson([
                'ok' => true,
                'ran' => $shouldRun,
                'admin_id' => (int) $admin->id,
                'organization_id' => (int) $created['organization']->id,
                'brand_profile_id' => (int) $created['brand_profile']->id,
                'keyword_ids' => $created['keyword_ids'],
                'task_id' => (int) $task->id,
                'task_status' => (string) $task->status,
                'points_cost' => (int) $task->points_cost,
                'report_id' => $task->report ? (int) $task->report->id : null,
                'total_score' => $task->report ? (int) $task->report->total_score : null,
                'report_url' => $task->report ? route('admin.geo.reports.show', ['taskId' => (int) $task->id]) : null,
            ]);

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->outputJson([
                'ok' => false,
                'error' => $exception->getMessage(),
            ]);

            return self::INVALID;
        } catch (Throwable $exception) {
            $this->outputJson([
                'ok' => false,
                'error' => $this->errorPreview($exception),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $json = trim((string) ($this->option('json') ?? ''));
        $file = trim((string) ($this->option('file') ?? ''));
        if ($json === '' && $file === '') {
            throw new InvalidArgumentException('请通过 --json 或 --file 提供一次性诊断内容');
        }
        if ($json !== '' && $file !== '') {
            throw new InvalidArgumentException('--json 和 --file 只能选择一种输入方式');
        }

        if ($file !== '') {
            if (! is_readable($file)) {
                throw new InvalidArgumentException('JSON 文件不可读取: '.$file);
            }

            $json = trim((string) file_get_contents($file));
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
    private function resolveAdmin(array $payload): Admin
    {
        $adminRef = trim((string) ($this->option('admin') ?: ($payload['admin_id'] ?? $payload['admin_username'] ?? $payload['admin'] ?? '')));
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
        if (! $admin instanceof Admin) {
            throw new InvalidArgumentException('没有可用管理员，请先创建 active 管理员或传入 --admin');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function platformCodes(array $payload): array
    {
        $optionPlatforms = collect((array) $this->option('platform'))
            ->map(static fn (mixed $code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->values()
            ->all();

        $platforms = $optionPlatforms !== []
            ? $optionPlatforms
            : $this->stringList($payload['platform_codes'] ?? $payload['platforms'] ?? [GeoWebWorkbenchClient::PLATFORM_CODE]);

        $platforms = collect($platforms)
            ->map(static fn (string $code): string => trim($code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();

        if ($platforms === []) {
            throw new InvalidArgumentException('请提供至少一个 AI 平台编码');
        }

        foreach ($platforms as $platformCode) {
            $this->ensurePlatform($platformCode);
        }

        return $platforms;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{keyword:string,type:string,intent:string}>
     */
    private function keywordPayloads(array $payload): array
    {
        $defaultType = trim((string) ($payload['keyword_type'] ?? 'question')) ?: 'question';
        $defaultIntent = trim((string) ($payload['intent'] ?? 'commercial'));
        $items = collect();

        foreach ($this->stringList($payload['keywords_text'] ?? '') as $keyword) {
            $items->push([
                'keyword' => $keyword,
                'type' => $defaultType,
                'intent' => $defaultIntent,
            ]);
        }

        foreach ((array) ($payload['questions'] ?? []) as $question) {
            if (is_string($question) || is_numeric($question)) {
                $items->push([
                    'keyword' => (string) $question,
                    'type' => 'question',
                    'intent' => $defaultIntent,
                ]);
            }
        }

        foreach ((array) ($payload['keywords'] ?? []) as $keyword) {
            if (is_string($keyword) || is_numeric($keyword)) {
                $items->push([
                    'keyword' => (string) $keyword,
                    'type' => $defaultType,
                    'intent' => $defaultIntent,
                ]);
            } elseif (is_array($keyword)) {
                $items->push([
                    'keyword' => (string) ($keyword['keyword'] ?? $keyword['question'] ?? $keyword['text'] ?? ''),
                    'type' => (string) ($keyword['type'] ?? $defaultType),
                    'intent' => (string) ($keyword['intent'] ?? $defaultIntent),
                ]);
            }
        }

        $allowedTypes = ['industry', 'brand', 'competitor', 'question'];
        $keywords = $items
            ->map(fn (array $item): array => [
                'keyword' => mb_substr(trim((string) $item['keyword']), 0, 255),
                'type' => in_array((string) $item['type'], $allowedTypes, true) ? (string) $item['type'] : 'question',
                'intent' => mb_substr(trim((string) $item['intent']), 0, 80),
            ])
            ->filter(static fn (array $item): bool => $item['keyword'] !== '')
            ->unique(fn (array $item): string => $item['type'].'|'.$item['keyword'])
            ->take(80)
            ->values()
            ->all();

        if ($keywords === []) {
            throw new InvalidArgumentException('请在 JSON 中提供 keywords、questions 或 keywords_text');
        }

        return $keywords;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $platforms
     * @param  list<array{keyword:string,type:string,intent:string}>  $keywords
     * @return array{organization: Organization, brand_profile: BrandProfile, keyword_ids: list<int>, task: GeoTask}
     */
    private function createDiagnosis(array $payload, Admin $admin, array $platforms, array $keywords): array
    {
        $organization = $this->resolveOrganization($payload, $admin);
        $brandProfile = $this->saveBrandProfile($payload, $organization);
        $keywordModels = $this->saveKeywords($organization, $keywords);
        $pointsCost = $keywordModels->count() * $this->platformCost($platforms);
        $reportMode = (string) ($payload['report_mode'] ?? 'with_recommendations');
        if (! in_array($reportMode, ['visibility_only', 'with_recommendations'], true)) {
            $reportMode = 'with_recommendations';
        }

        $taskName = trim((string) ($payload['task_name'] ?? ''));
        if ($taskName === '') {
            $taskName = 'GEO CLI 诊断 - '.$brandProfile->brand_name.' - '.now()->format('m-d H:i');
        }

        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => $taskName,
            'status' => 'pending',
            'points_cost' => $pointsCost,
            'report_mode' => $reportMode,
        ]);

        foreach ($keywordModels as $keyword) {
            $task->questions()->create([
                'geo_keyword_id' => $keyword->id,
                'question' => $this->buildQuestion($brandProfile, $keyword),
                'platform_codes' => $platforms,
                'status' => 'pending',
            ]);
        }

        return [
            'organization' => $organization,
            'brand_profile' => $brandProfile,
            'keyword_ids' => $keywordModels->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all(),
            'task' => $task,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveOrganization(array $payload, Admin $admin): Organization
    {
        $brand = $this->brandPayload($payload);
        $fallbackName = trim((string) ($admin->display_name ?: $admin->username)) ?: '默认企业';
        $organizationName = trim((string) ($payload['organization_name'] ?? $brand['organization_name'] ?? $fallbackName));

        $organization = Organization::query()->firstOrCreate(
            ['owner_admin_id' => $admin->id],
            [
                'name' => $organizationName !== '' ? $organizationName : $fallbackName,
                'plan_code' => (string) ($payload['plan_code'] ?? 'trial'),
                'points' => (int) ($payload['points'] ?? 100),
                'balance' => 0,
                'status' => 'active',
            ]
        );

        $attributes = [
            'name' => $organizationName !== '' ? $organizationName : $fallbackName,
            'plan_code' => (string) ($payload['plan_code'] ?? $organization->plan_code ?? 'trial'),
            'status' => 'active',
        ];
        if (array_key_exists('points', $payload)) {
            $attributes['points'] = (int) $payload['points'];
        }
        $organization->forceFill($attributes)->save();

        return $organization;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveBrandProfile(array $payload, Organization $organization): BrandProfile
    {
        $brand = $this->brandPayload($payload);
        $brandName = trim((string) ($payload['brand_name'] ?? $brand['brand_name'] ?? $brand['name'] ?? ''));
        if ($brandName === '') {
            throw new InvalidArgumentException('请提供 brand_name 或 brand.name');
        }

        return BrandProfile::query()->updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'brand_name' => $brandName,
                'aliases' => $this->stringList($payload['aliases'] ?? $brand['aliases'] ?? $payload['aliases_text'] ?? $brand['aliases_text'] ?? ''),
                'products' => $this->payloadString($payload, $brand, 'products'),
                'advantages' => $this->payloadString($payload, $brand, 'advantages'),
                'cases' => $this->payloadString($payload, $brand, 'cases'),
                'pain_points' => $this->payloadString($payload, $brand, 'pain_points'),
                'service_area' => $this->payloadString($payload, $brand, 'service_area'),
                'extra_facts' => $this->payloadString($payload, $brand, 'extra_facts'),
                'extended_profile' => $this->extendedProfile($payload, $brand),
                'status' => 'active',
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function brandPayload(array $payload): array
    {
        return is_array($payload['brand'] ?? null) ? $payload['brand'] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $brand
     */
    private function payloadString(array $payload, array $brand, string $key): string
    {
        return trim((string) ($payload[$key] ?? $brand[$key] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    private function extendedProfile(array $payload, array $brand): array
    {
        $extended = is_array($payload['extended_profile'] ?? null)
            ? $payload['extended_profile']
            : (is_array($brand['extended_profile'] ?? null) ? $brand['extended_profile'] : []);

        return [
            'short_name' => trim((string) ($payload['short_name'] ?? $brand['short_name'] ?? $extended['short_name'] ?? '')),
            'writing_directions' => trim((string) ($payload['writing_directions'] ?? $brand['writing_directions'] ?? $extended['writing_directions'] ?? '')),
            'copy_types' => $this->stringList($payload['copy_types'] ?? $brand['copy_types'] ?? $extended['copy_types'] ?? ''),
            'product_features' => $this->stringList($payload['product_features'] ?? $brand['product_features'] ?? $extended['product_features'] ?? ''),
            'brand_story' => trim((string) ($payload['brand_story'] ?? $brand['brand_story'] ?? $extended['brand_story'] ?? '')),
            'trust_proofs' => $this->stringList($payload['trust_proofs'] ?? $brand['trust_proofs'] ?? $extended['trust_proofs'] ?? ''),
            'promotion_regions' => $this->stringList($payload['promotion_regions'] ?? $brand['promotion_regions'] ?? $extended['promotion_regions'] ?? ''),
            'forbidden_claims' => $this->stringList($payload['forbidden_claims'] ?? $brand['forbidden_claims'] ?? $extended['forbidden_claims'] ?? ''),
        ];
    }

    /**
     * @param  list<array{keyword:string,type:string,intent:string}>  $keywords
     * @return Collection<int, GeoKeyword>
     */
    private function saveKeywords(Organization $organization, array $keywords): Collection
    {
        return collect($keywords)
            ->map(fn (array $item): GeoKeyword => GeoKeyword::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'type' => $item['type'],
                    'keyword' => $item['keyword'],
                ],
                [
                    'intent' => $item['intent'],
                    'status' => 'active',
                ]
            ))
            ->values();
    }

    private function buildQuestion(BrandProfile $brandProfile, GeoKeyword $keyword): string
    {
        if ($keyword->type === 'question') {
            return (string) $keyword->keyword;
        }

        $area = trim((string) $brandProfile->service_area);
        $prefix = $area !== '' ? '在'.$area : '';

        return $prefix.'选择'.$keyword->keyword.'时，哪些品牌值得优先了解？';
    }

    private function ensurePlatform(string $platformCode): void
    {
        if (preg_match('/^ai_model:(\d+)$/', $platformCode, $matches) === 1) {
            $exists = AiModel::query()
                ->whereKey((int) $matches[1])
                ->where('status', 'active')
                ->exists();
            if (! $exists) {
                throw new InvalidArgumentException('真实 AI 模型不存在或未启用: '.$platformCode);
            }

            return;
        }

        GeoAiPlatform::query()->updateOrCreate(
            ['code' => $platformCode],
            [
                'name' => $this->platformName($platformCode),
                'api_mode' => $platformCode === GeoWebWorkbenchClient::PLATFORM_CODE ? 'web_workbench' : 'mock',
                'cost_per_query' => 1,
                'status' => 'active',
            ]
        );
    }

    private function platformName(string $platformCode): string
    {
        return match ($platformCode) {
            GeoWebWorkbenchClient::PLATFORM_CODE => GeoWebWorkbenchClient::PLATFORM_NAME,
            'deepseek_mock' => 'DeepSeek 模拟',
            'kimi_mock' => 'Kimi 模拟',
            'qwen_mock' => '通义千问模拟',
            default => $platformCode,
        };
    }

    /**
     * @param  list<string>  $platforms
     */
    private function platformCost(array $platforms): int
    {
        return collect($platforms)
            ->sum(function (string $platformCode): int {
                if (preg_match('/^ai_model:\d+$/', $platformCode) === 1) {
                    return 1;
                }

                return (int) (GeoAiPlatform::query()->where('code', $platformCode)->value('cost_per_query') ?: 1);
            });
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) || is_numeric($value)) {
            $items = preg_split('/\R|[,，、;；]/u', (string) $value) ?: [];
        } else {
            $items = [];
        }

        return collect($items)
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
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

        $this->line((string) json_encode($payload, $flags));
    }

    private function errorPreview(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' ? mb_substr($message, 0, 1000) : $exception::class;
    }
}
