<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Services\GeoFlow\ManagedImageFileService;
use App\Support\Admin\WeChatArticleHtmlExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\File;

class ArticleEditorAssetController extends Controller
{
    private const EDITOR_LIBRARY_NAME = '文章编辑器图片';

    public function __construct(private readonly ManagedImageFileService $managedImages) {}

    public function exportWeChatHtml(Request $request, WeChatArticleHtmlExporter $exporter): JsonResponse
    {
        $payload = $request->validate([
            'content' => ['required', 'string', 'max:1000000'],
        ], [
            'content.required' => __('admin.article_editor.copy.empty'),
        ]);

        $html = $exporter->toHtml((string) $payload['content']);
        if ($html === '') {
            return response()->json([
                'message' => __('admin.article_editor.copy.empty'),
            ], 422);
        }

        return response()->json([
            'message' => __('admin.article_editor.wechat.success'),
            'html' => $html,
            'plain' => $exporter->toPlainText($html),
        ]);
    }

    public function uploadImage(Request $request, int $articleId): JsonResponse
    {
        $article = Article::query()->whereKey($articleId)->firstOrFail();

        $payload = $request->validate([
            'image' => [
                'required',
                File::image()->types(['jpg', 'jpeg', 'png', 'gif', 'webp'])->max(10 * 1024),
            ],
            'alt' => ['nullable', 'string', 'max:120'],
            'position' => ['nullable', 'integer', 'min:0'],
        ], [
            'image.required' => __('admin.article_editor.error.image_required'),
            'image.image' => __('admin.article_editor.error.image_invalid'),
            'image.max' => __('admin.article_editor.error.image_too_large'),
        ]);

        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->file('image');
        $alt = $this->normalizeAlt((string) ($payload['alt'] ?? ''));
        $position = max(0, (int) ($payload['position'] ?? 0));
        $storedPath = null;

        try {
            $result = $this->managedImages->withUploadedImagePathLock($uploadedFile, function () use ($article, $uploadedFile, $alt, $position, &$storedPath): array {
                return DB::transaction(function () use ($article, $uploadedFile, $alt, $position, &$storedPath): array {
                    $library = $this->editorImageLibrary();
                    $stored = $this->managedImages->storeUploadedImage($uploadedFile);
                    $storedPath = $stored['file_path'];

                    $image = Image::query()->create([
                        'library_id' => (int) $library->id,
                        'filename' => $stored['filename'],
                        'original_name' => $alt !== '' ? $alt : $stored['original_name'],
                        'file_name' => $stored['file_name'],
                        'file_path' => $stored['file_path'],
                        'managed_path_hash' => $stored['managed_path_hash'],
                        'file_size' => $stored['file_size'],
                        'mime_type' => $stored['mime_type'],
                        'width' => $stored['width'],
                        'height' => $stored['height'],
                        'tags' => $alt,
                        'used_count' => 1,
                        'usage_count' => 1,
                    ]);

                    ArticleImage::query()->create([
                        'article_id' => (int) $article->id,
                        'image_id' => (int) $image->id,
                        'position' => $position,
                    ]);

                    $this->refreshImageLibraryCount((int) $library->id);

                    $url = '/'.ltrim((string) $stored['file_path'], '/');
                    $imageAlt = $alt !== '' ? $alt : $this->readableAlt($stored['original_name']);

                    return [
                        'id' => (int) $image->id,
                        'url' => $url,
                        'alt' => $imageAlt,
                        'markdown' => '!['.$this->escapeMarkdownAlt($imageAlt).']('.$url.')',
                        'width' => (int) $stored['width'],
                        'height' => (int) $stored['height'],
                    ];
                });
            });
        } catch (\Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                $this->managedImages->discardStoredUpload($storedPath);
            }

            if (! $exception instanceof \RuntimeException) {
                report($exception);
            }

            $message = $exception instanceof \RuntimeException
                ? __('admin.article_editor.error.upload_failed', ['message' => $exception->getMessage()])
                : __('admin.article_editor.error.upload_failed_generic');

            return response()->json([
                'message' => $message,
            ], 422);
        }

        return response()->json([
            'message' => __('admin.article_editor.message.upload_success'),
            'image' => $result,
        ]);
    }

    private function editorImageLibrary(): ImageLibrary
    {
        return ImageLibrary::query()->firstOrCreate([
            'name' => self::EDITOR_LIBRARY_NAME,
        ], [
            'description' => __('admin.article_editor.image_library_description'),
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
    }

    private function refreshImageLibraryCount(int $libraryId): void
    {
        ImageLibrary::query()->whereKey($libraryId)->update([
            'image_count' => Image::query()->where('library_id', $libraryId)->count(),
        ]);
    }

    private function readableAlt(string $fileName): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $name = preg_replace('/[-_]+/u', ' ', $name) ?: '';
        $name = $this->normalizeAlt($name);

        return $name !== '' ? $name : 'image';
    }

    private function normalizeAlt(string $alt): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $alt));
    }

    private function escapeMarkdownAlt(string $alt): string
    {
        return str_replace([']', '['], ['\\]', '\\['], $alt);
    }
}
