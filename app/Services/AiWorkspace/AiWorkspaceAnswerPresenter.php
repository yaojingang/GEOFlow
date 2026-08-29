<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiWorkspaceUrlSanitizer;
use App\Models\AiWorkspaceRun;
use Illuminate\Support\Str;

final readonly class AiWorkspaceAnswerPresenter
{
    /** @param list<array<string,mixed>> $steps @return array<string,mixed> */
    public function present(AiWorkspaceRun $run, array $steps): array
    {
        $content = trim((string) $run->answer);
        $blocks = collect($steps)
            ->map(fn (array $step): ?array => $this->block(is_array($step['result_presentation'] ?? null) ? $step['result_presentation'] : null))
            ->filter()
            ->unique('dedupe_key')
            ->values()
            ->all();
        $sources = $run->artifacts
            ->map(function ($artifact): ?array {
                $url = AiWorkspaceUrlSanitizer::clean($artifact->source_url);
                if ($url === '') {
                    return null;
                }

                return [
                    'label' => $this->plainText($artifact->name, 180),
                    'url' => $url,
                    'dedupe_key' => $this->dedupeKey($artifact->name.'|'.$url),
                ];
            })
            ->filter()
            ->unique('dedupe_key')
            ->values()
            ->all();

        return [
            'version' => 1,
            'format' => 'markdown',
            'content' => $content,
            'detail_level' => $this->detailLevel($run),
            'dedupe_key' => $this->dedupeKey($content),
            'blocks' => $blocks,
            'sources' => $sources,
            'actions' => $this->actions($run),
        ];
    }

    /** @param array<string,mixed>|null $presentation @return array<string,mixed>|null */
    private function block(?array $presentation): ?array
    {
        if ($presentation === null) {
            return null;
        }

        $family = (string) ($presentation['family'] ?? 'generic');
        $type = match ($family) {
            'metrics', 'diagnosis' => 'metrics',
            'catalog', 'opportunities', 'navigation' => 'list',
            'preview', 'receipt' => 'status',
            default => 'status',
        };
        $summary = $this->plainText($presentation['summary'] ?? '', 1000);
        $metrics = collect((array) ($presentation['metrics'] ?? []))->take(12)->values()->all();
        $items = collect((array) ($presentation['items'] ?? []))->take(12)->values()->all();
        if ($summary === '' && $metrics === [] && $items === []) {
            return null;
        }

        return [
            'type' => $type,
            'result_type' => $this->plainText($presentation['type'] ?? 'generic', 60),
            'summary' => $summary,
            'metrics' => $metrics,
            'items' => $items,
            'period' => is_array($presentation['period'] ?? null) ? $presentation['period'] : null,
            'dedupe_key' => $this->dedupeKey(json_encode([
                $summary, $metrics, $items, $presentation['period'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        ];
    }

    private function detailLevel(AiWorkspaceRun $run): string
    {
        if ($run->steps->count() > 1
            || $run->approvals->contains(static fn ($approval): bool => $approval->status === 'pending')
            || in_array((string) $run->state, ['failed', 'partially_completed', 'outcome_unknown'], true)) {
            return 'execution';
        }

        return $run->steps->isNotEmpty() ? 'standard' : 'concise';
    }

    /** @return list<array<string,string>> */
    private function actions(AiWorkspaceRun $run): array
    {
        $actions = [];
        $seenLinks = [];
        foreach ($run->artifacts as $artifact) {
            $url = AiWorkspaceUrlSanitizer::clean($artifact->source_url);
            if ($url === '' || isset($seenLinks[$url])) {
                continue;
            }
            $seenLinks[$url] = true;
            $actions[] = [
                'type' => 'link',
                'url' => $url,
                'label' => $this->plainText($artifact->name ?: 'open_result', 180),
            ];
        }
        foreach ($run->approvals->where('status', 'pending') as $approval) {
            $actions[] = ['type' => 'reject', 'id' => (string) $approval->id, 'label' => 'reject'];
            $actions[] = ['type' => 'approve', 'id' => (string) $approval->id, 'label' => 'approve'];
        }
        foreach ($run->steps->where('state', 'failed') as $step) {
            $actions[] = ['type' => 'retry', 'id' => (string) $step->id, 'label' => 'retry'];
        }

        return $actions;
    }

    private function dedupeKey(mixed $value): string
    {
        return hash('sha256', Str::of((string) $value)->squish()->lower()->toString());
    }

    private function plainText(mixed $value, int $limit): string
    {
        return Str::limit(trim(strip_tags((string) $value)), $limit, '');
    }
}
