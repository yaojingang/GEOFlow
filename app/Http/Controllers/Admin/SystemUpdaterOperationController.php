<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\SystemUpdater\AgentClient;
use App\Http\Controllers\Controller;
use App\Services\Admin\SystemUpdateOperationGuard;
use App\Services\Admin\SystemUpdaterBootstrapService;
use App\Services\Admin\SystemUpdaterMutationPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemUpdaterOperationController extends Controller
{
    public function __construct(private readonly SystemUpdaterMutationPolicy $mutationPolicy) {}

    public function prepare(SystemUpdaterBootstrapService $bootstrapService): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();

        try {
            $prepared = $bootstrapService->prepare();
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.updater.prepare_failed')]);
        }

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.updater.prepared', [
                'version' => (string) ($prepared['version'] ?? ''),
            ]));
    }

    public function download(SystemUpdaterBootstrapService $bootstrapService): StreamedResponse|RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();

        try {
            $prepared = $bootstrapService->download();
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.updater.download_failed')]);
        }

        return Storage::disk('local')->download(
            (string) $prepared['path'],
            (string) $prepared['filename'],
            [
                'Content-Type' => 'application/gzip',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function update(Request $request, AgentClient $agentClient, SystemUpdateOperationGuard $operationGuard): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $authorizationCode = $this->validateMutationRequest($request);

        return $this->startOperation(
            fn (): array => $this->startAuthorizedMutation(
                $agentClient,
                'update',
                fn (): array => $agentClient->startUpdate($authorizationCode),
            ),
            'update',
            $operationGuard,
        );
    }

    public function backup(Request $request, AgentClient $agentClient, SystemUpdateOperationGuard $operationGuard): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $authorizationCode = $this->validateMutationRequest($request);

        return $this->startOperation(
            fn (): array => $this->startAuthorizedMutation(
                $agentClient,
                'backup',
                fn (): array => $agentClient->startBackup($authorizationCode),
            ),
            'backup',
            $operationGuard,
        );
    }

    public function rollback(Request $request, AgentClient $agentClient, SystemUpdateOperationGuard $operationGuard): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $authorizationCode = $this->validateMutationRequest($request);
        $validated = $request->validate([
            'recovery_point_id' => ['required', 'regex:/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/'],
        ]);

        return $this->startOperation(
            fn (): array => $this->startAuthorizedMutation(
                $agentClient,
                'rollback',
                fn (): array => $agentClient->startRollback((string) $validated['recovery_point_id'], $authorizationCode),
            ),
            'rollback',
            $operationGuard,
        );
    }

    public function verify(AgentClient $agentClient): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();

        return $this->startOperation(
            fn (): array => $agentClient->startVerify(),
            'verify',
        );
    }

    private function validateMutationRequest(Request $request): string
    {
        $validated = $request->validate([
            'updater_authorization_code' => ['required', 'regex:/\A[0-9]{6}\z/'],
        ]);

        if ((bool) config('geoflow.update_require_admin_password', true)) {
            $password = $request->validate([
                'current_admin_password' => ['required', 'string'],
            ]);
            $admin = $request->user('admin');
            if (! $admin || ! Hash::check((string) $password['current_admin_password'], (string) $admin->password)) {
                throw ValidationException::withMessages([
                    'current_admin_password' => __('admin.system_updates.error.admin_password_invalid'),
                ]);
            }
        }

        return (string) $validated['updater_authorization_code'];
    }

    /**
     * @param  \Closure(): array<string, mixed>  $start
     * @return array<string, mixed>
     */
    private function startAuthorizedMutation(AgentClient $agentClient, string $kind, \Closure $start): array
    {
        $status = $agentClient->status();
        if ($this->mutationPolicy->allows($status, $kind)) {
            return $start();
        }

        throw new \RuntimeException('Updater mutation preconditions are unavailable.');
    }

    /** @param  \Closure(): array<string, mixed>  $start */
    private function startOperation(
        \Closure $start,
        string $kind,
        ?SystemUpdateOperationGuard $operationGuard = null,
    ): RedirectResponse {
        try {
            $operation = $operationGuard ? $operationGuard->run($start) : $start();
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.updater.operation_failed')]);
        }

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.updater.operation_started', [
                'operation' => __('admin.system_updates.updater.operation_kind.'.$kind),
                'id' => (string) ($operation['id'] ?? ''),
            ]));
    }

    private function ensureUpdateCenterEnabled(): void
    {
        abort_unless((bool) config('geoflow.update_center_enabled', true), 404);
    }
}
