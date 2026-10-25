<?php

namespace App\Services\GeoFlow\Distribution\PlatformWeb;

use App\Ai\Workspace\AiPayloadDigest;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\ManualPublication;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Database\Eloquent\Builder;

/** Immutable source binding and claim authorization. Receipts preserve already-observed external facts. */
final class PlatformWebWorkOrderGuard
{
    /** New execution requires the same current creator authority as work-order creation. */
    public static function creatorFor(DistributionChannel $channel): Admin
    {
        $creator = Admin::query()->find((int) $channel->created_by_admin_id);
        if (! $creator || $creator->status !== 'active' || ! $creator->isSuperAdmin()) {
            throw new ApiException('publication_unavailable', 'platform_web 渠道创建管理员必须保持启用的超级管理员身份。', 409);
        }

        return $creator;
    }

    public static function digest(ManualPublication $publication): string
    {
        return AiPayloadDigest::make($publication->only([
            'article_id', 'source_distribution_id', 'platform', 'persona_id', 'account_id',
            'target_url', 'content', 'source_snapshot', 'publication_payload',
        ]));
    }

    /** Same-source failed attempts may queue, but the publisher still requires explicit confirmation to reopen. */
    public static function protectingWorkOrders(Builder $query, ?int $sameSourceId = null): Builder
    {
        return $query->where(function (Builder $protected) use ($sameSourceId): void {
            $protected->whereIn('status', ['ready', 'in_progress', 'completed', 'outcome_unknown'])
                ->orWhere('execution_receipt->outcome', 'completed')
                ->orWhereHas('transitions', fn (Builder $transitions) => $transitions->where('to_status', 'completed'))
                ->orWhere(function (Builder $attempted) use ($sameSourceId): void {
                    if ($sameSourceId !== null) {
                        $attempted->where('source_distribution_id', '!=', $sameSourceId);
                    }
                    $attempted->where(fn (Builder $history) => $history->whereNotNull('execution_receipt')
                        ->orWhereNotNull('browser_claimed_at')
                        ->orWhereHas('transitions', fn (Builder $transitions) => $transitions->where('to_status', 'in_progress')));
                });
        });
    }

    public static function canRefresh(ManualPublication $publication): bool
    {
        return in_array((string) $publication->status, ['ready', 'failed', 'skipped', 'cancelled', 'draft'], true)
            && $publication->browser_claimed_at === null && $publication->execution_receipt === null
            && ! $publication->transitions()->where('to_status', ManualPublication::STATUS_IN_PROGRESS)->exists();
    }

    /** Caller holds channel, task, article, distribution, then publication locks. */
    public function assertExecutable(ManualPublication $publication): void
    {
        if ($publication->source_distribution_id === null) {
            return;
        }
        $distribution = $this->assertBoundSnapshot($publication);
        if (! app(DistributionOrchestrator::class)->platformWorkOrderExecutable($distribution, $distribution->article)) {
            throw new ApiException('publication_source_superseded', '分发快照或发布授权已变化，请通过源分发重新入队。', 409);
        }
    }

    /** Validate immutable identity before a separately authorized source refresh. Caller holds the same locks. */
    public function assertBoundSnapshot(ManualPublication $publication): ArticleDistribution
    {
        $publication->load(['account.persona', 'persona']);
        $distribution = ArticleDistribution::query()->with(['channel', 'article.task'])->find($publication->source_distribution_id);
        $article = $distribution?->article;
        $channel = $distribution?->channel;
        $payload = data_get($distribution?->remote_meta, 'distribution_payload');
        if (! $distribution || ! $article || ! $channel?->isPlatformWeb() || $channel->status !== 'active'
            || (int) $publication->article_id !== (int) $article->id
            || $publication->platform !== $channel->resolvedPlatformWebConfig()['platform']
            || ! is_array($payload)
            || ! hash_equals((string) $distribution->payload_hash, AiPayloadDigest::make($payload))
            || ! hash_equals((string) data_get($distribution->remote_meta, 'platform_web_work_order_digest'), self::digest($publication))
            || $publication->content !== PlatformWebContentFormatter::fromSnapshot((array) $publication->source_snapshot)
            || data_get($publication->publication_payload, 'body_plain') !== $publication->content
            || ! $publication->account?->is_active || ! $publication->persona?->is_active
            || $publication->account->platform !== $publication->platform
            || (int) $publication->account->persona_id !== (int) $publication->persona_id) {
            throw new ApiException('publication_source_superseded', '分发快照或发布授权已变化，请通过源分发重新入队。', 409);
        }

        self::creatorFor($channel);

        return $distribution;
    }
}
