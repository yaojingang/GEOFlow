<?php

namespace App\Services\GeoFlow;

use InvalidArgumentException;

final class ArticleAiOptimizationPolicy
{
    public const STRATEGY_PASS = 'pass';

    public const STRATEGY_EXCELLENT_80 = 'excellent_80';

    public const STRATEGY_EXCELLENT_90 = 'excellent_90';

    /** @return list<string> */
    public static function strategies(): array
    {
        return [
            self::STRATEGY_PASS,
            self::STRATEGY_EXCELLENT_80,
            self::STRATEGY_EXCELLENT_90,
        ];
    }

    /** @return array{strategy:string,target_score:int,max_rounds:int,edit_budget_percent:int,max_edit_characters:int,estimated_seconds:int} */
    public function resolve(string $strategy, int $passScore): array
    {
        if (! in_array($strategy, self::strategies(), true)) {
            throw new InvalidArgumentException('article_ai_optimization_strategy_invalid');
        }

        $minimumTarget = match ($strategy) {
            self::STRATEGY_PASS => $passScore,
            self::STRATEGY_EXCELLENT_80 => 80,
            self::STRATEGY_EXCELLENT_90 => 90,
        };
        $defaults = match ($strategy) {
            self::STRATEGY_PASS => ['rounds' => 1, 'budget' => 15],
            self::STRATEGY_EXCELLENT_80 => ['rounds' => 2, 'budget' => 25],
            self::STRATEGY_EXCELLENT_90 => ['rounds' => 3, 'budget' => 35],
        };
        $configured = (array) config('geoflow.ai_quality_optimization_strategies.'.$strategy, []);
        $maxRounds = max(1, min(
            (int) config('geoflow.ai_quality_optimization_max_rounds', 3),
            (int) ($configured['max_rounds'] ?? $defaults['rounds']),
        ));

        return [
            'strategy' => $strategy,
            'target_score' => max(1, min(100, max($passScore, $minimumTarget))),
            'max_rounds' => $maxRounds,
            'edit_budget_percent' => max(1, min(100, (int) ($configured['edit_budget_percent'] ?? $defaults['budget']))),
            'max_edit_characters' => max(1, (int) config('geoflow.ai_quality_optimization_max_edit_characters', 8000)),
            'estimated_seconds' => $maxRounds * max(1, (int) config('geoflow.ai_quality_optimization_round_estimated_seconds', 235)),
        ];
    }

    public function hash(array $policy): string
    {
        return hash('sha256', json_encode([
            'version' => '1.0.0',
            'policy' => $policy,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
