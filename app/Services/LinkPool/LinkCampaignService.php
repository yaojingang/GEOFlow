<?php

namespace App\Services\LinkPool;

use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionChannelOperationLeaseService;
use App\Services\Site\LinkPoolSiteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LinkCampaignService
{
    public function __construct(
        private readonly LinkPoolSiteService $sites,
        private readonly DistributionChannelOperationLeaseService $leases,
    ) {}

    /** @param array<string,mixed> $input */
    public function saveDraft(?int $campaignId, array $input, int $adminId): int
    {
        $siteKey = (string) $input['source_site_key'];
        $payload = $this->normalize($input);

        return $this->withSiteLease($siteKey, 'link_pool_draft', function () use ($campaignId, $siteKey, $payload, $adminId): int {
            return DB::transaction(function () use ($campaignId, $siteKey, $payload, $adminId): int {
                if ($campaignId === null) {
                    $campaignId = DB::table('link_campaigns')->insertGetId([
                        'source_site_key' => $siteKey, 'name' => $payload['name'], 'mode' => $payload['mode'],
                        'priority' => 0, 'limit' => $payload['limit'], 'status' => 'draft',
                        'source_rules_json' => json_encode(['scope' => 'all']), 'exclusions_json' => '[]',
                        'revision' => 0, 'created_by' => $adminId, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $revision = 0;
                } else {
                    $campaign = DB::table('link_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
                    if ($campaign === null || $campaign->source_site_key !== $siteKey) {
                        throw ValidationException::withMessages(['source_site_key' => '活动来源站点不能变更。']);
                    }
                    $revision = (int) $campaign->revision;
                    if ((int) ($inputRevision = $payload['base_revision']) !== $revision) {
                        throw ValidationException::withMessages(['base_revision' => '活动已由其他人发布，请重新载入后编辑。']);
                    }
                    $priorDraft = DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->first();
                    $priorReasons = $priorDraft
                        ? (json_decode((string) $priorDraft->payload_json, true)['review_reasons'] ?? [])
                        : DB::table('link_campaign_targets')->where('campaign_id', $campaignId)
                            ->pluck('editorial_reason', 'target_id')->all();
                    $payload['approved_cross_site_ids'] = array_values(array_filter(
                        $payload['approved_cross_site_ids'],
                        static function (int $id) use ($payload, $priorReasons): bool {
                            return ! array_key_exists($id, $priorReasons)
                                || trim((string) $priorReasons[$id]) === trim((string) ($payload['review_reasons'][$id] ?? ''));
                        },
                    ));
                }
                DB::table('link_campaign_drafts')->updateOrInsert(['campaign_id' => $campaignId], [
                    'base_revision' => $revision, 'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'updated_by' => $adminId, 'created_at' => now(), 'updated_at' => now(),
                ]);

                return $campaignId;
            }, 3);
        });
    }

    public function publish(int $campaignId, int $adminId): void
    {
        $campaign = DB::table('link_campaigns')->find($campaignId);
        if ($campaign === null) {
            throw ValidationException::withMessages(['campaign' => '活动不存在。']);
        }
        $siteKey = (string) $campaign->source_site_key;
        $this->withSiteLease($siteKey, 'link_pool_publish', function () use ($campaignId, $siteKey, $adminId): void {
            $draftBeforeLock = DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->first();
            if ($draftBeforeLock === null) {
                throw ValidationException::withMessages(['campaign' => '没有可发布的草稿。']);
            }
            $payloadBeforeLock = json_decode((string) $draftBeforeLock->payload_json, true) ?: [];
            $targetSites = DB::table('link_pool_targets')->whereIn('id', $payloadBeforeLock['target_ids'] ?? [])
                ->pluck('target_site_key')->unique()->filter(fn ($key) => $key !== $siteKey && $key !== 'external')->sort()->values()->all();
            $this->withTargetLeases($targetSites, function () use ($campaignId, $siteKey, $adminId, $draftBeforeLock): void {
                DB::transaction(function () use ($campaignId, $siteKey, $adminId, $draftBeforeLock): void {
                    $campaign = DB::table('link_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
                    $draft = DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->first();
                    if ($campaign === null || $draft === null || (int) $campaign->revision !== (int) $draft->base_revision
                        || $draft->payload_json !== $draftBeforeLock->payload_json) {
                        throw ValidationException::withMessages(['campaign' => '发布冲突：活动版本已变化，请重新载入草稿。']);
                    }
                    if (! $this->sites->canPublish($siteKey) || ! $this->sites->settings($siteKey)['enabled']
                        || ! $this->sites->themeSupportsRecommendations($siteKey) || $this->sites->emergencyDisabled()) {
                        throw ValidationException::withMessages(['campaign' => '站点当前不可公开投放，请检查服务、索引与站点开关。']);
                    }
                    $payload = json_decode((string) $draft->payload_json, true);
                    if (! is_array($payload)) {
                        throw ValidationException::withMessages(['campaign' => '草稿格式错误。']);
                    }
                    $payload = $this->normalize($payload);
                    $targetIds = $payload['target_ids'];
                    $targets = DB::table('link_pool_targets')->whereIn('id', $targetIds)
                        ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    if (count($targetIds) !== $targets->count()) {
                        throw ValidationException::withMessages(['target_ids' => '部分目标已经删除，请重新选择。']);
                    }
                    foreach ($targets as $target) {
                        $external = LinkPoolTargetService::isExternal($target);
                        if (! app(LinkPoolTargetService::class)->eligible($target)) {
                            throw ValidationException::withMessages(['target_ids' => '目标已失效或站点不再公开：'.$target->display_title]);
                        }
                        if (! $external && $target->target_site_key !== $siteKey && ($payload['mode'] !== 'manual'
                            || ! in_array((int) $target->id, $payload['approved_cross_site_ids'], true)
                            || trim((string) ($payload['review_reasons'][$target->id] ?? '')) === '')) {
                            throw ValidationException::withMessages(['target_ids' => '跨站目标只能人工选择，并须逐条填写理由与审核。']);
                        }
                    }
                    if ($payload['scope'] === 'articles') {
                        foreach ($payload['source_article_ids'] as $id) {
                            if ($this->sites->article($siteKey, $id) === null) {
                                throw ValidationException::withMessages(['source_article_ids' => '来源文章已撤回或不属于此站。']);
                            }
                        }
                    }
                    DB::table('link_campaigns')->where('id', $campaignId)->update([
                        'name' => $payload['name'], 'mode' => $payload['mode'], 'priority' => $payload['priority'],
                        'limit' => $payload['limit'], 'starts_at' => $payload['starts_at'], 'ends_at' => $payload['ends_at'],
                        'status' => 'active', 'source_rules_json' => json_encode([
                            'scope' => $payload['scope'], 'category_id' => $payload['category_id'], 'keyword' => $payload['keyword'],
                        ], JSON_UNESCAPED_UNICODE),
                        'exclusions_json' => json_encode($payload['exclusion_ids']),
                        'revision' => (int) $campaign->revision + 1, 'updated_at' => now(),
                    ]);
                    DB::table('link_campaign_sources')->where('campaign_id', $campaignId)->delete();
                    foreach ($payload['source_article_ids'] as $id) {
                        DB::table('link_campaign_sources')->insert(['campaign_id' => $campaignId, 'source_article_id' => $id]);
                    }
                    DB::table('link_campaign_targets')->where('campaign_id', $campaignId)->delete();
                    foreach ($targetIds as $position => $id) {
                        $cross = ! LinkPoolTargetService::isExternal($targets[$id]) && $targets[$id]->target_site_key !== $siteKey;
                        DB::table('link_campaign_targets')->insert([
                            'campaign_id' => $campaignId, 'target_id' => $id,
                            'weight' => $payload['weights'][$id] ?? 1,
                            'pin_order' => in_array($id, $payload['pinned_ids'], true) ? array_search($id, $payload['pinned_ids'], true) + 1 : null,
                            'cross_site_review_status' => $cross ? 'approved' : 'not_required',
                            'editorial_reason' => $cross ? $payload['review_reasons'][$id] : null,
                            'reviewed_by' => $cross ? $adminId : null,
                            'reviewed_at' => $cross ? now() : null,
                        ]);
                    }
                    DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->delete();
                }, 3);
            });
        });
    }

    public function pause(int $campaignId): void
    {
        $campaign = DB::table('link_campaigns')->find($campaignId);
        if ($campaign === null) {
            return;
        }
        $this->withSiteLease((string) $campaign->source_site_key, 'link_pool_pause', function () use ($campaignId): void {
            DB::transaction(function () use ($campaignId): void {
                $locked = DB::table('link_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
                if ($locked === null) {
                    return;
                }
                $revision = (int) $locked->revision + ($locked->status === 'paused' ? 0 : 1);
                DB::table('link_campaigns')->where('id', $campaignId)->update([
                    'status' => 'paused', 'revision' => $revision, 'updated_at' => now(),
                ]);
                $draft = DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->lockForUpdate()->first();
                if ($draft && (int) $draft->base_revision === (int) $locked->revision) {
                    $payload = json_decode((string) $draft->payload_json, true) ?: [];
                    $payload['base_revision'] = $revision;
                    DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->update([
                        'base_revision' => $revision, 'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }, 3);
        });
    }

    public function copyToSite(int $campaignId, string $destinationSiteKey, int $adminId): int
    {
        $campaign = DB::table('link_campaigns')->find($campaignId);
        if ($campaign === null || $destinationSiteKey === $campaign->source_site_key) {
            throw ValidationException::withMessages(['site_key' => '请选择不同的目标来源站点。']);
        }
        $draft = DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->first();
        $rules = json_decode((string) $campaign->source_rules_json, true) ?: [];
        $payload = $draft ? json_decode((string) $draft->payload_json, true) : [
            'name' => $campaign->name,
            'mode' => $campaign->mode,
            'priority' => $campaign->priority,
            'limit' => $campaign->limit,
            'scope' => $rules['scope'] ?? 'all',
            'category_id' => $rules['category_id'] ?? 0,
            'keyword' => $rules['keyword'] ?? '',
            'target_ids' => DB::table('link_campaign_targets')->where('campaign_id', $campaignId)->pluck('target_id')->all(),
            'pinned_ids' => DB::table('link_campaign_targets')->where('campaign_id', $campaignId)->whereNotNull('pin_order')->orderBy('pin_order')->pluck('target_id')->all(),
            'starts_at' => $campaign->starts_at,
            'ends_at' => $campaign->ends_at,
        ];
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['campaign' => '原活动草稿无法读取。']);
        }
        $payload['source_site_key'] = $destinationSiteKey;
        $payload['name'] = mb_substr((string) ($payload['name'] ?? $campaign->name), 0, 115).'（复制）';
        $payload['base_revision'] = 0;
        $payload['approved_cross_site_ids'] = [];
        $payload['review_reasons'] = [];
        $payload['source_article_ids'] = [];
        $payload['exclusion_ids'] = [];
        if (($payload['scope'] ?? 'all') === 'articles') {
            $payload['scope'] = 'all';
        }
        if (($payload['mode'] ?? '') !== 'manual') {
            $sameSiteTargetIds = DB::table('link_pool_targets')->where(fn ($query) => $query->where('target_site_key', $destinationSiteKey)->orWhere('target_type', 'external'))
                ->whereIn('id', $payload['target_ids'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
            $payload['target_ids'] = $sameSiteTargetIds;
            $payload['pinned_ids'] = array_values(array_intersect($payload['pinned_ids'] ?? [], $sameSiteTargetIds));
        }

        return $this->saveDraft(null, $payload, $adminId);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalize(array $input): array
    {
        $ids = static function ($raw): array {
            $parts = is_array($raw) ? $raw : preg_split('/[\s,，、]+/u', (string) $raw);

            return array_values(array_unique(array_filter(array_map('intval', $parts ?: []), static fn (int $id): bool => $id > 0)));
        };
        $payload = [
            'source_site_key' => trim((string) ($input['source_site_key'] ?? '')),
            'name' => trim((string) ($input['name'] ?? '')),
            'mode' => (string) ($input['mode'] ?? ''),
            'priority' => (int) ($input['priority'] ?? 0),
            'limit' => (int) ($input['limit'] ?? 4),
            'scope' => (string) ($input['scope'] ?? 'all'),
            'category_id' => (int) ($input['category_id'] ?? 0),
            'keyword' => trim((string) ($input['keyword'] ?? '')),
            'source_article_ids' => $ids($input['source_article_ids'] ?? []),
            'exclusion_ids' => $ids($input['exclusion_ids'] ?? []),
            'target_ids' => $ids($input['target_ids'] ?? []),
            'pinned_ids' => $ids($input['pinned_ids'] ?? []),
            'approved_cross_site_ids' => $ids($input['approved_cross_site_ids'] ?? []),
            'review_reasons' => is_array($input['review_reasons'] ?? null) ? $input['review_reasons'] : [],
            'weights' => is_array($input['weights'] ?? null) ? $input['weights'] : [],
            'starts_at' => ($input['starts_at'] ?? '') ?: null,
            'ends_at' => ($input['ends_at'] ?? '') ?: null,
            'base_revision' => (int) ($input['base_revision'] ?? 0),
        ];
        if ($payload['name'] === '' || mb_strlen($payload['name']) > 120
            || ! in_array($payload['mode'], ['manual', 'related', 'random', 'mixed'], true)
            || ! in_array($payload['scope'], ['all', 'category', 'articles', 'keyword'], true)
            || $payload['limit'] < 1 || $payload['limit'] > 6 || $payload['priority'] < 0 || $payload['priority'] > 100
            || $payload['scope'] === 'articles' && $payload['source_article_ids'] === []
            || $payload['scope'] === 'category' && $payload['category_id'] < 1
            || $payload['scope'] === 'keyword' && $payload['keyword'] === ''
            || $payload['mode'] === 'manual' && $payload['target_ids'] === []
            || count($payload['pinned_ids']) > 2
            || array_diff($payload['pinned_ids'], $payload['target_ids']) !== []) {
            throw ValidationException::withMessages(['campaign' => '活动配置不完整：请检查名称、模式、来源范围、数量与置顶。']);
        }
        if ($payload['starts_at'] && (! strtotime($payload['starts_at']) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $payload['starts_at']))) {
            throw ValidationException::withMessages(['starts_at' => '开始时间无效。']);
        }
        if ($payload['ends_at'] && (! strtotime($payload['ends_at']) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $payload['ends_at']))) {
            throw ValidationException::withMessages(['ends_at' => '结束时间无效。']);
        }
        if ($payload['starts_at'] && $payload['ends_at'] && strtotime($payload['ends_at']) <= strtotime($payload['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => '结束时间必须晚于开始时间。']);
        }
        if (count($payload['target_ids']) > 2000 || count($payload['source_article_ids']) > 2000) {
            throw ValidationException::withMessages(['campaign' => '单个活动最多选择 2000 篇文章。']);
        }

        return $payload;
    }

    private function withSiteLease(string $siteKey, string $operation, \Closure $callback): mixed
    {
        if ($siteKey === 'primary') {
            return $callback();
        }
        $profile = $this->sites->profile($siteKey);
        if ($profile?->channel === null || $profile->channel->status === DistributionChannel::STATUS_DELETING) {
            throw ValidationException::withMessages(['source_site_key' => '托管站不存在或渠道正在删除。']);
        }
        try {
            return $this->leases->run($profile->channel, $operation, $callback);
        } catch (DistributionChannelDeletionBlocked) {
            throw ValidationException::withMessages(['source_site_key' => '渠道正在删除，当前无法保存活动。']);
        }
    }

    /** @param list<string> $siteKeys */
    private function withTargetLeases(array $siteKeys, \Closure $callback): mixed
    {
        if ($siteKeys === []) {
            return $callback();
        }
        $siteKey = array_shift($siteKeys);

        return $this->withSiteLease($siteKey, 'link_pool_publish_target',
            fn () => $this->withTargetLeases($siteKeys, $callback));
    }
}
