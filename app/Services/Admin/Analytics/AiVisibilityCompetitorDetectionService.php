<?php

namespace App\Services\Admin\Analytics;

use App\Models\AiModel;
use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityCompetitorDetection;
use App\Models\AiVisibilityRun;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AiVisibilityCompetitorDetectionService
{
    /**
     * 用 AI 从最近的采样回答中自动识别竞品品牌,发现的品牌会
     * upsert 进竞品名单(source=auto),供竞品统计直接使用。
     *
     * @return array{processed: int, discovered: list<string>}
     */
    public function detect(int $limit = 8): array
    {
        $model = AiModel::query()
            ->where('model_type', 'chat')
            ->where('status', 'active')
            ->whereIn('id', [4, 1])
            ->orderByRaw('id = 4 desc')
            ->first();

        if (! $model) {
            return ['processed' => 0, 'discovered' => []];
        }

        $ownBrandAliases = $this->ownBrandAliases();
        $processed = 0;
        $discovered = [];

        AiVisibilityRun::query()
            ->where('status', 'completed')
            ->whereIn('provider_type', [AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS, AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES])
            ->where('answer_text', '!=', '')
            ->whereDoesntHave('competitorDetection')
            ->latest('id')
            ->limit(max(1, min(20, $limit)))
            ->get()
            ->each(function (AiVisibilityRun $run) use (&$processed, &$discovered, $model, $ownBrandAliases): void {
                $text = mb_substr((string) $run->answer_text, 0, 8000);
                $names = $this->extractCompetitors($model, $text, $ownBrandAliases);

                AiVisibilityCompetitorDetection::query()->updateOrCreate(
                    ['run_id' => $run->id],
                    ['names_json' => $names],
                );
                $processed++;

                foreach ($names as $name) {
                    if (! in_array($name, $discovered, true)) {
                        $discovered[] = $name;
                    }
                    AiVisibilityCompetitor::query()->updateOrCreate(
                        ['name' => $name],
                        ['aliases' => [], 'is_active' => true, 'source' => 'auto'],
                    );
                }
            });

        return ['processed' => $processed, 'discovered' => $discovered];
    }

    /**
     * @return list<string>
     */
    private function extractCompetitors(AiModel $model, string $answerText, array $ownBrandAliases): array
    {
        $providerUrl = rtrim((string) $model->api_url, '/');
        $apiKey = app(ApiKeyCrypto::class)->decrypt((string) $model->getRawOriginal('api_key'));
        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) $model->model_id);
        $providerName = OpenAiRuntimeProvider::registerProvider(
            'ai_visibility_competitor_detect',
            $driver,
            $providerUrl,
            $apiKey,
        );

        $exclude = implode('、', $ownBrandAliases);
        $instructions = '你是竞品品牌识别助手。只输出一个 JSON 数组,不要输出任何解释、Markdown 代码块或其他文字。'
            .'从给定的 AI 回答文本中,提取被提及的商业培训机构/公司品牌名称(出现于文本中、与关键词业务相关的具体机构名)。'
            .'排除以下自身品牌及泛化词汇(如「培训机构」「教育机构」「在线课程」等类别词):'.$exclude.'。'
            .'每个品牌输出 {"name":"品牌名","count":出现次数};没有发现则输出 []。';

        $agent = new \App\Ai\Agents\MarkdownContentWriterAgent(
            instructions: $instructions,
            maxTokens: 4096,
        );

        try {
            $response = $agent->prompt(
                "识别以下文本中的竞品品牌,按要求的 JSON 数组格式输出:\n\n".$answerText,
                [],
                $providerName,
                (string) $model->model_id,
            );
        } catch (Throwable $exception) {
            Log::error('ai_visibility_competitor_detect_failed', [
                'message' => mb_substr($exception->getMessage(), 0, 300),
            ]);

            return [];
        }

        $raw = trim((string) ($response->text ?? ''));
        if ($raw === '') {
            return [];
        }

        // 兼容模型把 JSON 包在 ```json ``` 里的情况
        if (preg_match('/\[.*\]/s', $raw, $matches) !== 1) {
            return [];
        }

        $items = json_decode($matches[0], true);
        if (! is_array($items)) {
            return [];
        }

        $ownLower = array_map('mb_strtolower', $ownBrandAliases);
        $names = [];
        foreach ($items as $item) {
            $name = trim((string) (is_array($item) ? ($item['name'] ?? '') : $item));
            if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 60) {
                continue;
            }
            if (in_array(mb_strtolower($name), $ownLower, true)) {
                continue;
            }
            $names[$name] = true;
        }

        return array_keys($names);
    }

    /**
     * @return list<string>
     */
    private function ownBrandAliases(): array
    {
        return collect([
            config('geoflow.site_name'),
            config('geoflow.site_full_name'),
            'GEOFlow',
            '多次元',
            '多次元教育',
            '多次元内部测试demo1',
        ])
            ->map(static fn (mixed $alias): string => trim((string) $alias))
            ->filter(static fn (string $alias): bool => $alias !== '')
            ->unique()
            ->values()
            ->all();
    }
}
