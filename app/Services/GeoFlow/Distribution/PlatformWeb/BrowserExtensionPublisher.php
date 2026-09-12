<?php

namespace App\Services\GeoFlow\Distribution\PlatformWeb;

use App\Exceptions\PlatformPublishBlockedException;
use App\Models\Admin;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
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
        if ($syncedExists) {
            throw new PlatformPublishBlockedException(
                '该文章已成功发布到平台「'.PlatformCatalog::label($platform).'」，同平台严禁重发。'
            );
        }

        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->first();
        if ($publication instanceof ManualPublication) {
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
                $publication = $this->manualPublicationService->transition(
                    $publication,
                    ManualPublication::STATUS_READY,
                    (int) $publication->revision,
                    $this->actorFor($channel),
                );

                return $this->pendingResponse($publication);
            }
        }

        // 无工单 → 创建 ready 工单并绑定分发行。
        $actor = $this->actorFor($channel);
        $accountId = $config['manual_publication_account_id'];
        $account = $accountId === null
            ? null
            : ManualPublicationAccount::query()->find((int) $accountId);
        if (! $account instanceof ManualPublicationAccount) {
            throw new RuntimeException('platform_web 渠道未绑定有效的平台账号。');
        }
        $article = $distribution->article()->withTrashed()->firstOrFail();
        $publication = $this->manualPublicationService->create([
            'type' => ManualPublication::TYPE_POST,
            'platform' => $platform,
            'article_id' => (int) $article->getKey(),
            'persona_id' => (int) $account->persona_id,
            'account_id' => (int) $account->getKey(),
            // create() 对 status=ready 强制要求指派管理员，这里指派给渠道创建管理员。
            'assigned_admin_id' => (int) $actor->getKey(),
            'status' => ManualPublication::STATUS_READY,
            'content' => PlatformWebContentFormatter::plainText((string) $article->content),
            'target_url' => (string) $config['editor_url'],
            'publication_payload_extras' => $this->bridge->payloadExtras($distribution, $config),
        ], $actor);
        $publication->forceFill(['source_distribution_id' => (int) $distribution->id])->save();

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
                'execution_mode' => 'draft',
                'work_order_status' => (string) $publication->status,
            ],
        ];
    }

    private function actorFor(DistributionChannel $channel): Admin
    {
        $actor = Admin::query()->find((int) $channel->created_by_admin_id);
        if (! $actor instanceof Admin) {
            throw new RuntimeException('platform_web 渠道缺少有效的创建管理员，无法创建发布工单。');
        }
        // 工单重开（failed→ready）走 ManualPublicationPolicy::reopen，要求超级管理员；创建工单的
        // assigned_admin_id 也指向该管理员。此处前置校验，避免到重试路径才失败。
        if (! $actor->isSuperAdmin()) {
            throw new RuntimeException('platform_web 渠道创建管理员必须为超级管理员。');
        }

        return $actor;
    }
}
