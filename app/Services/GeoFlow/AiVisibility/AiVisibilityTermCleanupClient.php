<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Data\Ai\SystemAiIdentity;
use Throwable;

final class AiVisibilityTermCleanupClient implements AiVisibilityTermCleaner
{
    public function __construct(
        private readonly AiVisibilityConfigurationResolver $configuration,
        private readonly AiVisibilityService $visibility,
    ) {}

    /**
     * 用系统分析模型清洗词云候选主题：剔除 URL/标记噪声、合并同义与碎片、规范命名。
     * 模型未配置或调用失败时返回 null，由调用方回退到启发式结果。
     *
     * @param  array<string, float>  $candidates  候选词 => 权重
     * @return list<array{name: string, terms: list<string>}>|null
     */
    public function clean(array $candidates): ?array
    {
        if ($candidates === []) {
            return [];
        }

        try {
            $model = $this->configuration->deepSeekModel(SystemAiIdentity::forVisibilityCollection());
            if ($model === null) {
                return null;
            }
            $prompt = $this->instructions()."\n\n".json_encode(['candidates' => $candidates], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $run = $this->visibility->runTermCleanup(SystemAiIdentity::forVisibilityCollection(), $model, $prompt);

            return self::parseTopics((string) $run->answer_text);
        } catch (Throwable) {
            return null;
        }
    }

    private function instructions(): string
    {
        return <<<'TEXT'
你是 GEO 分析系统的主题清洗助手。输入 JSON 的 candidates 是从 AI 回答与搜索信源中自动提取的候选主题词及其权重，其中可能混有 URL/域名片段、HTML 或 JSON-LD 标记残留、无意义英文缩写、跨字断词噪声。
请清洗归一：
1. 剔除与真实业务主题无关的噪声（URL 片段、参数名、样式类名、无意义缩写等）；
2. 把同义、变体、中外文混写、截断碎片合并到同一个主题，主题名用简洁的中文或通用英文名；
3. 只保留业务上有意义的主题，保持原始权重语义，不要发明输入中不存在的主题。
输出严格 JSON，不要输出任何其他文字：{"topics":[{"name":"主题名","terms":["参与合并的原始候选词"]}]}。terms 必须逐字来自输入候选词，每个候选词最多归属一个主题。
TEXT;
    }

    /**
     * @return list<array{name: string, terms: list<string>}>|null
     */
    public static function parseTopics(string $text): ?array
    {
        $json = trim($text);
        if ($json === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $json, $match) === 1) {
            $json = trim($match[1]);
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded) && preg_match('/\{.*\}/s', $json, $match) === 1) {
            $decoded = json_decode($match[0], true);
        }
        if (! is_array($decoded) || ! is_array($decoded['topics'] ?? null)) {
            return null;
        }

        $topics = [];
        foreach ($decoded['topics'] as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $name = trim((string) ($topic['name'] ?? ''));
            $terms = array_values(array_filter(
                array_map(static fn (mixed $term): string => trim((string) $term), (array) ($topic['terms'] ?? [])),
                static fn (string $term): bool => $term !== '',
            ));
            if ($name === '' || $terms === [] || mb_strlen($name, 'UTF-8') > 30) {
                continue;
            }
            $topics[] = ['name' => $name, 'terms' => $terms];
        }

        return $topics;
    }
}
