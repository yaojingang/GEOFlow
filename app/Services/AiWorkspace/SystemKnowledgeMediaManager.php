<?php

namespace App\Services\AiWorkspace;

use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeMediaAsset;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SystemKnowledgeMediaManager
{
    private const STORAGE_PREFIX = 'ai-workspace-knowledge-media/';

    public function __construct(
        private readonly SystemKnowledgeBaseManager $systemKnowledge,
        private readonly AdminHelpFeatureRegistry $features,
    ) {}

    /** @return array{imported:int,updated:int,unchanged:int,total:int} */
    public function syncBundled(): array
    {
        $binding = $this->systemKnowledge->binding();
        $knowledgeBase = $binding?->knowledgeBase;
        if (! $knowledgeBase instanceof KnowledgeBase) {
            throw new RuntimeException('Sync the AI Workspace system knowledge before importing media.');
        }

        $manifestPath = resource_path('knowledge/ai-workspace/media/manifest.json');
        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw new RuntimeException('The bundled knowledge media manifest is missing.');
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($decoded) || ! is_array($decoded['assets'] ?? null)) {
            throw new RuntimeException('The bundled knowledge media manifest is invalid.');
        }
        if (($decoded['knowledge_key'] ?? null) !== $binding->system_key) {
            throw new RuntimeException('The bundled knowledge media manifest targets a different system knowledge base.');
        }

        $assetIdentities = collect($decoded['assets'])
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static fn (array $item): string => trim((string) ($item['asset_key'] ?? ''))
                .'|'.trim((string) ($item['locale'] ?? 'zh_CN')))
            ->all();
        if (count($assetIdentities) !== count(array_unique($assetIdentities))) {
            throw new RuntimeException('The bundled knowledge media manifest contains duplicate asset identities.');
        }

        $result = ['imported' => 0, 'updated' => 0, 'unchanged' => 0, 'total' => 0];
        foreach ($decoded['assets'] as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('The bundled knowledge media manifest contains an invalid asset.');
            }
            $result['total']++;
            $path = resource_path('knowledge/ai-workspace/media/'.basename((string) ($item['file'] ?? '')));
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('A bundled knowledge media file is missing.');
            }
            $assetResult = $this->importBytes(
                $knowledgeBase,
                (string) file_get_contents($path),
                $item + [
                    'official_version' => (string) $binding->official_version,
                    'captured_app_version' => (string) ($decoded['captured_app_version'] ?? config('geoflow.app_version', '')),
                ],
            );
            $result[$assetResult['created'] ? 'imported' : ($assetResult['changed'] ? 'updated' : 'unchanged')]++;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function replace(KnowledgeBase $knowledgeBase, Admin $admin, UploadedFile $file, array $metadata): KnowledgeMediaAsset
    {
        $this->authorize($knowledgeBase, $admin);
        $bytes = $file->get();
        if (! is_string($bytes)) {
            throw new RuntimeException('Unable to read the uploaded image.');
        }

        return $this->importBytes($knowledgeBase, $bytes, $metadata, $admin)['asset'];
    }

    /** @param array<string, mixed> $metadata */
    public function updateMetadata(KnowledgeMediaAsset $asset, Admin $admin, array $metadata): KnowledgeMediaAsset
    {
        $knowledgeBase = $asset->knowledgeBase;
        if (! $knowledgeBase instanceof KnowledgeBase) {
            throw new RuntimeException('The media asset has no knowledge base.');
        }
        $this->authorize($knowledgeBase, $admin);
        $routeName = trim((string) ($metadata['route_name'] ?? $asset->route_name));
        if (! $this->features->canAccessRoute($admin, $routeName)) {
            throw new RuntimeException('The selected media route is not a trusted accessible entry.');
        }

        $asset->forceFill([
            'section_key' => trim((string) ($metadata['section_key'] ?? $asset->section_key)),
            'route_name' => $routeName,
            'title' => trim((string) ($metadata['title'] ?? $asset->title)),
            'alt_text' => trim((string) ($metadata['alt_text'] ?? $asset->alt_text)),
            'caption' => trim((string) ($metadata['caption'] ?? $asset->caption)),
            'keywords_json' => $this->keywords($metadata['keywords'] ?? $asset->keywords_json),
            'sort_order' => max(0, (int) ($metadata['sort_order'] ?? $asset->sort_order)),
            'needs_review' => (bool) ($metadata['needs_review'] ?? $asset->needs_review),
        ])->save();

        return $asset->refresh();
    }

    public function setActive(KnowledgeMediaAsset $asset, Admin $admin, bool $active): KnowledgeMediaAsset
    {
        $knowledgeBase = $asset->knowledgeBase;
        if (! $knowledgeBase instanceof KnowledgeBase) {
            throw new RuntimeException('The media asset has no knowledge base.');
        }
        $this->authorize($knowledgeBase, $admin);

        try {
            return Cache::lock($this->assetLockKey($asset), 30)->block(5, function () use ($asset, $active): KnowledgeMediaAsset {
                return DB::transaction(function () use ($asset, $active): KnowledgeMediaAsset {
                    $locked = KnowledgeMediaAsset::query()->lockForUpdate()->findOrFail($asset->getKey());
                    if ($active) {
                        KnowledgeMediaAsset::query()
                            ->where('knowledge_base_id', $locked->knowledge_base_id)
                            ->where('asset_key', $locked->asset_key)
                            ->where('locale', $locked->locale)
                            ->whereKeyNot($locked->getKey())
                            ->update(['is_active' => false]);
                    }
                    $locked->forceFill(['is_active' => $active])->save();

                    return $locked->refresh();
                }, 3);
            });
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('Knowledge media is currently being updated. Please try again.', 0, $exception);
        }
    }

    public function isReadable(KnowledgeMediaAsset $asset): bool
    {
        return $this->readableFile($asset) !== null;
    }

    /** @return array{path:string,mime_type:string,content_hash:string}|null */
    public function readableFile(KnowledgeMediaAsset $asset, bool $thumbnail = false): ?array
    {
        $path = (string) ($thumbnail ? $asset->thumbnail_path : $asset->storage_path);
        $expectedPrefix = self::STORAGE_PREFIX.($thumbnail ? 'thumbnails/' : '').(string) $asset->content_hash.'.';
        if (! str_starts_with($path, $expectedPrefix)
            || ! preg_match('/\A'.preg_quote($expectedPrefix, '/').'(?:png|webp)\z/', $path)) {
            return null;
        }
        $disk = Storage::disk('local');
        try {
            if (! $disk->exists($path)) {
                return null;
            }
            $bytes = $disk->get($path);
        } catch (Throwable) {
            return null;
        }
        if (! is_string($bytes)) {
            return null;
        }
        $image = @getimagesizefromstring($bytes);
        $mime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
        if (! in_array($mime, ['image/png', 'image/webp'], true)) {
            return null;
        }
        $hash = hash('sha256', $bytes);
        if (! $thumbnail && ! hash_equals((string) $asset->content_hash, $hash)) {
            return null;
        }

        return ['path' => $path, 'mime_type' => $mime, 'content_hash' => $hash];
    }

    /** @return array<string, mixed> */
    public function answerPayload(KnowledgeMediaAsset $asset): array
    {
        return [
            'id' => (int) $asset->getKey(),
            'version' => (int) $asset->asset_version,
            'content_hash' => 'sha256:'.(string) $asset->content_hash,
            'title' => (string) $asset->title,
            'alt' => (string) $asset->alt_text,
            'caption' => (string) ($asset->caption ?? ''),
            'url' => route('admin.ai-workspace.media.show', ['mediaAsset' => $asset->getKey()], false),
            'thumbnail_url' => route('admin.ai-workspace.media.show', [
                'mediaAsset' => $asset->getKey(),
                'variant' => 'thumbnail',
            ], false),
            'width' => (int) $asset->width,
            'height' => (int) $asset->height,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{asset:KnowledgeMediaAsset,created:bool,changed:bool}
     */
    private function importBytes(
        KnowledgeBase $knowledgeBase,
        string $bytes,
        array $metadata,
        ?Admin $admin = null,
    ): array {
        if (! $knowledgeBase->isSystemManaged()) {
            throw new RuntimeException('Knowledge media can only be attached to system knowledge.');
        }
        if (strlen($bytes) === 0 || strlen($bytes) > 8 * 1024 * 1024) {
            throw new RuntimeException('Knowledge media must be between 1 byte and 8 MB.');
        }

        $image = @getimagesizefromstring($bytes);
        $mime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
        $width = is_array($image) ? (int) ($image[0] ?? 0) : 0;
        $height = is_array($image) ? (int) ($image[1] ?? 0) : 0;
        if (! in_array($mime, ['image/png', 'image/webp'], true) || $width < 1 || $height < 1 || max($width, $height) > 4096) {
            throw new RuntimeException('Knowledge media must be a valid PNG or WebP image with a longest edge up to 4096 pixels.');
        }

        $assetKey = trim((string) ($metadata['asset_key'] ?? ''));
        $sectionKey = trim((string) ($metadata['section_key'] ?? ''));
        $routeName = trim((string) ($metadata['route_name'] ?? ''));
        $title = trim((string) ($metadata['title'] ?? ''));
        $altText = trim((string) ($metadata['alt_text'] ?? ''));
        $locale = trim((string) ($metadata['locale'] ?? 'zh_CN'));
        if ($assetKey === '' || $sectionKey === '' || $title === '' || $altText === '' || ! preg_match('/\A[a-z0-9._-]+\z/', $assetKey)) {
            throw new RuntimeException('Knowledge media requires a stable asset key, section, title, and alt text.');
        }
        $route = app('router')->getRoutes()->getByName($routeName);
        if ($this->features->featureForRoute($routeName) === null
            || $route === null
            || ! in_array('GET', $route->methods(), true)
            || $route->parameterNames() !== []) {
            throw new RuntimeException('Knowledge media route must be a registered stable GET entry.');
        }

        $hash = hash('sha256', $bytes);
        $expectedHash = trim((string) ($metadata['content_hash'] ?? ''));
        if ($expectedHash !== '' && ! hash_equals(Str::after($expectedHash, 'sha256:'), $hash)) {
            throw new RuntimeException('Knowledge media content hash does not match its manifest.');
        }
        $extension = $mime === 'image/webp' ? 'webp' : 'png';
        $storagePath = self::STORAGE_PREFIX.$hash.'.'.$extension;
        $disk = Storage::disk('local');
        if (! $disk->exists($storagePath) && ! $disk->put($storagePath, $bytes)) {
            throw new RuntimeException('Unable to store the knowledge media file.');
        }
        [$thumbnailBytes, $thumbnailExtension] = $this->thumbnail($bytes, $mime, $width, $height);
        $thumbnailPath = self::STORAGE_PREFIX.'thumbnails/'.$hash.'.'.$thumbnailExtension;
        if (! $disk->exists($thumbnailPath) && ! $disk->put($thumbnailPath, $thumbnailBytes)) {
            throw new RuntimeException('Unable to store the knowledge media thumbnail.');
        }

        $lockKey = 'system-knowledge-media:'.$knowledgeBase->getKey().':'.sha1($assetKey.'|'.$locale);

        try {
            return Cache::lock($lockKey, 30)->block(5, fn (): array => DB::transaction(function () use (
                $knowledgeBase,
                $metadata,
                $admin,
                $assetKey,
                $sectionKey,
                $routeName,
                $title,
                $altText,
                $locale,
                $hash,
                $storagePath,
                $thumbnailPath,
                $mime,
                $width,
                $height,
            ): array {
                $current = KnowledgeMediaAsset::query()
                    ->whereBelongsTo($knowledgeBase)
                    ->where('asset_key', $assetKey)
                    ->where('locale', $locale)
                    ->orderByDesc('asset_version')
                    ->lockForUpdate()
                    ->first();
                $sameContent = $current instanceof KnowledgeMediaAsset && hash_equals((string) $current->content_hash, $hash);
                $capturedAppVersion = trim((string) ($metadata['captured_app_version'] ?? config('geoflow.app_version', '')));
                $payload = [
                    'section_key' => $sectionKey,
                    'route_name' => $routeName,
                    'title' => $title,
                    'alt_text' => $altText,
                    'caption' => trim((string) ($metadata['caption'] ?? '')),
                    'keywords_json' => $this->keywords($metadata['keywords'] ?? []),
                    'storage_path' => $storagePath,
                    'thumbnail_path' => $thumbnailPath,
                    'mime_type' => $mime,
                    'width' => $width,
                    'height' => $height,
                    'content_hash' => $hash,
                    'official_version' => trim((string) ($metadata['official_version'] ?? '')) ?: null,
                    'captured_at' => $metadata['captured_at'] ?? now(),
                    'captured_app_version' => $capturedAppVersion,
                    'sort_order' => max(0, (int) ($metadata['sort_order'] ?? 0)),
                    'is_active' => true,
                    'needs_review' => (bool) ($metadata['needs_review'] ?? false)
                        || $this->majorVersion($capturedAppVersion) !== $this->majorVersion((string) config('geoflow.app_version', '')),
                    'created_by_admin_id' => $admin?->getKey(),
                ];

                KnowledgeMediaAsset::query()
                    ->whereBelongsTo($knowledgeBase)
                    ->where('asset_key', $assetKey)
                    ->where('locale', $locale)
                    ->when($current instanceof KnowledgeMediaAsset, static fn ($query) => $query->whereKeyNot($current->getKey()))
                    ->update(['is_active' => false]);

                if ($sameContent) {
                    $current->forceFill($payload);
                    $changed = $current->isDirty();
                    $current->save();

                    return ['asset' => $current->refresh(), 'created' => false, 'changed' => $changed];
                }

                if ($current instanceof KnowledgeMediaAsset) {
                    $current->forceFill(['is_active' => false])->save();
                }
                $asset = KnowledgeMediaAsset::query()->create($payload + [
                    'knowledge_base_id' => $knowledgeBase->getKey(),
                    'asset_key' => $assetKey,
                    'asset_version' => $current instanceof KnowledgeMediaAsset ? (int) $current->asset_version + 1 : 1,
                    'supersedes_id' => $current?->getKey(),
                    'locale' => $locale,
                ]);

                return [
                    'asset' => $asset,
                    'created' => ! ($current instanceof KnowledgeMediaAsset),
                    'changed' => $current instanceof KnowledgeMediaAsset,
                ];
            }, 3));
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('Knowledge media is currently being updated. Please try again.', 0, $exception);
        }
    }

    /** @return list<string> */
    private function keywords(mixed $keywords): array
    {
        if (is_string($keywords)) {
            $keywords = preg_split('/[,，\n]+/u', $keywords) ?: [];
        }

        return collect(is_array($keywords) ? $keywords : [])
            ->map(static fn (mixed $keyword): string => trim((string) $keyword))
            ->filter()
            ->unique()
            ->take(30)
            ->values()
            ->all();
    }

    /** @return array{0:string,1:string} */
    private function thumbnail(string $bytes, string $mime, int $width, int $height): array
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return [$bytes, $mime === 'image/webp' ? 'webp' : 'png'];
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return [$bytes, $mime === 'image/webp' ? 'webp' : 'png'];
        }
        $targetWidth = min(720, $width);
        $targetHeight = max(1, (int) round($height * ($targetWidth / max(1, $width))));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $written = imagewebp($target, null, 82);
        $thumbnail = ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        return $written && is_string($thumbnail) && $thumbnail !== ''
            ? [$thumbnail, 'webp']
            : [$bytes, $mime === 'image/webp' ? 'webp' : 'png'];
    }

    private function authorize(KnowledgeBase $knowledgeBase, Admin $admin): void
    {
        if (! $knowledgeBase->isSystemManaged() || ! $admin->canManageProtectedWorkflows()) {
            throw new RuntimeException('Only protected workflow administrators can manage system knowledge media.');
        }
    }

    private function assetLockKey(KnowledgeMediaAsset $asset): string
    {
        return 'system-knowledge-media:'.$asset->knowledge_base_id.':'.sha1($asset->asset_key.'|'.$asset->locale);
    }

    private function majorVersion(string $version): string
    {
        return (string) Str::of($version)->trim()->before('.');
    }
}
