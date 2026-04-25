<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticleImage;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Task;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

/**
 * 图片库管理控制器。
 */
class ImageLibraryController extends Controller
{
    private const DETAIL_PER_PAGE = 24;

    /**
     * 列表页。
     */
    public function index(): View
    {
        return view('admin.image-libraries.index', [
            'pageTitle' => __('admin.image_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'libraries' => $this->loadLibraries(),
            'stats' => $this->loadStats(),
        ]);
    }

    /**
     * 图片库详情页。
     */
    public function detail(Request $request, int $libraryId): View|RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        $search = trim((string) $request->query('search', ''));
        $images = $this->loadDetailImages($libraryId, $search);
        $usageTotal = (int) ArticleImage::query()
            ->whereHas('image', function ($query) use ($libraryId): void {
                $query->where('library_id', $libraryId);
            })
            ->count();

        return view('admin.image-libraries.detail', [
            'pageTitle' => (string) $library->name.' - '.__('admin.image_detail.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'search' => $search,
            'images' => $images,
            'usageTotal' => $usageTotal,
            'totalImages' => Image::query()->where('library_id', $libraryId)->count(),
        ]);
    }

    /**
     * 详情页更新图片库基本信息。
     */
    public function updateFromDetail(Request $request, int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ], [
            'name.required' => __('admin.image_libraries.error.name_required'),
        ]);

