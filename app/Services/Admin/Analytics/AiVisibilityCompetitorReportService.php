<?php

namespace App\Services\Admin\Analytics;

use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityRun;

class AiVisibilityCompetitorReportService
{
    public function stats(int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $rows = [];
        $patterns = [];
        $mentioned = [];
        foreach (AiVisibilityCompetitor::query()->where('is_active', true)->select('id', 'name', 'aliases')->lazyById(200) as $competitor) {
            $terms = $competitor->matchTerms();
            $rows[$competitor->id] = ['id' => $competitor->id, 'name' => $competitor->name, 'aliases' => array_slice($terms, 1), 'mentions' => 0, 'samples_mentioned' => 0, 'mention_rate' => 0.0, 'keywords' => []];
            usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            $patterns[$competitor->id] = '/'.implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)).'/iu';
        }
        $samples = [];
        $keywords = [];
        $runs = AiVisibilityRun::query()->where('status', AiVisibilityRun::STATUS_COMPLETED)
            ->whereIn('provider_type', AiVisibilityRun::SAMPLE_PROVIDERS)
            ->whereNotNull('keyword')->where('keyword', '!=', '')
            ->whereRaw('COALESCE(completed_at, updated_at) >= ?', [now()->subDays($days)])
            ->select('id', 'keyword', 'answer_text', 'completed_at', 'updated_at')
            ->lazyById(500);
        foreach ($runs as $run) {
            $key = json_encode([$run->keyword, ($run->completed_at ?? $run->updated_at)->toDateString()], JSON_THROW_ON_ERROR);
            $samples[$key] = true;
            $keywords[$run->keyword] = true;
            foreach ($patterns as $id => $pattern) {
                $hits = preg_match_all($pattern, (string) $run->answer_text);
                if ($hits > 0) {
                    $rows[$id]['mentions'] += $hits;
                    $rows[$id]['keywords'][$run->keyword] = true;
                    $mentioned[$id][$key] = true;
                }
            }
        }
        foreach ($rows as $id => &$row) {
            $row['samples_mentioned'] = count($mentioned[$id] ?? []);
            $row['mention_rate'] = count($samples) > 0 ? round($row['samples_mentioned'] / count($samples) * 100, 1) : 0.0;
            $row['keywords'] = array_keys($row['keywords']);
        }
        unset($row);

        return ['days' => $days, 'total_samples' => count($samples), 'keywords_sampled' => count($keywords), 'competitors' => collect($rows)->sortByDesc('mentions')->values()->all()];
    }
}
