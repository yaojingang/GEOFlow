<?php

namespace App\Services\BrowserOperations;

use App\Exceptions\ApiException;
use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationTransition;
use App\Services\GeoFlow\ArticlePublicationQualityGate;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ManualPublicationBrowserService
{
    public const STALE_AFTER_MINUTES = 10;

    public function __construct(private readonly ArticlePublicationQualityGate $publicationQualityGate) {}

    public function queue(Admin $admin, int $tokenId, int $perPage): LengthAwarePaginator
    {
        return ManualPublication::query()
            ->visibleTo($admin)
            ->with(['account:id,account_name,platform,profile_url', 'persona:id,name'])
            ->whereNotNull('publication_payload')
            ->whereNotNull('target_url')
            ->where(function ($query) use ($tokenId): void {
                $query->where('status', ManualPublication::STATUS_READY)
                    ->orWhere(function ($claimed) use ($tokenId): void {
                        $claimed->where('status', ManualPublication::STATUS_IN_PROGRESS)
                            ->where('browser_claimed_by_token_id', $tokenId);
                    });
            })
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function findVisible(Admin $admin, int $tokenId, int $publicationId): ManualPublication
    {
        $publication = ManualPublication::query()
            ->visibleTo($admin)
            ->with(['account:id,account_name,platform,profile_url', 'persona:id,name'])
            ->find($publicationId);
        if (! $publication instanceof ManualPublication) {
            throw new ApiException('publication_not_found', '工作单不存在', 404);
        }
        if ($publication->status === ManualPublication::STATUS_IN_PROGRESS
            && (int) $publication->browser_claimed_by_token_id !== $tokenId) {
            throw new ApiException('claim_owned_by_another_client', '当前浏览器连接不持有该工作单', 409);
        }

        return $publication;
    }

    public function claim(Admin $admin, int $tokenId, int $publicationId, int $revision): ManualPublication
    {
        return DB::transaction(function () use ($admin, $tokenId, $publicationId, $revision): ManualPublication {
            $publication = $this->lockVisible($admin, $publicationId);
            $this->assertRevision($publication, $revision);
            if ($publication->status !== ManualPublication::STATUS_READY) {
                throw new ApiException('publication_claimed', '工作单已被领取或不处于待执行状态', 409);
            }
            $this->assertSourceArticleQuality($publication);
            if (! is_array($publication->publication_payload)) {
                throw new ApiException('browser_payload_unavailable', '该历史工作单没有浏览器执行载荷', 409);
            }
            if (trim((string) $publication->target_url) === '') {
                throw new ApiException('browser_target_required', '浏览器执行工作单必须提供目标 URL', 409);
            }
            if (($publication->publication_payload['target_action'] ?? null) === 'zhihu_answer'
                && (! $publication->account || trim((string) $publication->account->profile_url) === '')) {
                throw new ApiException('account_profile_required', '浏览器执行账号缺少 profile_url', 409);
            }

            $fromStatus = (string) $publication->status;
            $transitionedAt = now();
            $publication->forceFill([
                'status' => ManualPublication::STATUS_IN_PROGRESS,
                'status_changed_at' => $transitionedAt,
                'browser_claimed_by_token_id' => $tokenId,
                'browser_claimed_at' => now(),
                'browser_last_seen_at' => now(),
                'revision' => $revision + 1,
            ])->save();
            $this->recordTransition($publication, $admin, $fromStatus, ManualPublication::STATUS_IN_PROGRESS, createdAt: $transitionedAt);

            return $publication->refresh()->load(['account:id,account_name,platform,profile_url', 'persona:id,name']);
        });
    }

    public function heartbeat(Admin $admin, int $tokenId, int $publicationId): ManualPublication
    {
        return DB::transaction(function () use ($admin, $tokenId, $publicationId): ManualPublication {
            $publication = $this->lockVisible($admin, $publicationId);
            $this->assertClaimOwner($publication, $tokenId);
            $publication->forceFill(['browser_last_seen_at' => now()])->save();

            return $publication->refresh();
        });
    }

    public function release(Admin $admin, int $tokenId, int $publicationId, int $revision): ManualPublication
    {
        return DB::transaction(function () use ($admin, $tokenId, $publicationId, $revision): ManualPublication {
            $publication = $this->lockVisible($admin, $publicationId);
            $this->assertRevision($publication, $revision);
            $this->assertClaimOwner($publication, $tokenId);
            $fromStatus = (string) $publication->status;
            $transitionedAt = now();
            $publication->forceFill([
                'status' => ManualPublication::STATUS_READY,
                'status_changed_at' => $transitionedAt,
                'browser_claimed_by_token_id' => null,
                'browser_claimed_at' => null,
                'browser_last_seen_at' => null,
                'revision' => $revision + 1,
            ])->save();
            $this->recordTransition($publication, $admin, $fromStatus, ManualPublication::STATUS_READY, createdAt: $transitionedAt);

            return $publication->refresh()->load(['account:id,account_name,platform,profile_url', 'persona:id,name']);
        });
    }

    /** @param array<string,mixed> $receipt */
    public function recordReceipt(
        Admin $admin,
        int $tokenId,
        int $publicationId,
        int $revision,
        array $receipt,
        string $clientVersion,
    ): ManualPublication {
        return DB::transaction(function () use ($admin, $tokenId, $publicationId, $revision, $receipt, $clientVersion): ManualPublication {
            $publication = $this->lockVisible($admin, $publicationId);
            $this->assertRevision($publication, $revision);
            $this->assertClaimOwner($publication, $tokenId);

            $outcome = (string) $receipt['outcome'];
            $status = match ($outcome) {
                'completed' => ManualPublication::STATUS_COMPLETED,
                'failed' => ManualPublication::STATUS_FAILED,
                'cancelled' => ManualPublication::STATUS_CANCELLED,
                'outcome_unknown' => ManualPublication::STATUS_OUTCOME_UNKNOWN,
                default => throw new ApiException('validation_failed', '执行结果无效', 422),
            };
            $completionUrl = trim((string) ($receipt['completion_url'] ?? '')) ?: null;
            if ($status === ManualPublication::STATUS_COMPLETED && $completionUrl === null) {
                throw new ApiException('validation_failed', '完成状态必须提供发布 URL', 422);
            }
            if ($completionUrl !== null) {
                $this->assertCompletionUrl($publication, $completionUrl);
            }
            $this->assertTargetOrigin($publication, (string) ($receipt['target_origin'] ?? ''));
            $requiresVerifiedAccount = ($publication->publication_payload['target_action'] ?? null) === 'zhihu_answer'
                && in_array($outcome, ['completed', 'outcome_unknown'], true);
            $this->assertObservedAccount(
                $publication,
                (string) ($receipt['observed_account_hash'] ?? ''),
                $requiresVerifiedAccount,
            );

            $storedReceipt = [
                'schema_version' => 1,
                'outcome' => $outcome,
                'completion_url' => $completionUrl,
                'protocol_version' => 1,
                'extension_version' => $clientVersion,
                'adapter_version' => (string) ($receipt['adapter_version'] ?? ''),
                'target_origin' => (string) ($receipt['target_origin'] ?? ''),
                'observed_account_hash' => (string) ($receipt['observed_account_hash'] ?? ''),
                'started_at' => $receipt['started_at'] ?? null,
                'finished_at' => $receipt['finished_at'] ?? now()->toIso8601String(),
                'error_code' => $receipt['error_code'] ?? null,
            ];

            $fromStatus = (string) $publication->status;
            $transitionedAt = now();
            $resultNote = trim((string) ($receipt['result_note'] ?? '')) ?: null;
            $publication->forceFill([
                'status' => $status,
                'status_changed_at' => $transitionedAt,
                'completion_url' => $completionUrl,
                'result_note' => $resultNote,
                'execution_receipt' => $storedReceipt,
                'completed_at' => $status === ManualPublication::STATUS_COMPLETED ? now() : null,
                'browser_claimed_by_token_id' => null,
                'browser_claimed_at' => null,
                'browser_last_seen_at' => null,
                'revision' => $revision + 1,
            ])->save();
            $this->recordTransition(
                $publication,
                $admin,
                $fromStatus,
                $status,
                $completionUrl,
                $resultNote,
                $transitionedAt,
            );

            return $publication->refresh()->load(['account:id,account_name,platform,profile_url', 'persona:id,name']);
        });
    }

    private function lockVisible(Admin $admin, int $publicationId): ManualPublication
    {
        $publication = ManualPublication::query()
            ->visibleTo($admin)
            ->with('account:id,account_name,platform,profile_url')
            ->whereKey($publicationId)
            ->lockForUpdate()
            ->first();
        if (! $publication instanceof ManualPublication) {
            throw new ApiException('publication_not_found', '工作单不存在', 404);
        }

        return $publication;
    }

    private function assertRevision(ManualPublication $publication, int $revision): void
    {
        if ((int) $publication->revision !== $revision) {
            throw new ApiException('revision_conflict', '工作单版本已经变化，请刷新后重试', 409, [
                'current_revision' => (int) $publication->revision,
            ]);
        }
    }

    private function assertSourceArticleQuality(ManualPublication $publication): void
    {
        if ($publication->article_id === null) {
            return;
        }

        $article = Article::query()->find((int) $publication->article_id);
        if (! $article instanceof Article) {
            throw new ApiException('article_unavailable', '源文章不可用，无法领取工作单', 409);
        }

        try {
            $this->publicationQualityGate->check($article, 'browser_publication_claim');
        } catch (ArticleAiQualityGateException $exception) {
            throw new ApiException($exception->getErrorCode(), $exception->getMessage(), 409);
        } catch (ArticleRiskGateException $exception) {
            throw new ApiException('article_risk_blocked', $exception->getMessage(), 409);
        }
    }

    private function assertClaimOwner(ManualPublication $publication, int $tokenId): void
    {
        if ($publication->status !== ManualPublication::STATUS_IN_PROGRESS
            || (int) $publication->browser_claimed_by_token_id !== $tokenId) {
            throw new ApiException('claim_owned_by_another_client', '当前浏览器连接不持有该工作单', 409);
        }
    }

    private function recordTransition(
        ManualPublication $publication,
        Admin $actor,
        string $fromStatus,
        string $toStatus,
        ?string $completionUrl = null,
        ?string $resultNote = null,
        ?CarbonInterface $createdAt = null,
    ): void {
        ManualPublicationTransition::query()->create([
            'manual_publication_id' => $publication->getKey(),
            'changed_by_admin_id' => $actor->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'completion_url' => $completionUrl,
            'result_note' => $resultNote,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function assertCompletionUrl(ManualPublication $publication, string $url): void
    {
        $this->assertSafeHttpUrl($url, 'invalid_completion_url', '发布 URL 格式无效');

        if ($publication->platform === ManualPublicationAccount::PLATFORM_ZHIHU) {
            $this->assertZhihuHost($url, 'invalid_completion_origin');
            $targetPath = (string) parse_url((string) $publication->target_url, PHP_URL_PATH);
            $completionPath = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('#\A/question/(\d+)#', $targetPath, $targetMatch) === 1
                && (preg_match('#\A/question/(\d+)/answer/\d+#', $completionPath, $completionMatch) !== 1
                    || $targetMatch[1] !== $completionMatch[1])) {
                throw new ApiException('invalid_completion_target', '发布 URL 不属于该知乎问题', 422);
            }
        }
    }

    private function assertTargetOrigin(ManualPublication $publication, string $origin): void
    {
        $path = parse_url($origin, PHP_URL_PATH);
        if (filter_var($origin, FILTER_VALIDATE_URL) === false
            || ($path !== null && ! in_array($path, ['', '/'], true))
            || parse_url($origin, PHP_URL_QUERY) !== null
            || parse_url($origin, PHP_URL_FRAGMENT) !== null) {
            throw new ApiException('invalid_target_origin', '目标页面来源格式无效', 422);
        }

        $this->assertSafeHttpUrl($origin, 'invalid_target_origin', '目标页面来源格式无效');
        if ($publication->platform === ManualPublicationAccount::PLATFORM_ZHIHU) {
            $this->assertZhihuHost($origin, 'invalid_target_origin');
        }
    }

    private function assertObservedAccount(
        ManualPublication $publication,
        string $observedHash,
        bool $required,
    ): void {
        $profileUrl = trim((string) ($publication->account?->profile_url ?? ''));
        if (! $required && $observedHash === '') {
            return;
        }
        if ($profileUrl === '') {
            throw new ApiException('account_profile_required', '浏览器执行账号缺少 profile_url', 409);
        }

        $expected = hash('sha256', $this->normalizeProfileUrl($profileUrl));
        if ($observedHash === '' || ! hash_equals($expected, strtolower($observedHash))) {
            throw new ApiException('account_mismatch', '页面登录账号与工作单指定账号不一致', 409);
        }
    }

    private function normalizeProfileUrl(string $profileUrl): string
    {
        $scheme = strtolower((string) parse_url($profileUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($profileUrl, PHP_URL_HOST));
        $port = parse_url($profileUrl, PHP_URL_PORT);
        $path = rtrim((string) parse_url($profileUrl, PHP_URL_PATH), '/');

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port).strtolower($path);
    }

    private function assertSafeHttpUrl(string $url, string $code, string $message): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null) {
            throw new ApiException($code, $message, 422);
        }
    }

    private function assertZhihuHost(string $url, string $code): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== 'zhihu.com' && ! str_ends_with($host, '.zhihu.com')) {
            throw new ApiException($code, '页面 URL 与目标平台不匹配', 422);
        }
    }
}
