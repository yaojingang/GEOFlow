<?php

namespace Tests\Feature;

use App\Services\GeoFlow\KnowledgeSourceParser;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class KnowledgeSourceParserSafetyTest extends TestCase
{
    public function test_high_compression_docx_is_rejected_before_expansion(): void
    {
        if (! class_exists(ZipArchive::class) || ! class_exists(\XMLReader::class)) {
            $this->markTestSkipped('ZIP and XMLReader extensions are required.');
        }

        $archivePath = tempnam(sys_get_temp_dir(), 'geoflow-docx-');
        $this->assertIsString($archivePath);

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($archivePath, ZipArchive::OVERWRITE));
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'
                .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                .'<w:body><w:p><w:r><w:t>'
                .str_repeat('A', 2 * 1024 * 1024)
                .'</w:t></w:r></w:p></w:body></w:document>';
            $this->assertTrue($zip->addFromString('word/document.xml', $xml));
            $zip->close();

            app(KnowledgeSourceParser::class)->extractDocxContent($archivePath);
            $this->fail('A high-compression DOCX should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                __('admin.knowledge_bases.error.docx_expansion_too_large'),
                $exception->getMessage()
            );
        } finally {
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
        }
    }


    public function test_legacy_ppt_binary_is_rejected_with_dedicated_message(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'geoflow-ppt-');
        $this->assertIsString($path);
        file_put_contents($path, 'fake ppt binary');

        try {
            app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($path, 'legacy.ppt');
            $this->fail('Legacy PPT should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                __('admin.knowledge_bases.error.ppt_legacy_not_supported'),
                $exception->getMessage()
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_empty_pptx_is_rejected_as_invalid(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required.');
        }

        $path = sys_get_temp_dir() . '/geoflow-pptx-empty-' . bin2hex(random_bytes(4)) . '.pptx';
        @unlink($path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->close();

        try {
            app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($path, 'empty.pptx');
            $this->fail('An empty PPTX should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                __('admin.knowledge_bases.error.file_type_invalid'),
                $exception->getMessage()
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_high_compression_pptx_is_rejected_before_expansion(): void
    {
        if (! class_exists(ZipArchive::class) || ! class_exists(\XMLReader::class)) {
            $this->markTestSkipped('ZIP and XMLReader extensions are required.');
        }

        $path = sys_get_temp_dir() . '/geoflow-pptx-bomb-' . bin2hex(random_bytes(4)) . '.pptx';
        @unlink($path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:cSld><p:sp><p:txBody><a:p><a:r><a:t>'
            .str_repeat('A', 2 * 1024 * 1024)
            .'</a:t></a:r></a:p></p:txBody></p:sp></p:cSld></p:sld>';
        $this->assertTrue($zip->addFromString('ppt/slides/slide1.xml', $xml));
        $zip->close();

        try {
            app(KnowledgeSourceParser::class)->extractPptxContent($path);
            $this->fail('A high-compression PPTX should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                __('admin.knowledge_bases.error.pptx_expansion_too_large'),
                $exception->getMessage()
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_pptx_with_single_slide_is_parsed(): void
    {
        if (! class_exists(ZipArchive::class) || ! class_exists(\XMLReader::class)) {
            $this->markTestSkipped('ZIP and XMLReader extensions are required.');
        }

        $path = sys_get_temp_dir() . '/geoflow-pptx-ok-' . bin2hex(random_bytes(4)) . '.pptx';
        @unlink($path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $slide = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:cSld><p:sp><p:txBody><a:p><a:r><a:t>GEOFlow PPTX smoke test</a:t></a:r></a:p></p:txBody></p:sp></p:cSld></p:sld>';
        $this->assertTrue($zip->addFromString('ppt/slides/slide1.xml', $slide));
        $zip->close();

        $result = app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($path, 'smoke.pptx');
        $this->assertSame('presentation', $result['file_type']);
        $this->assertStringContainsString('GEOFlow PPTX smoke test', $result['content']);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function test_combined_upload_size_is_bounded_before_files_are_stored(): void
    {
        $files = [
            UploadedFile::fake()->create('first.txt', 5 * 1024, 'text/plain'),
            UploadedFile::fake()->create('second.txt', 4 * 1024, 'text/plain'),
        ];
        $storedPaths = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('admin.knowledge_bases.error.total_files_too_large'));

        app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFiles($files, $storedPaths);
    }
}
