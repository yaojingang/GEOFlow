<?php

namespace App\Services\GeoFlow\Distribution\PlatformWeb;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionLog;
use App\Models\ManualPublication;
use App\Models\ManualPublicationTransition;
use App\Services\Site\SiteUrlGenerator;
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
     * 防陈旧回执：工单非终态（例如重开后的 ready）时直接忽略；分发行仅在
     * awaiting_extension/sending/outcome_unknown 时接受写回，避免覆盖 synced/failed 等已定状态。
     */
    public function handleReceipt(ManualPublication $publication): void
    {
        $sourceDistributionId = $publication->source_distribution_id;
        if ($sourceDistributionId === null) {
            return;
        }

        $workOrderStatus = (string) $publication->status;
        if (! in_array($workOrderStatus, [
            ManualPublication::STATUS_COMPLETED,
            ManualPublication::STATUS_FAILED,
            ManualPublication::STATUS_SKIPPED,
            ManualPublication::STATUS_CANCELLED,
            ManualPublication::STATUS_OUTCOME_UNKNOWN,
        ], true)) {
            return;
        }

        DB::transaction(function () use ($sourceDistributionId, $publication, $workOrderStatus): void {
            $distribution = ArticleDistribution::query()
                ->whereKey((int) $sourceDistributionId)
                ->lockForUpdate()
                ->first();
            if (! $distribution instanceof ArticleDistribution
                || ! in_array((string) $distribution->status, ['awaiting_extension', 'sending', 'outcome_unknown'], true)) {
                return;
            }

            $distributionStatus = match ($workOrderStatus) {
                ManualPublication::STATUS_COMPLETED => 'synced',
                ManualPublication::STATUS_OUTCOME_UNKNOWN => 'outcome_unknown',
                default => 'failed',
            };

            $receipt = is_array($publication->execution_receipt) ? $publication->execution_receipt : [];
            $errorCode = trim((string) ($receipt['error_code'] ?? ''));
            $resultNote = trim((string) ($publication->result_note ?? ''));
            $lastErrorMessage = $distributionStatus === 'synced'
                ? null
                : ($errorCode !== ''
                    ? $errorCode
                    : ($resultNote !== '' ? $resultNote : 'extension_reported_failure'));

            $distribution->forceFill([
                'status' => $distributionStatus,
                'remote_id' => (string) $publication->getKey(),
                'remote_url' => $distributionStatus === 'synced' ? $publication->completion_url : null,
                'last_attempt_at' => now(),
                'last_error_message' => $lastErrorMessage,
            ])->save();

            DistributionLog::query()->create([
                'distribution_channel_id' => (int) $distribution->distribution_channel_id,
                'article_distribution_id' => (int) $distribution->id,
                'article_id' => (int) $distribution->article_id,
                'level' => $distributionStatus === 'synced' ? 'info' : 'error',
                'event' => 'platform_web_receipt',
                'message' => '扩展发布工单 #'.$publication->getKey().' 已回执：'.$workOrderStatus,
                'context' => [
                    'work_order_status' => $workOrderStatus,
                    'completion_url' => $publication->completion_url,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * 分发行被取消/删除时联动取消挂起的扩展发布工单。
     *
     * 工单状态可转到 cancelled（draft/ready/in_progress）时直接取消并留痕；
     * 其余状态（failed/skipped/cancelled/completed/outcome_unknown）不可达 cancelled，
     * 仅在 result_note 为空时补记取消说明。
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
        ManualPublication::query()
            ->whereIn('source_distribution_id', $distributionIds)
            ->orderBy('id')
            ->chunkById(100, function ($publications) use ($note, &$cancelled): void {
                foreach ($publications as $publication) {
                    $fromStatus = (string) $publication->status;
                    if (! in_array($fromStatus, [
                        ManualPublication::STATUS_DRAFT,
                        ManualPublication::STATUS_READY,
                        ManualPublication::STATUS_IN_PROGRESS,
                    ], true)) {
                        if (trim((string) $publication->result_note) === '') {
                            $publication->forceFill(['result_note' => $note])->save();
                        }

                        continue;
                    }
                    $publication->forceFill([
                        'status' => ManualPublication::STATUS_CANCELLED,
                        'status_changed_at' => now(),
                        'result_note' => $note,
                    ])->save();
                    ManualPublicationTransition::query()->create([
                        'manual_publication_id' => (int) $publication->getKey(),
                        'changed_by_admin_id' => null,
                        'from_status' => $fromStatus,
                        'to_status' => ManualPublication::STATUS_CANCELLED,
                        'completion_url' => null,
                        'result_note' => $note,
                        'created_at' => now(),
                    ]);
                    $cancelled++;
                }
            });

        return $cancelled;
    }

    /**
     * 工单 payload 的 transient extras（经 publication_payload_extras 进入构建结果，不落库）。
     *
     * @param  array<string, mixed>  $config  resolvedPlatformWebConfig()
     * @return array{images: list<mixed>, append_source_link: bool, source_url: ?string}
     */
    public function payloadExtras(ArticleDistribution $distribution, array $config): array
    {
        $appendSourceLink = (bool) ($config['append_source_link'] ?? false);
        $sourceUrl = null;
        if ($appendSourceLink) {
            $article = $distribution->article()->withTrashed()->first();
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
