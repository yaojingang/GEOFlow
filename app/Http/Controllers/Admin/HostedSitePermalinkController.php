<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DistributionChannel;
use App\Models\UrlChangeRequest;
use App\Services\Site\UrlChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class HostedSitePermalinkController extends Controller
{
    public function __construct(private readonly UrlChangeService $changes) {}

    public function preview(Request $request, DistributionChannel $hostedSite): RedirectResponse
    {
        $payload = $request->validate(['pattern' => ['required', 'string', 'max:160']]);
        try {
            $change = $this->changes->start($request->user('admin'), 'hosted', (int) $hostedSite->id, $payload['pattern']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['pattern' => $exception->getMessage()]);
        }

        return redirect()->route('admin.url-changes.show', $change);
    }

    public function activate(Request $request, DistributionChannel $hostedSite): RedirectResponse
    {
        return app(UrlChangeController::class)->confirm($request, $this->requestRecord($request, $hostedSite));
    }

    public function migrationMap(Request $request, DistributionChannel $hostedSite): RedirectResponse
    {
        return redirect()->route('admin.url-changes.download', $this->requestRecord($request, $hostedSite));
    }

    private function requestRecord(Request $request, DistributionChannel $channel): UrlChangeRequest
    {
        if (! $request->filled('change_id')) {
            throw ValidationException::withMessages(['pattern' => __('url_change.errors.legacy_preview')]);
        }
        $data = $request->validate(['change_id' => ['required', 'uuid']]);
        $change = UrlChangeRequest::query()->findOrFail($data['change_id']);
        abort_unless($change->operation === 'hosted' && $change->target_id === (int) $channel->id && $change->admin_id === (int) $request->user('admin')->id, 403);

        return $change;
    }
}
