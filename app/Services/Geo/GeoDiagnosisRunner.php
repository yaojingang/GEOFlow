<?php

namespace App\Services\Geo;

use App\Models\AiModel;
use App\Models\BrandProfile;
use App\Models\GeoAiPlatform;
use App\Models\GeoCompetitor;
use App\Models\GeoReport;
use App\Models\GeoTask;
use App\Models\GeoTaskQuestion;
use App\Models\PointLog;
use Illuminate\Support\Facades\DB;

class GeoDiagnosisRunner
{
    public function __construct(
        private readonly GeoAIPlatformClient $client,
        private readonly GeoAnswerScorer $scorer,
        private readonly GeoReportBuilder $reportBuilder
    ) {}

    public function run(GeoTask $task): GeoTask
    {
        $task->loadMissing(['organization', 'brandProfile', 'questions.geoKeyword']);
        if ($task->status === 'completed') {
            return $task;
        }

        return DB::transaction(function () use ($task): GeoTask {
            $task->forceFill([
                'status' => 'running',
                'started_at' => $task->started_at ?? now(),
                'error_message' => null,
            ])->save();

            $brandProfile = $task->brandProfile;
            $brandNames = $this->brandNames($brandProfile);
            $competitorNames = $this->competitorNames((int) $task->organization_id);

            foreach ($task->questions as $question) {
                $this->runQuestion($task, $question, $brandProfile, $brandNames, $competitorNames);
            }

            $totalScore = (int) round((float) $task->answers()->join('geo_scores', 'geo_answers.id', '=', 'geo_scores.geo_answer_id')->avg('geo_scores.score'));
            $task->forceFill([
                'status' => 'completed',
                'total_score' => $totalScore,
                'finished_at' => now(),
            ])->save();

            $reportPayload = $this->reportBuilder->build($task->fresh(['brandProfile', 'answers.question', 'answers.score']));
            GeoReport::query()->updateOrCreate(
                ['geo_task_id' => $task->id],
                $reportPayload + [
                    'total_score' => $totalScore,
                    'status' => 'ready',
                ]
            );

            $this->recordPointCost($task);

            return $task->fresh(['report']);
        });
    }

    /**
     * @param  list<string>  $brandNames
     * @param  list<string>  $competitorNames
     */
    private function runQuestion(GeoTask $task, GeoTaskQuestion $question, BrandProfile $brandProfile, array $brandNames, array $competitorNames): void
    {
        $question->forceFill(['status' => 'running'])->save();
        $platformCodes = $question->platform_codes ?: ['deepseek_mock'];

        foreach ($platformCodes as $platformCode) {
            $platform = $this->resolvePlatform((string) $platformCode);
            $prompt = $this->buildPrompt($brandProfile, $question);
            $rawAnswer = $this->client->ask($platform, $brandProfile, $question, $prompt);

            $answer = $task->answers()->updateOrCreate(
                [
                    'geo_task_question_id' => $question->id,
                    'platform_code' => $platform->code,
                ],
                [
                    'prompt' => $prompt,
                    'raw_answer' => $rawAnswer,
                    'status' => 'succeeded',
                    'error_message' => null,
                    'answered_at' => now(),
                ]
            );

            $score = $this->scorer->score($brandNames, $competitorNames, $rawAnswer);
            $answer->score()->updateOrCreate(
                ['geo_answer_id' => $answer->id],
                [
                    'brand_mentioned' => $score['brand_mentioned'],
                    'is_recommended' => $score['is_recommended'],
                    'rank_position' => $score['rank_position'],
                    'competitors_mentioned' => $score['competitors_mentioned'],
                    'citations' => $score['has_citation'] ? ['模拟来源：品牌知识库'] : [],
                    'score' => $score['score'],
                    'analysis_json' => $score,
                ]
            );
        }

        $question->forceFill(['status' => 'completed'])->save();
    }

    private function resolvePlatform(string $code): GeoAiPlatform
    {
        if (preg_match('/^ai_model:(\d+)$/', $code, $matches) === 1) {
            $model = AiModel::query()->whereKey((int) $matches[1])->first();

            return new GeoAiPlatform([
                'code' => $code,
                'name' => $model instanceof AiModel ? (string) $model->name : '真实 AI 模型 #'.(int) $matches[1],
                'api_mode' => 'ai_model',
                'cost_per_query' => 1,
                'status' => 'active',
            ]);
        }

        return GeoAiPlatform::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $this->platformName($code),
                'api_mode' => 'mock',
                'cost_per_query' => 1,
                'status' => 'active',
            ]
        );
    }

    private function platformName(string $code): string
    {
        return match ($code) {
            GeoWebWorkbenchClient::PLATFORM_CODE => GeoWebWorkbenchClient::PLATFORM_NAME,
            'deepseek_mock' => 'DeepSeek 模拟',
            'kimi_mock' => 'Kimi 模拟',
            'qwen_mock' => '通义千问模拟',
            default => $code,
        };
    }

    private function buildPrompt(BrandProfile $brandProfile, GeoTaskQuestion $question): string
    {
        return implode("\n", array_merge([
            '请像真实 AI 搜索助手一样回答用户问题。',
            '用户问题：'.$question->question,
            '品牌：'.$brandProfile->brand_name,
            '别名：'.implode('、', (array) $brandProfile->aliases),
            '产品/服务：'.(string) $brandProfile->products,
            '核心优势：'.(string) $brandProfile->advantages,
            '服务区域：'.(string) $brandProfile->service_area,
            '补充事实：'.(string) $brandProfile->extra_facts,
        ], BrandProfileContextFormatter::promptLines($brandProfile)));
    }

    /**
     * @return list<string>
     */
    private function brandNames(BrandProfile $brandProfile): array
    {
        return collect([(string) $brandProfile->brand_name])
            ->merge((array) $brandProfile->aliases)
            ->map(static fn (mixed $name): string => trim((string) $name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function competitorNames(int $organizationId): array
    {
        return GeoCompetitor::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->flatMap(fn (GeoCompetitor $competitor): array => array_merge([(string) $competitor->name], (array) $competitor->aliases))
            ->map(static fn (mixed $name): string => trim((string) $name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function recordPointCost(GeoTask $task): void
    {
        $pointsCost = max(0, (int) $task->points_cost);
        if ($pointsCost <= 0) {
            return;
        }

        $alreadyCharged = PointLog::query()
            ->where('action', 'geo_diagnosis')
            ->where('ref_type', GeoTask::class)
            ->where('ref_id', $task->id)
            ->exists();
        if ($alreadyCharged) {
            return;
        }

        $task->organization()->decrement('points', $pointsCost);
        PointLog::query()->create([
            'organization_id' => $task->organization_id,
            'admin_id' => $task->created_by_admin_id,
            'action' => 'geo_diagnosis',
            'points_delta' => -$pointsCost,
            'ref_type' => GeoTask::class,
            'ref_id' => $task->id,
            'remark' => 'GEO 诊断任务消耗',
        ]);
    }
}
