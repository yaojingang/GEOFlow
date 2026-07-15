<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleRiskGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleRiskScan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticleRiskGate
{
    public function __construct(private ArticleRiskScanner $scanner) {}

    public function check(
        Article $article,
        string $trigger,
        ?int $adminId = null,
        ?string $overrideReason = null,
        bool $allowExistingOverride = true,
    ): ArticleRiskScan {
        $result = DB::transaction(function () use ($article, $trigger, $adminId, $overrideReason, $allowExistingOverride): ArticleRiskScan|ArticleRiskGateException {
            $lockedArticle = Article::query()
                ->whereKey($article->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $scan = $lockedArticle->latestRiskScan()->first();

            if ($scan === null || ! $this->scanner->isFresh($lockedArticle, $scan)) {
                $scan = $this->scanner->record($lockedArticle, $trigger, $adminId);
            }

            $lockedArticle->setRelation('latestRiskScan', $scan);

            if ($scan->status === 'clean') {
                return $scan;
            }

            if ($allowExistingOverride && $scan->status === 'warning' && $scan->is_overridden) {
                return $scan;
            }

            $reason = $this->normalizeOverrideReason($overrideReason);
            $admin = $adminId === null ? null : Admin::query()->find($adminId);

            if (
                $allowExistingOverride
                && $scan->status === 'warning'
                && $reason !== ''
                && $admin !== null
            ) {
                ArticleRiskScan::query()
                    ->whereKey($scan->getKey())
                    ->where('is_overridden', false)
                    ->update([
                        'is_overridden' => true,
                        'override_reason' => $reason,
                        'overridden_by_admin_id' => $admin->getKey(),
                        'overridden_by_username' => $admin->username,
                        'overridden_at' => now(),
                    ]);

                $latestScan = $lockedArticle->latestRiskScan()->first();

                if (
                    $latestScan === null
                    || ! $latestScan->is($scan)
                    || ! $this->scanner->isFresh($lockedArticle, $latestScan)
                    || ! $latestScan->is_overridden
                ) {
                    throw new ArticleRiskGateException($scan);
                }

                $lockedArticle->setRelation('latestRiskScan', $latestScan);

                return $latestScan;
            }

            return new ArticleRiskGateException($scan);
        });

        if ($result instanceof ArticleRiskGateException) {
            throw $result;
        }

        return $result;
    }

    private function normalizeOverrideReason(?string $reason): string
    {
        $reason = $reason ?? '';
        if (class_exists(\Normalizer::class)) {
            $reason = \Normalizer::normalize($reason, \Normalizer::FORM_KC) ?: $reason;
        }
        $reason = preg_replace('/[\p{Default_Ignorable_Code_Point}\p{Cc}\p{Cf}]+/u', '', $reason) ?? $reason;
        $reason = Str::squish($reason);

        return preg_match('/[\p{L}\p{N}\p{P}\p{S}]/u', $reason) === 1 ? $reason : '';
    }
}
