<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\ThemeWorkspaceRequest;
use App\Models\ThemeRevision;
use App\Services\Admin\SiteThemePackageGuard;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Api\ThemeWorkspaceAuthorization;
use App\Services\Api\ThemeWorkspacePreview;
use App\Services\Api\ThemeWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ThemeWorkspaceController extends BaseApiController
{
    public function themes(Request $request, ThemeWorkspaceService $workspaces): JsonResponse
    {
        return $this->success($request, $workspaces->themes($this->auth($request)));
    }

    public function contract(Request $request, ThemeWorkspaceService $workspaces, ?string $workspace = null): JsonResponse
    {
        return $this->success($request, $workspaces->contract($this->auth($request), $workspace));
    }

    public function discard(ThemeWorkspaceRequest $request, string $workspace, ThemeWorkspaceService $workspaces): JsonResponse
    {
        return $this->success($request, $workspaces->discard($this->auth($request), $workspace, $request->integer('expected_version')));
    }

    public function store(ThemeWorkspaceRequest $request, ThemeWorkspaceService $workspaces): JsonResponse
    {
        return $this->success($request, $workspaces->create($this->auth($request), $request->validated('site'), $request->validated('theme')), 201);
    }

    public function show(Request $request, string $workspace, ThemeWorkspaceService $workspaces): JsonResponse
    {
        return $this->success($request, $workspaces->show($this->auth($request), $workspace));
    }

    public function file(ThemeWorkspaceRequest $request, string $workspace, ThemeWorkspaceService $workspaces): JsonResponse
    {
        return $this->success($request, $workspaces->file($this->auth($request), $workspace, $request->validated('path'), $request->integer('offset', 0), $request->integer('length', 262144)));
    }

    public function authorizeCode(ThemeWorkspaceRequest $request, string $workspace, ThemeWorkspaceAuthorization $authorization): JsonResponse
    {
        return $this->success($request, $authorization->grant($this->auth($request), $workspace, $request->validated('password')));
    }

    public function change(ThemeWorkspaceRequest $request, string $workspace, ThemeWorkspaceService $workspaces): JsonResponse
    {
        return $this->success($request, $workspaces->change($this->auth($request), $workspace, $request->integer('expected_version'), $request->validated('changes')));
    }

    public function preview(Request $request, string $workspace, ThemeWorkspacePreview $preview): JsonResponse
    {
        return $this->success($request, $preview->links($this->auth($request), $workspace));
    }

    public function previewFrame(Request $request, string $workspace, string $revision, ThemeWorkspacePreview $preview, string $sitePath = ''): Response
    {
        $auth = $preview->fromSignedRequest($request, $workspace, $revision);

        return response($preview->render($auth, $workspace, $sitePath, $request->query(), expectedRevision: $revision), 200, ThemeWorkspacePreview::HEADERS);
    }

    public function previewAsset(Request $request, string $workspace, string $revision, string $assetPath, ThemeWorkspacePreview $preview, ThemeRevisionStorage $storage): BinaryFileResponse|Response
    {
        $auth = $preview->fromSignedRequest($request, $workspace, $revision);
        $model = ThemeRevision::query()->findOrFail($revision);
        $extension = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
        abort_unless(isset(SiteThemePackageGuard::ASSET_MIMES[$extension]), 404);
        $logical = 'public/themes/'.$model->theme_id.'/'.$assetPath;
        $path = $storage->path($model, $logical);
        $bytes = $storage->read($path, 5 * 1024 * 1024);
        abort_unless(hash_equals($model->files[$logical]['sha256'], hash('sha256', $bytes)), 409);

        $headers = [
            'Content-Type' => SiteThemePackageGuard::ASSET_MIMES[$extension], 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff', 'Access-Control-Allow-Origin' => '*',
        ];

        return $extension === 'css'
            ? response($preview->stylesheet($bytes, $assetPath, $auth, $workspace, $model), 200, $headers)
            : response()->file($path, $headers);
    }
}