        $library->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);

        return redirect()->route('admin.image-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.image_detail.message.update_success'));
    }

    /**
     * 上传多张图片到指定图片库。
     */
    public function uploadImages(Request $request, int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'gif', 'webp'])->max(10 * 1024),
            ],
        ], [
            'images.required' => __('admin.image_detail.error.select_images'),
        ]);

        /** @var array<int, UploadedFile> $uploadedFiles */
        $uploadedFiles = $request->file('images', []);
        if ($uploadedFiles === []) {
            return back()->withErrors(__('admin.image_detail.error.select_images'));
        }

        $uploadedCount = 0;
        $skippedCount = 0;
        DB::transaction(function () use ($uploadedFiles, $libraryId, &$uploadedCount, &$skippedCount): void {
            foreach ($uploadedFiles as $uploadedFile) {
                try {
                    $stored = $this->storeUploadedImageFile($uploadedFile);
                    Image::query()->create([
                        'library_id' => $libraryId,
                        'filename' => $stored['filename'],
                        'original_name' => $stored['original_name'],
                        'file_name' => $stored['file_name'],
                        'file_path' => $stored['file_path'],
                        'file_size' => $stored['file_size'],
                        'mime_type' => $stored['mime_type'],
                        'width' => $stored['width'],
                        'height' => $stored['height'],
                        'tags' => '',
                        'used_count' => 0,
                        'usage_count' => 0,
                    ]);
                    $uploadedCount++;
                } catch (\Throwable) {
                    $skippedCount++;
                }
            }

            $this->refreshImageLibraryCount($libraryId);
        });

        if ($uploadedCount <= 0) {
            return back()->withErrors(__('admin.image_detail.error.upload_none'));
        }

        $message = __('admin.image_detail.message.upload_success', ['count' => $uploadedCount]);
        if ($skippedCount > 0) {
            $message .= __('admin.image_detail.message.upload_skipped', ['count' => $skippedCount]);
        }

        return redirect()->route('admin.image-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    /**
     * 删除图片（支持单条/批量）。
     */
    public function destroyImages(Request $request, int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        /** @var array<int, mixed> $rawIds */
        $rawIds = (array) $request->input('image_ids', []);
        $imageIds = collect($rawIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();
        if ($imageIds->isEmpty()) {
            return back()->withErrors(__('admin.image_detail.error.select_delete'));
        }

        $filePaths = Image::query()
            ->where('library_id', $libraryId)
            ->whereIn('id', $imageIds->all())
            ->pluck('file_path')
            ->filter()
            ->values()
            ->all();

        ArticleImage::query()
            ->whereIn('image_id', $imageIds->all())
            ->delete();

        $deletedCount = Image::query()
            ->where('library_id', $libraryId)
            ->whereIn('id', $imageIds->all())
            ->delete();
        $this->refreshImageLibraryCount($libraryId);
        $cleanupFailed = $this->cleanupFiles($filePaths);

        $message = __('admin.image_detail.message.delete_success', ['count' => $deletedCount]);
        if ($cleanupFailed > 0) {
            $message .= __('admin.image_detail.message.delete_cleanup_partial', ['count' => $cleanupFailed]);
        }

        return redirect()->route('admin.image-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.image-libraries.form', [
            'pageTitle' => __('admin.image_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'libraryId' => 0,
            'libraryForm' => $this->emptyForm(),
        ]);
    }

    /**
     * 创建图片库。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ], [
            'name.required' => __('admin.image_libraries.error.name_required'),
        ]);

        ImageLibrary::query()->create([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'image_count' => 0,
            'used_task_count' => 0,
        ]);

        return redirect()->route('admin.image-libraries.index')->with('message', __('admin.image_libraries.message.create_success'));
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $libraryId): View|RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.image-libraries.form', [
            'pageTitle' => __('admin.image_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'libraryId' => (int) $library->id,
            'libraryForm' => [
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
            ],
        ]);
    }

    /**
     * 更新图片库。
     */
    public function update(Request $request, int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ], [
            'name.required' => __('admin.image_libraries.error.name_required'),
        ]);

        $library->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);

        return redirect()->route('admin.image-libraries.index')->with('message', __('admin.image_libraries.message.update_success'));
    }

    /**
     * 删除图片库，并尝试删除关联文件。
     */
    public function destroy(int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        $taskCount = Task::query()->where('image_library_id', $libraryId)->count();
        if ($taskCount > 0) {
            return back()->withErrors(__('admin.image_libraries.error.in_use', ['count' => $taskCount]));
        }

        $filePaths = Image::query()->where('library_id', $libraryId)->pluck('file_path')->filter()->values()->all();
        Image::query()->where('library_id', $libraryId)->delete();

        $library->delete();
        $cleanupFailed = $this->cleanupFiles($filePaths);

        $message = __('admin.image_libraries.message.delete_success');
        if ($cleanupFailed > 0) {
            $message .= __('admin.image_libraries.message.delete_cleanup_partial', ['count' => $cleanupFailed]);
        }

        return redirect()->route('admin.image-libraries.index')->with('message', $message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLibraries(): array
    {
        $query = ImageLibrary::query()
            ->select(['id', 'name', 'description', 'created_at', 'updated_at'])
            ->withCount('images as actual_count')
            ->withSum('images as total_size', 'file_size')
            ->orderByDesc('created_at');

        return $query->get()->map(static function (ImageLibrary $library): array {
            return [
                'id' => (int) $library->id,
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
                'actual_count' => (int) ($library->actual_count ?? 0),
                'total_size' => (int) ($library->total_size ?? 0),
                'created_at' => $library->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $library->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    /**
     * @return array{total_libraries:int,total_images:int,total_size:int,avg_images:float}
     */
    private function loadStats(): array
    {
        $totalLibraries = ImageLibrary::query()->count();
        $totalImages = Image::query()->count();
        $totalSize = (int) (Image::query()->sum('file_size') ?? 0);

        return [
            'total_libraries' => $totalLibraries,
            'total_images' => $totalImages,
            'total_size' => $totalSize,
            'avg_images' => $totalLibraries > 0 ? round($totalImages / $totalLibraries, 1) : 0.0,
        ];
    }

    /**
     * 删除磁盘文件（仅清理本地相对路径）。
     *
     * @param  list<string>  $paths
     */
    private function cleanupFiles(array $paths): int
    {
        $failed = 0;
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            /**
             * 新上传文件统一落在 public 磁盘（storage/app/public）；
             * 这里优先按 Laravel Storage 删除，兼容旧路径再做兜底 unlink。
             */
            if (str_starts_with($path, 'storage/')) {
                $relativePublicPath = ltrim(substr($path, strlen('storage/')), '/');
                if ($relativePublicPath === '') {
                    continue;
                }
                if (! Storage::disk('public')->delete($relativePublicPath) && Storage::disk('public')->exists($relativePublicPath)) {
                    $failed++;
                }

                continue;
            }

            if (str_starts_with($path, 'uploads/')) {
                $legacyPublicAbsolutePath = public_path($path);
                if (is_file($legacyPublicAbsolutePath) && ! @unlink($legacyPublicAbsolutePath)) {
                    $failed++;
                }

                continue;
            }

            $legacyAbsolutePath = base_path($path);
            if (is_file($legacyAbsolutePath) && ! @unlink($legacyAbsolutePath)) {
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * @return array{name:string,description:string}
     */
    private function emptyForm(): array
    {
        return [
            'name' => '',
            'description' => '',
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Image>
     */
    private function loadDetailImages(int $libraryId, string $search): LengthAwarePaginator
    {
        $query = Image::query()
            ->where('library_id', $libraryId)
            ->orderByDesc('created_at');
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('original_name', 'like', '%'.$search.'%')
                    ->orWhere('filename', 'like', '%'.$search.'%')
                    ->orWhere('file_name', 'like', '%'.$search.'%');
            });
        }

        return $query->paginate(self::DETAIL_PER_PAGE)->withQueryString();
    }

    /**
     * 维护图片库图片计数字段，保持首页统计一致。
     */
    private function refreshImageLibraryCount(int $libraryId): void
    {
        $count = Image::query()->where('library_id', $libraryId)->count();
        ImageLibrary::query()->whereKey($libraryId)->update([
            'image_count' => $count,
        ]);
    }

    /**
     * @return array{filename:string,file_name:string,original_name:string,file_path:string,file_size:int,mime_type:string,width:int,height:int}
     */
    private function storeUploadedImageFile(UploadedFile $file): array
    {
        $uploadDirectory = 'images/'.date('Y/m');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = bin2hex(random_bytes(16)).'.'.$extension;
        $storedRelativePath = Storage::disk('public')->putFileAs('uploads/'.$uploadDirectory, $file, $filename);
        if (! is_string($storedRelativePath) || $storedRelativePath === '') {
            throw new \RuntimeException('保存图片失败');
        }
        $targetPath = Storage::disk('public')->path($storedRelativePath);

        $imageInfo = @getimagesize($targetPath) ?: [0, 0, null, null, 'mime' => (string) $file->getMimeType()];

        return [
            'filename' => $filename,
            'file_name' => $filename,
            'original_name' => (string) $file->getClientOriginalName(),
            'file_path' => 'storage/'.$storedRelativePath,
            'file_size' => (int) filesize($targetPath),
            'mime_type' => (string) ($imageInfo['mime'] ?? $file->getMimeType() ?? ''),
            'width' => (int) ($imageInfo[0] ?? 0),
            'height' => (int) ($imageInfo[1] ?? 0),
        ];
    }
}
