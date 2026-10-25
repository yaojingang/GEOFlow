<?php

namespace App\Services\GeoFlow\Distribution\PlatformWeb;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Models\ManualPublication;
use App\Models\ManualPublicationTransition;
use App\Services\Site\SiteUrlGenerator;
use App\Services\SystemUpdater\RecoveryState;
use Illuminate\Support\Facades\DB;

/**
 * platform_web 渠道与发布工单（ManualPublication）之间的桥接：
 * - 发布前组装工单 payload 的 transient extras（来源链接等）；
 * - 扩展回执落地后把工单终态写回 article_distributions。
 */
class PlatformWebDistributionBridge
{
    public function __construct(private readonly SiteUrlGenerator $siteUrlGenerator) {}

    /**
     * 扩展回执写回：工单终态 → 分发行状态。
     *
     * completed → synced；outcome_unknown → outcome_unknown；其余（failed/skipped/cancelled）→ failed。
     * 防陈旧回执：事务内加锁重读工单最新状态（调用方快照可能过期），非终态（如重开后的 ready）
     * 直接忽略。已终止的分发行仅补存完成事实及远端身份，保留终止状态。
     */
    public function handleReceipt(ManualPublication $publication): void
    {
        $sourceDistributionId = $publication->source_distribution_id;
        if ($sourceDistributionId === null) {
            return;
        }

        DB::transaction(function () use ($sourceDistributionId, $publication): void {
            $candidate = ArticleDistribution::query()->find((int) $sourceDistributionId);
            if (! $candidate) {
                return;
            }
            $channel = DistributionChannel::query()->whereKey($candidate->distribution_channel_id)->lockForUpdate()->first();
            if (! $channel) {
                return;
            }
            $distribution = ArticleDistribution::query()->whereKey((int) $sourceDistributionId)->lockForUpdate()->first();
            if (! $distribution || ! in_array((string) $distribution->status, ['awaiting_extension', 'sending', 'outcome_unknown', 'failed', 'cancelled'], true)) {
                return;
            }
            $current = ManualPublication::query()->whereKey($publication->id)->lockForUpdate()->first();
            if (! $current || (int) $current->source_distribution_id !== (int) $distribution->id
                || ! in_array((string) $current->status, [
                    ManualPublication::STATUS_COMPLETED, ManualPublication::STATUS_FAILED, ManualPublication::STATUS_SKIPPED,
                    ManualPublication::STATUS_CANCELLED, ManualPublication::STATUS_OUTCOME_UNKNOWN,
                ], true)) {
                return;
            }
            $stopped = $channel->status === DistributionChannel::STATUS_DELETING
                || in_array((string) $distribution->status, ['failed', 'cancelled'], true);
            if ($stopped && $current->status !== ManualPublication::STATUS_COMPLETED) {
                return;
            }
            $meta = (array) $distribution->remote_meta;
            if ((int) ($meta['platform_web_receipt_revision'] ?? 0) >= (int) $current->revision) {
                return;
            }
            $status = match ((string) $current->status) {
                ManualPublication::STATUS_COMPLETED => 'synced',
                ManualPublication::STATUS_OUTCOME_UNKNOWN => 'outcome_unknown',
                default => 'failed',
            };
            $status = $stopped ? (string) $distribution->status : $status;
            $receipt = (array) $current->execution_receipt;
            $error = trim((string) ($receipt['error_code'] ?? $current->result_note ?? '')) ?: 'extension_reported_failure';
            $meta['platform_web_receipt_revision'] = (int) $current->revision;
            $meta['work_order_status'] = (string) $current->status;
            $meta['pending_extension'] = false;
            $distribution->forceFill([
                'status' => $status,
                'remote_id' => (string) $current->id,
                'remote_url' => $current->completion_url ?: $distribution->remote_url,
                'remote_meta' => $meta,
                'next_retry_at' => null,
                'last_attempt_at' => now(),
                'last_error_message' => $stopped ? $distribution->last_error_message : ($status === 'synced' ? null : $error),
            ])->save();
            DistributionLog::query()->create([
                'distribution_channel_id' => (int) $distribution->distribution_channel_id,
                'article_distribution_id' => (int) $distribution->id,
                'article_id' => (int) $distribution->article_id,
                'level' => $status === 'synced' ? 'info' : 'error',
                'event' => 'platform_web_receipt',
                'message' => '人工发布工单 #'.$current->id.' 已回执：'.$current->status,
                'context' => ['work_order_status' => $current->status, 'receipt_revision' => $current->revision, 'completion_url' => $current->completion_url],
                'created_at' => now(),
            ]);
        });
    }

