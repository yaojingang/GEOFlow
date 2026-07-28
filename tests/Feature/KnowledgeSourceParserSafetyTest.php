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
