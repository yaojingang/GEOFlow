<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\KnowledgeMediaAsset;
use App\Services\AiWorkspace\AdminHelpFeatureRegistry;
use App\Services\AiWorkspace\SystemKnowledgeMediaManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class AiWorkspaceKnowledgeMediaController extends Controller
{
    public function __invoke(
        Request $request,
        int $mediaAsset,
        AdminHelpFeatureRegistry $features,
        SystemKnowledgeMediaManager $media,
    ): BinaryFileResponse|Response {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 401);
        $mediaAsset = KnowledgeMediaAsset::query()->findOrFail($mediaAsset);
        $mediaAsset->loadMissing('knowledgeBase.systemBinding');
        abort_unless($mediaAsset->knowledgeBase?->isSystemManaged() === true, 404);
        abort_unless($features->canAccessRoute($admin, (string) $mediaAsset->route_name), 404);
        $thumbnail = $request->query('variant') === 'thumbnail';
        $file = $media->readableFile($mediaAsset, $thumbnail);
        abort_unless(is_array($file), 404);

        $etag = '"'.(string) $file['content_hash'].'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $this->headers((string) $file['mime_type'], $etag));
        }

        $path = Storage::disk('local')->path((string) $file['path']);
        $extension = $file['mime_type'] === 'image/webp' ? 'webp' : 'png';

        return response()->file($path, $this->headers((string) $file['mime_type'], $etag) + [
            'Content-Disposition' => 'inline; filename="'.$mediaAsset->asset_key.'-v'.$mediaAsset->asset_version.($thumbnail ? '-thumbnail' : '').'.'.$extension.'"',
        ]);
    }

    /** @return array<string, string> */
    private function headers(string $mimeType, string $etag): array
    {
        return [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=86400, immutable',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];
    }
}
