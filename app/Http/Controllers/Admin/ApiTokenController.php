<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Api\ApiTokenService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * API Token 管理控制器（超级管理员）。
 *
 * 对齐 bak/admin/api-tokens.php 的核心能力：
 * 1. 创建 Token（一次性明文回显）；
 * 2. 列表查看 Token 元数据；
 * 3. 撤销已发放 Token。
 */
class ApiTokenController extends Controller
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService
    ) {}

    /**
     * API Token 管理页。
     */
    public function index(): View
    {
        return view('admin.api-tokens.index', [
            'pageTitle' => __('admin.api_tokens.page_title'),
            'activeMenu' => 'admin_users',
            'adminSiteName' => AdminWeb::siteName(),
            'tokens' => $this->apiTokenService->listTokens(),
            'availableScopes' => $this->apiTokenService->getAvailableScopes(),
            'defaultExpiresAtInput' => $this->apiTokenService->defaultExpiresAtInputValue(),
            'submissionToken' => (string) Str::uuid(),
        ]);
    }

    /**
     * 创建 API Token。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string'],
            'expires_at' => ['nullable', 'string', 'max:32'],
        ]);

        $adminId = auth('admin')->id() !== null ? (int) auth('admin')->id() : 0;
        $idempotencyKey = 'geoflow:admin-api-token-create:'.$adminId.':'.hash('sha256', (string) $payload['submission_token']);
        $idempotencyExpiresAt = now()->addMinutes(10);
        if (! Cache::add($idempotencyKey, ['state' => 'pending'], $idempotencyExpiresAt)) {
            $existing = Cache::get($idempotencyKey);
            if (is_array($existing) && ($existing['state'] ?? null) === 'completed' && is_string($existing['token'] ?? null)) {
                try {
                    return redirect()
                        ->route('admin.api-tokens.index')
                        ->with('message', __('admin.api_tokens.message.created'))
                        ->with('new_api_token', Crypt::decryptString($existing['token']));
                } catch (Throwable) {
                    $this->revokeUndeliveredToken(['record' => ['id' => (int) ($existing['record_id'] ?? 0)]]);
                    Cache::forget($idempotencyKey);

                    return back()->withErrors(__('admin.api_tokens.error.replay_failed'))->withInput();
                }
            }

            return back()->withErrors(__('admin.api_tokens.error.duplicate_submission'))->withInput();
        }

        $created = null;
        try {
            $created = $this->apiTokenService->createToken(
                (string) $payload['name'],
                is_array($payload['scopes']) ? $payload['scopes'] : [],
                $adminId > 0 ? $adminId : null,
                (string) ($payload['expires_at'] ?? '')
            );
            $recordId = (int) ($created['record']['id'] ?? 0);
            if (! Cache::put($idempotencyKey, [
                'state' => 'completed',
                'record_id' => $recordId,
                'token' => Crypt::encryptString((string) $created['token']),
            ], $idempotencyExpiresAt)) {
                throw new \RuntimeException('Could not persist the token idempotency result.');
            }

            return redirect()
                ->route('admin.api-tokens.index')
                ->with('message', __('admin.api_tokens.message.created'))
                ->with('new_api_token', (string) $created['token']);
        } catch (ApiException $exception) {
            $this->revokeUndeliveredToken($created);
            Cache::forget($idempotencyKey);

            return back()->withErrors($exception->getMessage())->withInput();
        } catch (Throwable $exception) {
            $this->revokeUndeliveredToken($created);
            Cache::forget($idempotencyKey);

            return back()->withErrors(__('admin.api_tokens.error.operation_failed', ['message' => $exception->getMessage()]))->withInput();
        }
    }

    /** @param array<string,mixed>|null $created */
    private function revokeUndeliveredToken(?array $created): void
    {
        $recordId = (int) ($created['record']['id'] ?? 0);
        if ($recordId <= 0) {
            return;
        }

        try {
            $this->apiTokenService->revokeToken($recordId);
        } catch (Throwable $exception) {
            Log::critical('Could not revoke an API token after delivery failed.', [
                'token_id' => $recordId,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * 撤销 API Token。
     */
    public function revoke(int $tokenId): RedirectResponse
    {
        if ($tokenId <= 0) {
            return back()->withErrors(__('admin.api_tokens.error.operation_failed', ['message' => 'Token ID 无效']));
        }

        try {
            $this->apiTokenService->revokeToken($tokenId);

            return redirect()
                ->route('admin.api-tokens.index')
                ->with('message', __('admin.api_tokens.message.revoked'));
        } catch (ApiException $exception) {
            return back()->withErrors($exception->getMessage());
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.api_tokens.error.operation_failed', ['message' => $exception->getMessage()]));
        }
    }
}
