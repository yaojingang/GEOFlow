<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticleImage;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Task;
use App\Services\GeoFlow\ManagedImageFileService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

/**
 * 图片库管理控制器。
 */
class ImageLibraryController extends Controller
{
    private const DETAIL_PER_PAGE = 24;

    public function __construct(private readonly ManagedImageFileService $managedImages) {}

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
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'gif', 'webp'])
                    ->max(max(1, (int) ceil((int) config('geoflow.max_upload_bytes', 2 * 1024 * 1024) / 1024))),
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
        $uploadErrors = [];
        foreach ($uploadedFiles as $uploadedFile) {
            $stored = null;
            try {
                $this->managedImages->withUploadedImagePathLock($uploadedFile, function () use ($uploadedFile, $libraryId, &$stored): void {
                    $stored = $this->managedImages->storeUploadedImage($uploadedFile);
                    DB::transaction(function () use ($stored, $libraryId): void {
                        Image::query()->create([
                            'library_id' => $libraryId,
                            'filename' => $stored['filename'],
                            'original_name' => $stored['original_name'],
                            'file_name' => $stored['file_name'],
                            'file_path' => $stored['file_path'],
                            'managed_path_hash' => $stored['managed_path_hash'],
                            'file_size' => $stored['file_size'],
                            'mime_type' => $stored['mime_type'],
                            'width' => $stored['width'],
                            'height' => $stored['height'],
                            'tags' => '',
                            'used_count' => 0,
                            'usage_count' => 0,
                        ]);
                        $this->refreshImageLibraryCount($libraryId);
                    });
                });
                $uploadedCount++;
            } catch (\Throwable $exception) {
                if (is_array($stored) && isset($stored['file_path'])) {
                    $this->managedImages->discardStoredUpload((string) $stored['file_path']);
                }
                $skippedCount++;
                $uploadErrors[] = $exception->getMessage();
                Log::warning('geoflow.image_upload_failed', [
                    'library_id' => $libraryId,
                    'original_name' => $uploadedFile->getClientOriginalName(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($uploadedCount <= 0) {
            $firstError = trim((string) ($uploadErrors[0] ?? ''));

            return back()->withErrors($firstError !== ''
                ? __('admin.image_detail.error.upload_failed_detail', ['message' => $firstError])
                : __('admin.image_detail.error.upload_none'));
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

        [$filePaths, $deletedCount] = DB::transaction(function () use ($libraryId, $imageIds): array {
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

            return [$filePaths, $deletedCount];
        });
        $cleanupFailed = $this->managedImages->cleanupUnreferenced($filePaths);

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

        $filePaths = DB::transaction(function () use ($library, $libraryId): array {
            $images = Image::query()->where('library_id', $libraryId)->get(['id', 'file_path']);
            ArticleImage::query()->whereIn('image_id', $images->pluck('id')->all())->delete();
            Image::query()->where('library_id', $libraryId)->delete();
            $library->delete();

            return $images->pluck('file_path')->filter()->values()->all();
        });
        $cleanupFailed = $this->managedImages->cleanupUnreferenced($filePaths);

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
}
