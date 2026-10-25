<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\SystemUpdater\AgentClient;
use App\Exceptions\SystemUpdaterPreparationException;
use App\Http\Controllers\Controller;
use App\Services\Admin\SystemUpdaterBootstrapService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemUpdaterOperationController extends Controller
{
    public function prepare(SystemUpdaterBootstrapService $bootstrapService): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();

        try {
            $prepared = $bootstrapService->prepare();
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.system-updates.index')
                ->with('system_updater_error', [
                    'reason' => $this->prepareFailureReason($exception),
                ]);
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

    public function verify(AgentClient $agentClient): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();

        return $this->startOperation(
            fn (): array => $agentClient->startVerify(),
            'verify',
        );
    }

    private function startOperation(\Closure $start, string $kind): RedirectResponse
    {
        try {
            $operation = $start();
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

    private function prepareFailureReason(\Throwable $exception): string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof SystemUpdaterPreparationException) {
                return $current->failureReason();
            }

            if ($current instanceof RequestException) {
                $status = $current->response->status();
                if ($status === 404) {
                    return 'release_not_found';
                }

                if ($status === 403 || $status === 429 || $status >= 500) {
                    return 'release_unavailable';
                }
            }

            if ($current instanceof ConnectionException) {
                return 'connection_failed';
            }

            $message = strtolower($current->getMessage());
            if (str_contains($message, 'linux hosts only')
                || str_contains($message, 'cpu architecture')
                || str_contains($message, 'no package for this host')) {
                return 'platform_unsupported';
            }

            if (str_contains($message, 'signature')
                || str_contains($message, 'integrity')
                || str_contains($message, 'digest')
                || str_contains($message, 'trusted root')
                || str_contains($message, 'rollback was rejected')
                || str_contains($message, 'expired')
                || str_contains($message, 'expiry')) {
                return 'verification_failed';
            }

            if (str_contains($message, 'could not be staged')
                || str_contains($message, 'could not be stored')
                || str_contains($message, 'storage path')
                || str_contains($message, 'unable to create directory')
                || str_contains($message, 'permission denied')
                || str_contains($message, 'read-only file system')
                || str_contains($message, 'no space left on device')) {
                return 'storage_failed';
            }
        }

        return 'unexpected';
    }
}
