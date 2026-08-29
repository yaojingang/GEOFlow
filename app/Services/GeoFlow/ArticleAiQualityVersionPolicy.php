<?php

namespace App\Services\GeoFlow;

final class ArticleAiQualityVersionPolicy
{
    public function __construct(private readonly ?ArticleAiQualityRolloutPolicy $rolloutPolicy = null) {}

    /**
     * @return array{execution:string,principles:string,scoring:string,shadow_v2:bool,gate_applied_v2:bool,algorithm_version:string,buckets:array<string,int>}
     */
    public function selection(int $articleId, string $workspace = 'single-workspace'): array
    {
        $rollout = $this->rollout()->state();
        $principleBucket = $this->bucket($workspace, $articleId, 'principles-v2');
        $fastBucket = $this->bucket($workspace, $articleId, 'fast-v2');
        $scoringBucket = $this->bucket($workspace, $articleId, 'scoring-v2');
        $shadowBucket = $this->bucket($workspace, $articleId, 'shadow-v2');
        $principles = $principleBucket < (int) ($rollout['principle_percent'] ?? 0) ? 'v2' : 'v1';
        $execution = $fastBucket < (int) ($rollout['execution_percent'] ?? 0)
                ? 'fast_v2'
                : 'legacy';
        $scoring = $scoringBucket < (int) ($rollout['scoring_percent'] ?? 0)
            ? 'v2'
            : 'v1';
        $shadowV2 = $scoring !== 'v2'
            && $shadowBucket < (int) ($rollout['shadow_percent'] ?? 0);

        return [
            'execution' => $execution,
            'principles' => $principles,
            'scoring' => $scoring,
            'shadow_v2' => $shadowV2,
            'gate_applied_v2' => $scoring === 'v2',
            'algorithm_version' => sprintf(
                'exec=%s;ret=4;principles=%s;prompt=2;score=%s',
                $execution === 'fast_v2' ? 'f2' : 'v1',
                $principles === 'v2' ? '2' : '1',
                $scoring === 'v2' ? '2' : '1',
            ),
            'buckets' => [
                'principles_v2' => $principleBucket,
                'fast_v2' => $fastBucket,
                'scoring_v2' => $scoringBucket,
                'shadow_v2' => $shadowBucket,
            ],
        ];
    }

    public function sampledAutoReleaseEnabled(): bool
    {
        return $this->rollout()->sampledAutoReleaseEnabled();
    }

    /** @return array<string,mixed> */
    public function rolloutState(): array
    {
        return $this->rollout()->state();
    }

    public function bucketForTrack(int $articleId, string $track, string $workspace = 'single-workspace'): int
    {
        $experiment = match ($track) {
            'principles' => 'principles-v2',
            'execution' => 'fast-v2',
            'scoring' => 'scoring-v2',
            'shadow' => 'shadow-v2',
            default => throw new \InvalidArgumentException('Unknown AI quality rollout track.'),
        };

        return $this->bucket($workspace, $articleId, $experiment);
    }

    private function rollout(): ArticleAiQualityRolloutPolicy
    {
        return $this->rolloutPolicy ?? app(ArticleAiQualityRolloutPolicy::class);
    }

    private function bucket(string $workspace, int $articleId, string $experiment): int
    {
        $hash = hash('sha256', $workspace."\0".$articleId."\0".$experiment);

        return (int) (hexdec(substr($hash, 0, 8)) % 100);
    }
}
