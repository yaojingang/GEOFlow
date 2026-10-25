<?php

namespace App\Services\GeoFlow\Distribution\PlatformWeb;

use App\Exceptions\PlatformPublishBlockedException;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Services\BrowserOperations\PublicationPayloadBuilder;
use App\Services\GeoFlow\DistributionPublisherInterface;
use App\Services\GeoFlow\ManualPublicationService;
use RuntimeException;

/**
 * platform_web 渠道发布器：发布动作创建 ManualPublication 工单（status ready），
 * 分发行停驻 awaiting_extension，等待 Chrome 扩展回执（写回见 PlatformWebDistributionBridge）。
 */
class BrowserExtensionPublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly ManualPublicationService $manualPublicationService,
        private readonly PlatformWebDistributionBridge $bridge,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        if (! config('geoflow.platform_web.enabled', true)) {
            throw new RuntimeException('platform_web 分发未启用。');
        }

        $channel = $distribution->channel;
        if (! $channel instanceof DistributionChannel) {
            throw new RuntimeException('platform_web 分发缺少渠道信息。');
        }
        $config = $channel->resolvedPlatformWebConfig();
        $platform = (string) $config['platform'];

        // 严禁重发第二层：同平台任意 platform_web 渠道存在未落定的分发行（synced/awaiting_extension/
        // sending/outcome_unknown）则直接拦截。awaiting_extension 可能持续数小时，必须与 synced 同等对待。
        $syncedExists = ArticleDistribution::query()
            ->where('article_id', (int) $distribution->article_id)
            ->where('action', (string) $distribution->action)
            ->whereIn('status', ['synced', 'awaiting_extension', 'sending', 'outcome_unknown'])
            ->where('id', '!=', (int) $distribution->id)
            ->whereHas('channel', fn ($query) => $query
                ->where('channel_type', DistributionChannel::TYPE_PLATFORM_WEB)
                ->where('channel_config->platform', $platform))
            ->exists();
        $knownWorkOrder = ManualPublication::query()->where('article_id', (int) $distribution->article_id)
            ->where('platform', $platform)->whereNotNull('source_distribution_id')
            ->where('source_distribution_id', '!=', (int) $distribution->id)
            ->tap(fn ($query) => PlatformWebWorkOrderGuard::protectingWorkOrders($query))
            ->exists();
        if ($syncedExists || $knownWorkOrder) {
            throw new PlatformPublishBlockedException(
                '该文章在平台已有工单或执行记录「'.PlatformCatalog::label($platform).'」，同平台严禁重发。'
            );
        }

        PlatformWebWorkOrderGuard::creatorFor($channel);
        $boundAccount = ManualPublicationAccount::query()->with('persona')->find((int) ($config['manual_publication_account_id'] ?? 0));
        if (! $boundAccount || ! $boundAccount->is_active || ! $boundAccount->persona?->is_active || $boundAccount->platform !== $platform) {
            throw new RuntimeException('platform_web 渠道未绑定有效的平台账号。');
        }

        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->first();
        if ($publication instanceof ManualPublication) {
            if (PlatformWebWorkOrderGuard::canRefresh($publication)) {
                $snapshot = $payload['article'] ?? null;
                if (! is_array($snapshot) || (int) $publication->article_id !== (int) $distribution->article_id || $publication->platform !== $platform) {
                    throw new RuntimeException('platform_web_work_order_binding_changed');
                }
                $content = PlatformWebContentFormatter::fromSnapshot($snapshot);
                $publication->forceFill([
                    'content' => $content,
                    'source_snapshot' => $snapshot,
                    'publication_payload' => app(PublicationPayloadBuilder::class)->build([
                        'type' => 'post', 'platform' => $platform, 'content' => $content, 'source_snapshot' => $snapshot,
                        'target_url' => $publication->target_url, 'disclosure_snapshot' => $publication->disclosure_snapshot,
                        'publication_payload_extras' => $this->bridge->payloadExtras($distribution, $config, $payload),
                    ]),
                    'revision' => (int) $publication->revision + ($publication->status === ManualPublication::STATUS_READY ? 1 : 0),
                ])->save();
            }

            if ((string) $publication->status === ManualPublication::STATUS_COMPLETED) {
                return [
                    'remote_id' => (string) $publication->getKey(),
                    'remote_url' => $publication->completion_url,
                    'remote_meta' => [
                        'execution_mode' => 'draft',
                        'work_order_status' => ManualPublication::STATUS_COMPLETED,
                    ],
                ];
            }
            if (in_array((string) $publication->status, [
                ManualPublication::STATUS_READY,
                ManualPublication::STATUS_IN_PROGRESS,
                ManualPublication::STATUS_OUTCOME_UNKNOWN,
            ], true)) {
                return $this->pendingResponse($publication);
            }
            // 重试路径：failed/skipped/cancelled 重新打开工单（extras 由 transition 从既有 payload 回填）。
            // draft 理论上不会由本发布器产生，兜底同样走转 ready，避免重复建单。
            if (in_array((string) $publication->status, [
                ManualPublication::STATUS_DRAFT,
                ManualPublication::STATUS_FAILED,
                ManualPublication::STATUS_SKIPPED,
                ManualPublication::STATUS_CANCELLED,
            ], true)) {
                if (! PlatformWebWorkOrderGuard::canRefresh($publication)) {
                    throw new RuntimeException('platform_web_claimed_work_order_requires_reconciliation');
                }
                $publication = $this->manualPublicationService->transition(
                    $publication,
                    ManualPublication::STATUS_READY,
                    (int) $publication->revision,
                    PlatformWebWorkOrderGuard::creatorFor($channel),
                    fromDistribution: true,
                );

                return $this->pendingResponse($publication);
            }
        }

        // 无工单 → 创建 ready 工单并绑定分发行。
        $actor = PlatformWebWorkOrderGuard::creatorFor($channel);
        $accountId = $config['manual_publication_account_id'];
        $account = $accountId === null
            ? null
            : ManualPublicationAccount::query()->find((int) $accountId);
        if (! $account instanceof ManualPublicationAccount || ! $account->is_active || ! $account->persona?->is_active || $account->platform !== $platform) {
            throw new RuntimeException('platform_web 渠道未绑定有效的平台账号。');
        }
        $article = $distribution->article()->withTrashed()->firstOrFail();
        $snapshot = $payload['article'] ?? null;
        if (! is_array($snapshot) || ! is_string($snapshot['content'] ?? null)) {
            throw new RuntimeException('platform_web 缺少不可变文章载荷。');
        }
        $publication = $this->manualPublicationService->create([
            'type' => ManualPublication::TYPE_POST,
            'platform' => $platform,
            'article_id' => (int) $article->getKey(),
            'persona_id' => (int) $account->persona_id,
            'account_id' => (int) $account->getKey(),
            // create() 对 status=ready 强制要求指派管理员，这里指派给渠道创建管理员。
            'assigned_admin_id' => (int) $actor->getKey(),
            'status' => ManualPublication::STATUS_READY,
            'content' => PlatformWebContentFormatter::fromSnapshot($snapshot),
            'target_url' => (string) $config['editor_url'],
            'publication_payload_extras' => $this->bridge->payloadExtras($distribution, $config, $payload),
        ], $actor);
        $publication->forceFill([
            'source_distribution_id' => (int) $distribution->id,
            'source_snapshot' => $snapshot,
            'publication_payload' => array_replace((array) $publication->publication_payload, ['title' => (string) ($snapshot['title'] ?? '')]),
        ])->save();

        return $this->pendingResponse($publication);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(ArticleDistribution $distribution, array $payload): array
    {
        return [
            'supported' => false,
            'remote_meta' => ['reason' => 'platform_web_phase1_no_update'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(ArticleDistribution $distribution): array
    {
        return [
            'supported' => false,
            'remote_meta' => ['reason' => 'platform_web_phase1_no_delete'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncSiteSettings(DistributionChannel $channel, ?string $idempotencyKey = null, ?array $settings = null): array
    {
        return [
            'synced' => false,
            'settings_version' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function health(DistributionChannel $channel): array
    {
        $config = $channel->resolvedPlatformWebConfig();
        $accountId = $config['manual_publication_account_id'];
        $account = $accountId === null
            ? null
            : ManualPublicationAccount::query()->find((int) $accountId);
        $healthy = $account instanceof ManualPublicationAccount && (bool) $account->is_active;

        return [
            'healthy' => $healthy,
            'status' => $healthy ? 'ok' : 'account_missing',
            'account_id' => $accountId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingResponse(ManualPublication $publication): array
    {
        return [
            'remote_id' => (string) $publication->getKey(),
            'remote_url' => null,
            'remote_meta' => [
                'pending_extension' => true,
                'platform_web_work_order_digest' => PlatformWebWorkOrderGuard::digest($publication),
                'execution_mode' => 'draft',
                'work_order_status' => (string) $publication->status,
            ],
        ];
    }
}
