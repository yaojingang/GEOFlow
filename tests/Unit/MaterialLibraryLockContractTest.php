<?php

namespace Tests\Unit;

use App\Services\GeoFlow\MaterialLibraryService;
use ReflectionMethod;
use Tests\TestCase;

class MaterialLibraryLockContractTest extends TestCase
{
    public function test_keyword_count_writes_and_library_deletes_keep_explicit_parent_locks(): void
    {
        foreach ([
            'createKeywordItem' => 'KeywordLibrary',
            'deleteKeywordItems' => 'KeywordLibrary',
            'createTitleItem' => 'TitleLibrary',
            'createImageItem' => 'ImageLibrary',
            'createUploadedImageItem' => 'ImageLibrary',
            'deleteTitleItems' => 'TitleLibrary',
            'deleteImageItems' => 'ImageLibrary',
            'deleteKeywordLibrary' => 'KeywordLibrary',
            'deleteTitleLibrary' => 'TitleLibrary',
            'deleteImageLibrary' => 'ImageLibrary',
        ] as $method => $model) {
            $source = $this->methodSource($method);

            $this->assertStringContainsString($model.'::query()', $source, "{$method} must select its parent model.");
            $this->assertStringContainsString('->lockForUpdate()', $source, "{$method} must retain its parent-row lock.");
        }
    }

    public function test_both_image_create_paths_lock_the_parent_before_the_image_insert_and_real_count_refresh(): void
    {
        foreach ([
            'createImageItem' => 'withExistingPathLock(',
            'createUploadedImageItem' => 'storeUploadedImage(',
        ] as $method => $pathWrite) {
            $source = $this->methodSource($method);
            $parentPosition = strpos($source, 'ImageLibrary::query()');
            $pathWritePosition = strpos($source, $pathWrite);
            $insertPosition = strpos($source, 'createImageFromMetadata(');
            $countPosition = strpos($source, 'refreshImageLibraryCount(');

            $this->assertIsInt($parentPosition);
            $this->assertIsInt($pathWritePosition);
            $this->assertIsInt($insertPosition);
            $this->assertIsInt($countPosition);
            $this->assertLessThan($pathWritePosition, $parentPosition, "{$method} must lock the parent before path coordination or file writes.");
            $this->assertLessThan($insertPosition, $parentPosition, "{$method} must lock the parent before inserting the image.");
            $this->assertLessThan($countPosition, $insertPosition, "{$method} must refresh the real count after inserting the image.");
        }
    }

    public function test_image_operation_guards_lock_the_parent_before_path_coordination(): void
    {
        foreach (['withLegacyImagePathLock', 'withUploadedImagePathLock'] as $method) {
            $source = $this->methodSource($method);
            $parentPosition = strpos($source, 'ImageLibrary::query()');
            $pathLockPosition = strpos($source, '$this->managedImages->');

            $this->assertIsInt($parentPosition);
            $this->assertIsInt($pathLockPosition);
            $this->assertLessThan($pathLockPosition, $parentPosition, "{$method} must keep the parent-to-path lock order.");
        }
    }

    public function test_item_deletes_use_one_retryable_outer_transaction_without_nested_transactions(): void
    {
        $deleteItems = $this->methodSource('deleteItems');

        $this->assertStringContainsString('DB::transaction(', $deleteItems);
        $this->assertStringContainsString('}, 3);', $deleteItems);

        foreach (['deleteKeywordItems', 'deleteTitleItems', 'deleteImageItems'] as $method) {
            $source = $this->methodSource($method);

            $this->assertStringNotContainsString('DB::transaction(', $source, "{$method} must use the outer physical transaction.");
        }
    }

    public function test_item_and_library_deletes_lock_the_parent_before_touching_children(): void
    {
        foreach ([
            'deleteKeywordItems' => ['KeywordLibrary', 'Keyword'],
            'deleteTitleItems' => ['TitleLibrary', 'Title'],
            'deleteImageItems' => ['ImageLibrary', 'Image'],
            'deleteKeywordLibrary' => ['KeywordLibrary', 'Keyword'],
            'deleteTitleLibrary' => ['TitleLibrary', 'Title'],
            'deleteImageLibrary' => ['ImageLibrary', 'Image'],
        ] as $method => [$parent, $child]) {
            $source = $this->methodSource($method);
            $parentPosition = strpos($source, $parent.'::query()');
            $childPosition = strpos($source, $child.'::query()');

            $this->assertIsInt($parentPosition);
            $this->assertIsInt($childPosition);
            $this->assertLessThan($childPosition, $parentPosition, "{$method} must keep the parent-to-child lock order.");
        }
    }

    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(MaterialLibraryService::class, $method);
        $lines = file($reflection->getFileName());
        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