    public function reconcile(int $limit = 100): int
    {
        $revisionColumn = DB::connection()->getQueryGrammar()->wrap('article_distributions.remote_meta->platform_web_receipt_revision');
        $integerCast = DB::getDriverName() === 'mysql' ? 'UNSIGNED' : 'INTEGER';
        $publications = ManualPublication::query()->select('manual_publications.*')
            ->join('article_distributions', 'article_distributions.id', '=', 'manual_publications.source_distribution_id')
            ->join('distribution_channels', 'distribution_channels.id', '=', 'article_distributions.distribution_channel_id')
            ->where(fn ($query) => $query->where('distribution_channels.status', '!=', DistributionChannel::STATUS_DELETING)
                ->orWhere('manual_publications.status', ManualPublication::STATUS_COMPLETED))
            ->where(fn ($query) => $query->whereIn('article_distributions.status', ['awaiting_extension', 'sending', 'outcome_unknown'])
                ->orWhere(fn ($stopped) => $stopped->whereIn('article_distributions.status', ['failed', 'cancelled'])->where('manual_publications.status', ManualPublication::STATUS_COMPLETED)))
            ->whereIn('manual_publications.status', [ManualPublication::STATUS_COMPLETED, ManualPublication::STATUS_FAILED,
                ManualPublication::STATUS_SKIPPED, ManualPublication::STATUS_CANCELLED, ManualPublication::STATUS_OUTCOME_UNKNOWN])
            ->where(fn ($q) => $q->whereNull('article_distributions.remote_meta->platform_web_receipt_revision')
                ->orWhereRaw('manual_publications.revision > CAST('.$revisionColumn.' AS '.$integerCast.')'))
            ->orderBy('manual_publications.id')->limit(max(1, min(500, $limit)))->get();
        foreach ($publications as $publication) {
            app(RecoveryState::class)->assertBackgroundReady();
            $this->handleReceipt($publication);
        }

        return $publications->count();
    }

    /**
     * 分发行被取消/删除时联动取消挂起的扩展发布工单。
     *
     * 未领取工单取消，已领取工单标记结果待核验；清理领取并推进版本。
     * 调用方已持有渠道或任务锁，再按分发、工单顺序加锁，既有终态保持不变。
     */
    public function cancelWorkOrdersForDistributions(array $distributionIds, string $note): int
    {
        $distributionIds = array_values(array_filter(
            array_map(static fn ($id): int => (int) $id, $distributionIds),
            static fn (int $id): bool => $id > 0,
        ));
        if ($distributionIds === []) {
            return 0;
        }

        $cancelled = 0;
        foreach (ArticleDistribution::query()->whereIn('id', $distributionIds)->orderBy('id')->lockForUpdate()->get() as $distribution) {
            foreach (ManualPublication::query()->where('source_distribution_id', $distribution->id)->orderBy('id')->lockForUpdate()->get() as $publication) {
                $fromStatus = (string) $publication->status;
                if (! in_array($fromStatus, [ManualPublication::STATUS_DRAFT, ManualPublication::STATUS_READY, ManualPublication::STATUS_IN_PROGRESS], true)) {
                    continue;
                }
                $status = $fromStatus === ManualPublication::STATUS_IN_PROGRESS
                    ? ManualPublication::STATUS_OUTCOME_UNKNOWN : ManualPublication::STATUS_CANCELLED;
                $publication->forceFill([
                    'status' => $status, 'status_changed_at' => now(), 'result_note' => $note,
                    'revision' => (int) $publication->revision + 1,
                    'browser_claimed_by_token_id' => null, 'browser_claimed_at' => null, 'browser_last_seen_at' => null,
                ])->save();
                if ($status === ManualPublication::STATUS_OUTCOME_UNKNOWN) {
                    $distribution->forceFill(['status' => 'outcome_unknown', 'next_retry_at' => null, 'last_error_message' => $note])->save();
                }
                ManualPublicationTransition::query()->create([
                    'manual_publication_id' => (int) $publication->id, 'changed_by_admin_id' => null,
                    'from_status' => $fromStatus, 'to_status' => $status,
                    'completion_url' => $publication->completion_url, 'result_note' => $note, 'created_at' => now(),
                ]);
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * 工单 payload 的 transient extras（经 publication_payload_extras 进入构建结果，不落库）。
     *
     * @param  array<string, mixed>  $config  resolvedPlatformWebConfig()
     * @return array{images: list<mixed>, append_source_link: bool, source_url: ?string}
     */
    public function payloadExtras(ArticleDistribution $distribution, array $config, array $payload = []): array
    {
        $appendSourceLink = (bool) ($config['append_source_link'] ?? false);
        $sourceUrl = null;
        if ($appendSourceLink) {
            $snapshot = $payload['article'] ?? null;
            $article = is_array($snapshot) ? (new Article)->forceFill($snapshot) : null;
            if ($article instanceof Article && trim((string) $article->slug) !== '') {
                $sourceUrl = $this->siteUrlGenerator->article($article);
            }
        }

        return [
            'append_source_link' => $appendSourceLink,
            'source_url' => $sourceUrl,
            'images' => [],
        ];
    }
}
