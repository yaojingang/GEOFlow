<?php

namespace Tests\Feature;

use App\Services\GeoFlow\KnowledgeSourceParser;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\KnowledgeDocumentFixtures;
use Tests\TestCase;

class KnowledgeDocumentParserTest extends TestCase
{
    public function test_pptx_with_no_body_text_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('blank.pptx', KnowledgeDocumentFixtures::pptx([1 => '', 2 => '  ']));

        $this->expectException(RuntimeException::class);
        app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), $file->getClientOriginalName());
    }

    public function test_pptx_body_follows_the_presentation_relationship_order(): void
    {
        $file = UploadedFile::fake()->createWithContent('reordered.pptx', KnowledgeDocumentFixtures::pptx([
            1 => 'Final conclusion',
            2 => 'Opening context',
            3 => 'Evidence in the middle',
        ], [2, 3, 1]));

        $parsed = app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), $file->getClientOriginalName());

        $this->assertSame('presentation', $parsed['file_type']);
        $this->assertSame("# Slide 1\n\nOpening context\n\n# Slide 2\n\nEvidence in the middle\n\n# Slide 3\n\nFinal conclusion", $parsed['content']);
    }

    public function test_valid_pdf_text_is_extracted_with_its_file_type(): void
    {
        $file = UploadedFile::fake()->createWithContent('source.pdf', KnowledgeDocumentFixtures::pdf('Source evidence in a PDF.', true));

        $parsed = app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), $file->getClientOriginalName());

        $this->assertSame(['content' => 'Source evidence in a PDF.', 'file_type' => 'pdf'], $parsed);
    }

    public static function unreadablePdfs(): array
    {
        return [
            'empty text' => [KnowledgeDocumentFixtures::pdf(''), 'pdf_no_text'],
            'invalid PDF structure' => ["%PDF-1.4\ninvalid objects\n%%EOF", 'pdf_invalid'],
        ];
    }

    #[DataProvider('unreadablePdfs')]
    public function test_pdf_without_readable_body_is_rejected(string $bytes, string $message): void
    {
        $file = UploadedFile::fake()->createWithContent('source.pdf', $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('admin.knowledge_bases.error.'.$message));
        app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), 'source.pdf');
    }

    public function test_pdf_stream_expansion_is_bounded_even_with_valid_text_before_the_limit(): void
    {
        $stream = 'BT /F1 12 Tf (Valid prefix must not hide oversized data) Tj ET'.str_repeat(' ', 20 * 1024 * 1024);
        $file = UploadedFile::fake()->createWithContent('bomb.pdf', KnowledgeDocumentFixtures::pdfStream($stream));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('admin.knowledge_bases.error.pdf_resource_limit'));
        app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), 'bomb.pdf');
    }

    public function test_pdf_multiple_streams_share_a_total_decoding_budget(): void
    {
        $stream = 'BT /F1 12 Tf (Each stream fits on its own) Tj ET'.str_repeat(' ', 12 * 1024 * 1024);
        $file = UploadedFile::fake()->createWithContent('many-streams.pdf', KnowledgeDocumentFixtures::pdfStreams([$stream, $stream, $stream]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('admin.knowledge_bases.error.pdf_resource_limit'));
        app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), 'many-streams.pdf');
    }

    public static function unsafePptxDocuments(): array
    {
        $entries = KnowledgeDocumentFixtures::pptxEntries([1 => 'Slide body']);
        $slide = 'ppt/slides/slide1.xml';
        $rels = 'ppt/_rels/presentation.xml.rels';
        $cases = [];
        $cases['internal entity'] = [$entries + []];
        $cases['internal entity'][0][$slide] = str_replace('<?xml version="1.0"?>', '<?xml version="1.0"?><!DOCTYPE p:sld [<!ENTITY text "entity text">]>', str_replace('Slide body', '&text;', $entries[$slide]));
        $cases['external entity'] = [$entries + []];
        $cases['external entity'][0][$slide] = str_replace('<?xml version="1.0"?>', '<?xml version="1.0"?><!DOCTYPE p:sld [<!ENTITY text SYSTEM "file:///__geoflow_private_file">]>', str_replace('Slide body', '&text;', $entries[$slide]));
        foreach ([
            'external relationship' => 'TargetMode="External" Target="https://example.com/slide.xml"',
            'archive root escape' => 'Target="../../secret.xml"',
            'encoded path escape' => 'Target="%2e%2e/secret.xml"',
            'non-slide part' => 'Target="presentation.xml"',
        ] as $name => $target) {
            $copy = $entries;
            $copy[$rels] = str_replace('Target="slides/slide1.xml"', $target, $entries[$rels]);
            $cases[$name] = [$copy];
        }
        $copy = $entries;
        $copy[$slide] = str_replace('</p:sld>', '', $entries[$slide]);
        $cases['malformed XML'] = [$copy];
        $copy = $entries;
        $copy['ppt/presentation.xml'] = str_replace('rId1', 'missing', $entries['ppt/presentation.xml']);
        $cases['missing relationship'] = [$copy];

        return $cases;
    }

    #[DataProvider('unsafePptxDocuments')]
    public function test_unsafe_pptx_content_and_relationships_are_rejected(array $entries): void
    {
        $file = UploadedFile::fake()->createWithContent('unsafe.pptx', KnowledgeDocumentFixtures::zip($entries));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('admin.knowledge_bases.error.pptx_invalid'));
        app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), 'unsafe.pptx');
    }

    public function test_pptx_high_compression_entries_are_rejected_before_xml_is_read(): void
    {
        $entries = KnowledgeDocumentFixtures::pptxEntries([1 => 'Valid body']);
        $entries['ppt/media/compressed.bin'] = str_repeat('A', 2 * 1024 * 1024);
        $file = UploadedFile::fake()->createWithContent('compressed.pptx', KnowledgeDocumentFixtures::zip($entries));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
        app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), 'compressed.pptx');
    }

    public function test_pptx_total_expansion_budget_includes_parts_that_are_not_slides(): void
    {
        $entries = KnowledgeDocumentFixtures::pptxEntries([1 => 'Valid body']);
        $payload = $this->moderatelyCompressedBytes(12 * 1024 * 1024);
        foreach (range(1, 3) as $index) {
            $entries['ppt/media/part'.$index.'.bin'] = $payload;
        }
        $file = UploadedFile::fake()->createWithContent('total.pptx', KnowledgeDocumentFixtures::zip($entries));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
        app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), 'total.pptx');
    }

    public function test_pptx_individual_entry_size_is_bounded_at_a_normal_compression_ratio(): void
    {
        $entries = KnowledgeDocumentFixtures::pptxEntries([1 => 'Valid body']);
        $entries['ppt/media/large.bin'] = $this->moderatelyCompressedBytes(17 * 1024 * 1024);
        $file = UploadedFile::fake()->createWithContent('large-entry.pptx', KnowledgeDocumentFixtures::zip($entries));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
        app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), 'large-entry.pptx');
    }

    public static function excessivePptxStructures(): array
    {
        $entries = KnowledgeDocumentFixtures::pptxEntries([1 => 'Valid slide body']);
        $presentation = 'ppt/presentation.xml';
        $rels = 'ppt/_rels/presentation.xml.rels';
        $cases = [];

        $copy = $entries;
        $slideIds = '';
        for ($index = 1; $index <= 2049; $index++) {
            $slideIds .= '<p:sldId id="'.(255 + $index).'" r:id="rId'.$index.'"/>';
        }
        $copy[$presentation] = preg_replace('~<p:sldIdLst>.*</p:sldIdLst>~s', '<p:sldIdLst>'.$slideIds.'</p:sldIdLst>', $entries[$presentation]);
        $cases['slide ID count'] = [$copy];

        $copy = $entries;
        $copy[$rels] = self::pptxRelationshipList(2049);
        $cases['relationships in one part'] = [$copy];

        $copy = $entries;
        for ($index = 1; $index <= 8; $index++) {
            $copy['ppt/metadata'.$index.'.xml'] = '<metadata/>';
            $copy['ppt/_rels/metadata'.$index.'.xml.rels'] = self::pptxRelationshipList(2048);
        }
        $cases['relationships across the package'] = [$copy];

        foreach (['empty' => '<a:t/>', 'short' => '<a:t>x</a:t>'] as $name => $node) {
            $copy = KnowledgeDocumentFixtures::pptxEntries([1 => 'First body', 2 => 'Second body']);
            foreach (['ppt/slides/slide1.xml', 'ppt/slides/slide2.xml'] as $slide) {
                $copy[$slide] = str_replace('</a:r>', str_repeat($node, 50000).'</a:r>', $copy[$slide]);
            }
            $cases[$name.' text nodes across slides'] = [$copy];
        }

        foreach ([
            'target byte length' => ['Target="slides/slide1.xml"', 'Target="slides/'.str_repeat('/', 1025).'slide1.xml"'],
            'target path segments' => ['Target="slides/slide1.xml"', 'Target="'.str_repeat('./', 65).'slides/slide1.xml"'],
            'relationship ID length' => ['Id="rId1"', 'Id="'.str_repeat('x', 256).'"'],
            'relationship type length' => ['Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide"', 'Type="'.str_repeat('x', 1025).'"'],
            'relationship mode length' => ['Target="slides/slide1.xml"', 'TargetMode="'.str_repeat('x', 1025).'" Target="slides/slide1.xml"'],
        ] as $name => [$search, $replacement]) {
            $copy = $entries;
            $copy[$rels] = str_replace($search, $replacement, $entries[$rels]);
            $cases[$name] = [$copy];
        }
        $copy = $entries;
        $copy[$presentation] = str_replace('r:id="rId1"', 'r:id="'.str_repeat('x', 256).'"', $entries[$presentation]);
        $cases['slide relationship ID length'] = [$copy];

        return $cases;
    }

    #[DataProvider('excessivePptxStructures')]
    public function test_pptx_structure_budget_rejects_excessive_counts_and_attributes_within_archive_byte_limits(array $entries): void
    {
        $bytes = KnowledgeDocumentFixtures::zip($entries, \ZipArchive::CM_STORE);
        $this->assertLessThan(8 * 1024 * 1024, strlen($bytes));
        $this->assertLessThan(2048, count($entries));
        $file = UploadedFile::fake()->createWithContent('structure-budget.pptx', $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
        app(KnowledgeSourceParser::class)->parseUploadedKnowledgeFile($file->getPathname(), 'structure-budget.pptx');
    }

    private static function pptxRelationshipList(int $count): string
    {
        $xml = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($index = 1; $index <= $count; $index++) {
            $xml .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>';
        }

        return $xml.'</Relationships>';
    }

    private function moderatelyCompressedBytes(int $bytes): string
    {
        $payload = '';
        for ($index = 0; strlen($payload) < $bytes; $index++) {
            $payload .= str_repeat(hash('sha256', (string) $index, true), 10);
        }

        return substr($payload, 0, $bytes);
    }
}
