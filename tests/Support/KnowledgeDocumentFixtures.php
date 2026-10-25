<?php

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

final class KnowledgeDocumentFixtures
{
    public static function docx(string $text): string
    {
        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'
                .htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                .'</w:t></w:r></w:p></w:body></w:document>',
        ]);
    }

    public static function pdf(string $text = 'Knowledge PDF body', bool $compressed = false): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = 'BT /F1 12 Tf 72 720 Td ('.$escaped.') Tj ET';

        return self::pdfStream($stream, $compressed);
    }

    public static function pdfStream(string $stream, bool $compressed = true): string
    {
        return self::pdfStreams([$stream], $compressed);
    }

    /** @param list<string> $streams */
    public static function pdfStreams(array $streams, bool $compressed = true): string
    {
        $references = implode(' ', array_map(fn (int $index): string => ($index + 5).' 0 R', array_keys($streams)));
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents ['.$references.'] >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        foreach ($streams as $stream) {
            $data = $compressed ? gzcompress($stream) : $stream;
            $objects[] = '<< /Length '.strlen($data).($compressed ? ' /Filter /FlateDecode' : '').">>\nstream\n".$data."\nendstream";
        }
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 ".$size."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".$size." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** @param array<int, string> $slides
     * @param  list<int>|null  $order
     * @return array<string, string>
     */
    public static function pptxEntries(array $slides, ?array $order = null): array
    {
        $order ??= array_keys($slides);
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
                .implode('', array_map(fn (int $id): string => '<Override PartName="/ppt/slides/slide'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>', array_keys($slides)))
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/></Relationships>',
            'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst>'
                .implode('', array_map(fn (int $id): string => '<p:sldId id="'.(255 + $id).'" r:id="rId'.$id.'"/>', $order))
                .'</p:sldIdLst></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .implode('', array_map(fn (int $id): string => '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide'.$id.'.xml"/>', array_keys($slides)))
                .'</Relationships>',
        ];
        foreach ($slides as $id => $text) {
            $entries['ppt/slides/slide'.$id.'.xml'] = '<?xml version="1.0"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>'
                .htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                .'</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
        }

        return $entries;
    }

    /** @param array<int, string> $slides
     * @param  list<int>|null  $order
     */
    public static function pptx(array $slides, ?array $order = null): string
    {
        return self::zip(self::pptxEntries($slides, $order));
    }

    /** @param array<string, string> $entries */
    public static function zip(array $entries, int $compression = ZipArchive::CM_DEFLATE): string
    {
        $path = tempnam(sys_get_temp_dir(), 'geoflow-fixture-');
        if (! is_string($path)) {
            throw new RuntimeException('Unable to create fixture.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to open fixture.');
            }
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
                $zip->setCompressionName($name, $compression);
            }
            $zip->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
