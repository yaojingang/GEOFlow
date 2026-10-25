<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Task;
use App\Services\GeoFlow\Distribution\PlatformWeb\PlatformWebContentFormatter;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServiceImageSelectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ai_inserted_images_are_detected_and_content_left_untouched(): void
    {
        $library = $this->imageLibrary();
        $used = $this->image($library->id, 'storage/uploads/images/storefront.jpg', '门店实拍');
        $this->image($library->id, 'storage/uploads/images/product.jpg', '产品特写');
        $task = $this->task($library->id, 3);

        $imageUrls = ImageUrlNormalizer::toPublicUrl('storage/uploads/images/storefront.jpg');
        $content = "第一段正文。\n\n![门店实拍](".$imageUrls.")\n\n第二段正文。";

        $result = $this->insertImagesIntoContent($task, $content);

        $this->assertSame($content, $result['content']);
        $this->assertSame([$used->id], array_map(static fn (Image $image): int => (int) $image->id, $result['images']));
    }

    public function test_zero_image_count_excludes_image_context_and_generated_library_images(): void
    {
        $library = $this->imageLibrary();
        $image = $this->image($library->id, 'storage/uploads/images/storefront.jpg', '门店实拍');
        $task = $this->task($library->id, 0);
        $url = ImageUrlNormalizer::toPublicUrl($image->file_path);
        $result = $this->insertImagesIntoContent($task, "正文。\n\n![门店]($url)");
        $this->assertSame('', $this->buildImageContext($task));
        $this->assertSame([], $result['images']);
        $this->assertStringNotContainsString('![', $result['content']);
    }

    public function test_generated_library_images_respect_task_count(): void
    {
        $library = $this->imageLibrary();
        $a = $this->image($library->id, 'storage/uploads/images/a.jpg', 'A');
        $b = $this->image($library->id, 'storage/uploads/images/b.jpg', 'B');
        $content = '正文。'."\n\n![a](".ImageUrlNormalizer::toPublicUrl($a->file_path).")\n\n![b](".ImageUrlNormalizer::toPublicUrl($b->file_path).')';
        $result = $this->insertImagesIntoContent($this->task($library->id, 1), $content);
        $this->assertCount(1, $result['images']);
        $this->assertSame(1, substr_count($result['content'], '!['));
    }

    public function test_falls_back_to_interval_insertion_when_model_inserted_none(): void
    {
        $library = $this->imageLibrary();
        $this->image($library->id, 'storage/uploads/images/storefront.jpg', '门店实拍');
        $this->image($library->id, 'storage/uploads/images/product.jpg', '产品特写');
        $task = $this->task($library->id, 2);
        $content = "第一段正文。\n\n第二段正文。\n\n第三段正文。";

        $result = $this->insertImagesIntoContent($task, $content);

        $this->assertCount(2, $result['images']);
        $this->assertSame(2, substr_count($result['content'], '!['));
    }

    public function test_task_without_library_returns_content_unchanged(): void
    {
        $task = $this->task(0, 2);
        $content = '第一段正文。';

        $result = $this->insertImagesIntoContent($task, $content);

        $this->assertSame($content, $result['content']);
        $this->assertSame([], $result['images']);
    }

    public function test_build_image_context_lists_library_images_with_remarks(): void
    {
        $library = $this->imageLibrary();
        $this->image($library->id, 'storage/uploads/images/storefront.jpg', '门店实拍照片');
        $this->image($library->id, 'storage/uploads/images/unnamed.jpg', '');

        $context = $this->buildImageContext($this->task($library->id, 2));

        $this->assertStringContainsString('- 门店实拍照片 | /storage/uploads/images/storefront.jpg', $context);
        // 无备注的图片回退使用文件名（非图片扩展名部分）作为说明。
        $this->assertStringContainsString('/storage/uploads/images/unnamed.jpg', $context);
    }

    public function test_admin_can_update_image_tags_for_ai_matching(): void
    {
        $library = $this->imageLibrary();
        $image = $this->image($library->id, 'storage/uploads/images/storefront.jpg', '');
        $admin = Admin::query()->create([
            'username' => uniqid('image_tags_', true),
            'password' => 'secret-123',
            'email' => uniqid('image-tags-', true).'@example.test',
            'display_name' => 'Image Tags Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.image-libraries.images.tags', ['libraryId' => $library->id, 'imageId' => $image->id]), [
                'tags' => '门店外观实拍',
            ])
            ->assertRedirect()
            ->assertSessionHas('message');

        $this->assertSame('门店外观实拍', (string) $image->fresh()->tags);
    }

    public function test_final_renderer_enforces_reference_image_zero_candidates_and_count(): void
    {
        $library = $this->imageLibrary();
        $a = $this->image($library->id, 'storage/uploads/images/a.jpg', 'A');
        $b = $this->image($library->id, 'storage/uploads/images/b.jpg', 'B');
        $aUrl = ImageUrlNormalizer::toPublicUrl($a->file_path);
        $bUrl = ImageUrlNormalizer::toPublicUrl($b->file_path);
        $content = "Ordinary **text** and `![demo][a]`.\n\n![picture][a] ![b][] ![a] ![outside][x]\n\n> ![multi\n> line][a]\n\n[a]: $aUrl\n[b]: $bUrl\n[x]: https://outside.example/photo.jpg";
        foreach ([0, 1] as $count) {
            $result = $this->insertImagesIntoContent($this->task($library->id, $count), $content);
            $html = ArticleHtmlPresenter::markdownToHtml($result['content']);
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $this->assertSame($count, $dom->getElementsByTagName('img')->length);
            $this->assertStringContainsString('<strong>text</strong>', $html);
            $this->assertStringContainsString('<code>![demo][a]</code>', $html);
            $this->assertStringNotContainsString('src="https://outside.example', $html);
        }
    }

    public function test_final_renderer_enforces_images_inside_table_cells(): void
    {
        $library = $this->imageLibrary();
        $a = $this->image($library->id, 'storage/uploads/images/table-a.jpg', 'A');
        $b = $this->image($library->id, 'storage/uploads/images/table-b.jpg', 'B');
        $aUrl = ImageUrlNormalizer::toPublicUrl($a->file_path);
        $bUrl = ImageUrlNormalizer::toPublicUrl($b->file_path);
        foreach (['', '> ', '  > '] as $prefix) {
            $headerPrefix = $prefix === '  > ' ? '- > ' : $prefix;
            $table = $headerPrefix.'| 中文说明 | Illustration ![header][x] |'."\n"
                .$prefix.'| --- | --- |'."\n"
                .$prefix.'| 保留 **文字** 与 `![picture][shot]` | ![picture][shot] ![second][b] ![outside][x] |'."\n"
                .$prefix.'| 转义 \| 管道 | ![picture][shot] |'."\n"
                .$prefix.'| `![same](https://outside.example/same.jpg)` | ![same](https://outside.example/same.jpg) |';
            $content = $table."\n\n[shot]: $aUrl\n[b]: $bUrl\n[x]: https://outside.example/photo.jpg";
            foreach ([0, 1] as $count) {
                $result = $this->insertImagesIntoContent($this->task($library->id, $count), $content);
                $html = ArticleHtmlPresenter::markdownToHtml($result['content']);
                $dom = new \DOMDocument;
                @$dom->loadHTML($html);
                $this->assertSame($count, $dom->getElementsByTagName('img')->length);
                $this->assertSame(1, $dom->getElementsByTagName('table')->length);
                $this->assertStringContainsString('中文说明', $html);
                $this->assertStringContainsString('<strong>文字</strong>', $html);
                $this->assertStringContainsString('<code>![picture][shot]</code>', $html);
                $this->assertStringContainsString('转义 | 管道', $html);
                $this->assertStringContainsString('<code>![same](https://outside.example/same.jpg)</code>', $html);
                $this->assertStringNotContainsString('src="https://outside.example', $html);
            }
        }
    }

    public function test_filtering_images_preserves_final_rendering_and_selected_metadata(): void
    {
        $library = $this->imageLibrary();
        $a = $this->image($library->id, 'storage/uploads/images/outer.jpg', 'Outer');
        $b = $this->image($library->id, 'storage/uploads/images/inner.jpg', 'Inner');
        $aUrl = ImageUrlNormalizer::toPublicUrl($a->file_path);
        $bUrl = ImageUrlNormalizer::toPublicUrl($b->file_path);
        $cases = [
            "!![first](https://outside.example/first.jpg)[second](https://outside.example/second.jpg)\n\nKeep Omega paragraph.",
            "![first](https://outside.example/first.jpg)\n\n    ![example](https://outside.example/example.jpg)\n\nKeep Omega paragraph.",
            'Intro text. ![outer ![inner](https://outside.example/inner.jpg)](https://outside.example/outer.jpg) Keep Omega paragraph.',
            "head | b\n--- | ---\n1. ![p](https://outside.example/a.jpg) | text\n\nKeep Omega paragraph.",
            "head | b\n--- | ---\n- ![p](https://outside.example/a.jpg) | text\n\nKeep Omega paragraph.",
            "Intro text. ![outer ![inner]($bUrl)]($aUrl) Keep Omega paragraph.",
            "Intro text. ![outer ![inner]($bUrl)](https://outside.example/outer.jpg) Keep Omega paragraph.",
        ];
        foreach ($cases as $case) {
            foreach ([0, 1] as $count) {
                $result = $this->insertImagesIntoContent($this->task($library->id, $count), $case);
                $html = ArticleHtmlPresenter::markdownToHtml($result['content']);
                $dom = new \DOMDocument;
                @$dom->loadHTML($html);
                $urls = [];
                foreach ($dom->getElementsByTagName('img') as $image) {
                    $urls[] = $image->getAttribute('src');
                }
                $selected = array_map(fn (Image $image) => ImageUrlNormalizer::toPublicUrl($image->file_path), $result['images']);
                $this->assertSame($selected, $urls, $case);
                $this->assertCount($count, $urls, $case);
                $this->assertSame($urls, array_values(array_unique($urls)));
                $this->assertStringContainsString('Keep Omega paragraph.', $html);
                $this->assertStringNotContainsString('src="https://outside.example', $html);
                $this->assertStringNotContainsString('&#32;', $html);
                $plain = PlatformWebContentFormatter::fromSnapshot([
                    'content' => $result['content'], 'content_html' => $html,
                ]);
                $this->assertStringNotContainsString('&#32;', $plain);
                $this->assertStringContainsString('Keep Omega paragraph.', $plain);
                $summary = ArticleHtmlPresenter::cardSummary(new Article(['content' => $result['content']]), 2000);
                $this->assertStringNotContainsString('&#32;', $summary);
                if (str_contains($case, '    ![example]')) {
                    $this->assertStringContainsString('<code>![example]', $html);
                }
                if ($count === 1 && str_contains($case, "]($aUrl)")) {
                    $this->assertSame([$a->id], array_map(fn (Image $image) => $image->id, $result['images']));
                }
            }
        }
    }

    private function insertImagesIntoContent(Task $task, string $content): array
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'insertTaskImagesIntoContent');
        $method->setAccessible(true);

        /** @var array{content:string,images:list<Image>} $result */
        $result = $method->invoke($service, $task, $content);

        return $result;
    }

    private function buildImageContext(Task $task): string
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildImageContext');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $task);
    }

    private function imageLibrary(): ImageLibrary
    {
        return ImageLibrary::query()->create([
            'name' => '智能配图测试库',
            'description' => '用于 AI 选图测试。',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
    }

    private function image(int $libraryId, string $filePath, string $tags): Image
    {
        return Image::query()->create([
            'library_id' => $libraryId,
            'filename' => basename($filePath),
            'original_name' => basename($filePath),
            'file_name' => basename($filePath),
            'file_path' => $filePath,
            'managed_path_hash' => md5($filePath),
            'file_size' => 1024,
            'mime_type' => 'image/jpeg',
            'width' => 800,
            'height' => 600,
            'tags' => $tags,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
    }

    private function task(int $libraryId, int $imageCount): Task
    {
        return new Task([
            'image_library_id' => $libraryId,
            'image_count' => $imageCount,
        ]);
    }
}
