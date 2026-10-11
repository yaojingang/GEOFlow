<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\LinkPoolMetricsService;
use App\Services\Site\LinkPoolSiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LinkPoolEventController extends Controller
{
    public function __invoke(Request $request, LinkPoolMetricsService $metrics, LinkPoolSiteService $sites): JsonResponse
    {
        $origin = $request->headers->get('Origin');
        if (($origin !== null && rtrim($origin, '/') !== $request->getSchemeAndHttpHost())
            || $request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            return response()->json(['accepted' => false], 403);
        }
        $data = $request->validate([
            'placement_id' => ['required', 'integer', 'min:1'],
            'event_uuid' => ['required', 'uuid'],
            'event_type' => ['required', 'in:viewable,click'],
        ]);
        $siteKey = $sites->currentKey();
        if ($sites->emergencyDisabled() || ! $sites->canPublish($siteKey) || ! $sites->settings($siteKey)['enabled']) {
            return response()->json(['accepted' => false], 403);
        }

        return response()->json(['accepted' => $metrics->recordEvent(
            $request,
            (int) $data['placement_id'],
            (string) $data['event_uuid'],
            (string) $data['event_type'],
            $siteKey,
        )]);
    }
}
