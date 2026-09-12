<?php

namespace App\Services\GeoFlow\Distribution\PlatformWeb;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionLog;
use App\Models\ManualPublication;
use App\Services\Site\SiteUrlGenerator;

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
     */
    public function handleReceipt(ManualPublication $publication): void
    {
        $sourceDistributionId = $publication->source_distribution_id;
        if ($sourceDistributionId === null) {
            return;
        }
        $distribution = ArticleDistribution::query()->find((int) $sourceDistributionId);
        if (! $distribution instanceof ArticleDistribution) {
            return;
        }

        $workOrderStatus = (string) $publication->status;
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
